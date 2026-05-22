<?php
/**
 * Moneta GitHub Release Puller
 * Server-side release mailbox pull from raw.githubusercontent.com.
 * No Google Drive. No ZIP upload. No hardcoded secrets.
 */
declare(strict_types=1);

const MONETA_LATEST_URL = 'https://raw.githubusercontent.com/amflimited/AMF/main/moneta/releases/latest.json';
const MONETA_ALLOWED_HOSTS = ['raw.githubusercontent.com'];
const MONETA_MAX_BYTES = 5242880;
const MONETA_PACKAGE_FORMAT = 'moneta.release.v1';

function jexit(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function docroot(): string {
    $root = realpath(__DIR__ . '/../../..');
    if ($root === false) { throw new RuntimeException('Could not resolve document root.'); }
    return $root;
}

function state_dir(): string {
    $dir = __DIR__ . '/_release_state';
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) { throw new RuntimeException('Could not create _release_state.'); }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\nDeny from all\n"); }
    return $dir;
}

function local_secret(): ?string {
    $env = getenv('MONETA_RELEASE_SECRET');
    if (is_string($env) && trim($env) !== '') { return trim($env); }
    foreach ([__DIR__.'/release.secret', __DIR__.'/_release_state/release.secret', __DIR__.'/../../../moneta_private/config/release-secret.txt', __DIR__.'/../../../../moneta_private/config/release-secret.txt'] as $p) {
        if (is_file($p) && is_readable($p)) {
            $v = trim((string)file_get_contents($p));
            if ($v !== '') { return $v; }
        }
    }
    return null;
}

function require_secret(): void {
    $expected = local_secret();
    if ($expected === null) { throw new RuntimeException('Install secret not configured. Preview/test mode only.'); }
    $provided = (string)($_POST['secret'] ?? $_GET['secret'] ?? '');
    if ($provided === '' || !hash_equals($expected, $provided)) { throw new RuntimeException('Install secret missing or invalid.'); }
}

function host_allowed(string $url): bool {
    $p = parse_url($url);
    return strtolower((string)($p['scheme'] ?? '')) === 'https' && in_array(strtolower((string)($p['host'] ?? '')), MONETA_ALLOWED_HOSTS, true);
}

function fetch_text(string $url): string {
    if (!host_allowed($url)) { throw new RuntimeException('Blocked URL host: '.$url); }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30, CURLOPT_USERAGENT=>'MonetaReleasePuller/1.0']);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) { throw new RuntimeException('Fetch failed HTTP '.$status.($err ? ' / '.$err : '')); }
    } else {
        $body = @file_get_contents($url, false, stream_context_create(['http'=>['timeout'=>30, 'header'=>"User-Agent: MonetaReleasePuller/1.0\r\n"]]));
        if ($body === false) { throw new RuntimeException('Fetch failed with file_get_contents.'); }
    }
    if (!is_string($body) || $body === '') { throw new RuntimeException('Fetch returned empty body.'); }
    if (strlen($body) > MONETA_MAX_BYTES) { throw new RuntimeException('Payload too large.'); }
    return $body;
}

function decode_json(string $raw, string $label): array {
    $d = json_decode($raw, true);
    if (!is_array($d)) { throw new RuntimeException($label.' is not valid JSON.'); }
    return $d;
}

function safe_path(string $path): string {
    $path = ltrim(trim(str_replace('\\', '/', $path)), '/');
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) { throw new RuntimeException('Unsafe path: '.$path); }
    if (!str_starts_with($path, 'moneta/v2/') && !str_starts_with($path, 'moneta/releases/')) { throw new RuntimeException('Path outside allowed boundary: '.$path); }
    foreach (['config/','private/','secret','.env','db-config','openai-config','admin-password'] as $bad) {
        if (str_contains(strtolower($path), $bad)) { throw new RuntimeException('Blocked config/secret path: '.$path); }
    }
    return $path;
}

function pull_latest(): array {
    $latest = decode_json(fetch_text(MONETA_LATEST_URL), 'latest.json');
    $url = (string)($latest['package_url'] ?? '');
    $expected = strtolower((string)($latest['sha256'] ?? ''));
    if ($url === '' || $expected === '') { throw new RuntimeException('latest.json missing package_url or sha256.'); }
    $raw = fetch_text($url);
    $actual = hash('sha256', $raw);
    if (!hash_equals($expected, $actual)) { throw new RuntimeException('sha256 mismatch. expected='.$expected.' actual='.$actual); }
    return ['latest'=>$latest, 'package_raw'=>$raw, 'sha256'=>$actual];
}

