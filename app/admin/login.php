<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/layout.php';
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (login($_POST['email'] ?? '', $_POST['password'] ?? '')) { header('Location: ' . admin_url('index.php')); exit; }
    $error='Invalid login.';
}
header_page('Login');
echo '<section class="card">';
if ($error) echo '<p>'.h($error).'</p>';
echo '<form method="post"><p><input type="email" name="email" placeholder="Email"></p><p><input type="password" name="password" placeholder="Password"></p><p><button>Log in</button></p></form></section>';
footer_page();
?>
