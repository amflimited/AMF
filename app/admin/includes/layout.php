<?php
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function header_page($title) {
    echo '<!doctype html><html><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<link rel="stylesheet" href="' . h(admin_url('assets/style.css')) . '">';
    echo '<title>'.h($title).'</title></head><body>';
    echo '<header class="top"><div><b>'.h($title).'</b><small>SPEC-1 Pipeline</small></div>';
    if (function_exists('user') && user()) echo '<a class="mini" href="' . h(admin_url('logout.php')) . '">Logout</a>';
    echo '</header><main>';
}
function footer_page() {
    echo '</main><nav class="bottom">';
    echo '<a href="' . h(admin_url('index.php')) . '">Home</a>';
    echo '<a href="' . h(admin_url('pages/source-acquisition.php')) . '">Acquire</a>';
    echo '<a href="' . h(admin_url('pages/sample-runs.php')) . '">Samples</a>';
    echo '<a href="' . h(admin_url('pages/sources.php')) . '">Sources</a>';
    echo '<a href="' . h(admin_url('pages/runs.php')) . '">Runs</a>';
    echo '<a href="' . h(admin_url('pages/storage.php')) . '">Storage</a>';
    echo '</nav></body></html>';
}
?>