function install_package(array $pkg): array {
    if (($pkg['format'] ?? '') !== MONETA_PACKAGE_FORMAT) { throw new RuntimeException('Unsupported package format.'); }
    $files = $pkg['files'] ?? [];
    if (!is_array($files) || count($files) < 1) { throw new RuntimeException('No files in package.'); }
    if (count($files) > 100) { throw new RuntimeException('Too many files in package.'); }
    $root = docroot();
    $rid = gmdate('Ymd_His').'_'.preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string)($pkg['version'] ?? 'release'));
    $changed = [];
    $backed = [];
    foreach ($files as $f) {
        if (!is_array($f)) { throw new RuntimeException('Invalid file entry.'); }
        $rel = safe_path((string)($f['path'] ?? ''));
        $content = array_key_exists('content_base64', $f) ? base64_decode((string)$f['content_base64'], true) : (string)($f['content'] ?? '');
        if ($content === false) { throw new RuntimeException('Invalid base64 for '.$rel); }
        $fsha = strtolower((string)($f['sha256'] ?? ''));
        if ($fsha !== '' && !hash_equals($fsha, hash('sha256', $content))) { throw new RuntimeException('File sha256 mismatch: '.$rel); }
        $target = $root.'/'.$rel;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) { throw new RuntimeException('Cannot create dir for '.$rel); }
        if (is_file($target)) {
            $backup = state_dir().'/backups/'.$rid.'/'.$rel;
            if (!is_dir(dirname($backup)) && !mkdir(dirname($backup), 0750, true)) { throw new RuntimeException('Cannot create backup dir.'); }
            if (!copy($target, $backup)) { throw new RuntimeException('Cannot backup '.$rel); }
            $backed[] = $rel;
        }
        $tmp = $target.'.tmp_'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) { throw new RuntimeException('Cannot write temp '.$rel); }
        @chmod($tmp, 0644);
        if (!rename($tmp, $target)) { @unlink($tmp); throw new RuntimeException('Cannot move temp into place '.$rel); }
        $changed[] = $rel;
    }
    $log = ['release_id'=>$rid, 'version'=>(string)($pkg['version'] ?? ''), 'installed_at_utc'=>gmdate('c'), 'changed_files'=>$changed, 'backed_up_files'=>$backed, 'boundary'=>(string)($pkg['boundary'] ?? '')];
    file_put_contents(state_dir().'/last_install.json', json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents(state_dir().'/install_log.ndjson', json_encode($log, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    return $log;
}

try {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? 'preview');
    if ($action === 'health') { jexit(['ok'=>true, 'tool'=>'release_github_pull', 'secret_configured'=>local_secret() !== null, 'latest_url'=>MONETA_LATEST_URL]); }
    if ($action !== 'preview' && $action !== 'install') { throw new RuntimeException('Unknown action.'); }
    $pull = pull_latest();
    $latest = $pull['latest'];
    $out = ['ok'=>true, 'mode'=>'preview', 'version'=>(string)($latest['version'] ?? ''), 'package_url'=>(string)($latest['package_url'] ?? ''), 'sha256_verified'=>$pull['sha256'], 'install_intent'=>(string)($latest['install_intent'] ?? ''), 'expected_changed_files'=>$latest['expected_changed_files'] ?? [], 'boundary'=>(string)($latest['boundary'] ?? '')];
    if ($action === 'install') {
        if (($latest['install_intent'] ?? '') === 'none') {
            $out['mode'] = 'connectivity_test';
            $out['install_status'] = 'No write performed because install_intent=none.';
        } else {
            require_secret();
            $out['mode'] = 'installed';
            $out['install_log'] = install_package(decode_json($pull['package_raw'], 'release package'));
        }
    }
    if (isset($_GET['json'])) { jexit($out); }
} catch (Throwable $e) {
    if (isset($_GET['json'])) { jexit(['ok'=>false, 'error'=>$e->getMessage()], 500); }
    $error = $e->getMessage();
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Moneta GitHub Release Pull</title><style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#0f1115;color:#f4f4f5;line-height:1.45}main{max-width:900px;margin:0 auto;padding:24px 16px 60px}.card{background:#181b22;border:1px solid #2a2f3a;border-radius:14px;padding:16px;margin:14px 0}button,input{width:100%;box-sizing:border-box;border-radius:12px;padding:14px 16px;font-size:16px;margin-top:10px}button{border:0;font-weight:700;background:#f4f4f5;color:#111827}input{border:1px solid #3f4654;background:#0f1115;color:#f4f4f5}pre{white-space:pre-wrap;word-break:break-word;background:#0f1115;border:1px solid #2a2f3a;border-radius:12px;padding:12px}.muted{color:#a1a1aa}.ok{color:#86efac}.bad{color:#fca5a5}.warn{color:#fde68a}</style></head><body><main><h1>Moneta GitHub Release Pull</h1><div class="muted">Server-side pull from GitHub raw artifact mailbox. No Drive. No ZIP upload.</div><?php if (isset($error)): ?><section class="card"><strong class="bad">Blocked / Failed</strong><pre><?=htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></pre></section><?php endif; ?><?php if (isset($out)): ?><section class="card"><strong class="ok">Latest release verified</strong><pre><?=htmlspecialchars(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></pre></section><?php endif; ?><section class="card"><strong>Pull latest from GitHub</strong><p class="muted">Preview verifies latest.json, downloads the package, and checks sha256. Install writes only verified moneta.release.v1 packages and backs up overwritten files.</p><form method="post"><input type="hidden" name="action" value="preview"><button type="submit">Preview / Verify Latest</button></form><form method="post"><input type="hidden" name="action" value="install"><?php if (local_secret() !== null): ?><input type="password" name="secret" placeholder="Install secret"><?php else: ?><p class="warn">Install secret not configured. Connectivity tests can run; file writes are blocked.</p><?php endif; ?><button type="submit">Pull Latest + Install If Allowed</button></form></section><section class="card"><strong>Boundaries</strong><pre>Allowed host: raw.githubusercontent.com
Allowed write paths: moneta/v2/* and moneta/releases/*
Blocked paths: config, private, secret, .env, db-config, openai-config, admin-password
Backup spot: moneta/v2/ops/_release_state/backups/&lt;release_id&gt;/</pre></section></main></body></html>
