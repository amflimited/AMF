<?php
$envPath = __DIR__ . '/.env';
$webhookUrl = 'https://revenuepack.com/deploy_webhook.php';
function h($v){return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');}
function lines($path){return file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];}
function getv($path,$key){foreach(lines($path) as $line){if(strpos($line,'=')===false)continue;[$k,$v]=explode('=',$line,2);if(trim($k)===$key)return trim($v);}return null;}
function setv($path,$key,$value){$out=[];$found=false;foreach(lines($path) as $line){if(strpos($line,'=')!==false){[$k,$v]=explode('=',$line,2);if(trim($k)===$key){$out[]=$key.'='.$value;$found=true;continue;}}$out[]=$line;}if(!$found)$out[]=$key.'='.$value;$ok=file_put_contents($path,implode("\n",$out)."\n",LOCK_EX);@chmod($path,0600);return $ok!==false;}
if($_SERVER['REQUEST_METHOD']==='POST'){$secret=bin2hex(random_bytes(32));setv($envPath,'DEPLOY_WEBHOOK_SECRET',$secret);}
$secret=getv($envPath,'DEPLOY_WEBHOOK_SECRET');
echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif;max-width:720px;margin:32px auto;padding:16px;background:#f6f7f9"><section style="background:white;border:1px solid #ddd;border-radius:18px;padding:16px"><h1>Webhook Setup</h1><p>This makes GitHub updates deploy RevenuePack immediately.</p>';
if(!$secret){echo '<form method="post"><button style="width:100%;height:52px;border:0;border-radius:14px;background:#1f6feb;color:white;font-weight:700;font-size:16px">Generate Deploy Secret</button></form>';}else{echo '<h2>GitHub Webhook Values</h2><p>GitHub → amflimited/AMF → Settings → Webhooks → Add webhook</p><p><b>Payload URL</b></p><pre>'.h($webhookUrl).'</pre><p><b>Content type</b></p><pre>application/json</pre><p><b>Secret</b></p><pre>'.h($secret).'</pre><p><b>Events</b></p><pre>Just the push event</pre><p><b>Active</b></p><pre>checked</pre><p>After saving it in GitHub, ask ChatGPT to push a tiny test commit, then check deploy status.</p><pre>https://revenuepack.com/app/admin/pages/deploy-status.php</pre>';}
echo '</section></body>';
?>
