<?php
require_once __DIR__ . '/config.php';
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? '') . ';charset=' . ($_ENV['DB_CHARSET'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    return $pdo;
}
function one($sql, $params=[]) { $s=db()->prepare($sql); $s->execute($params); return $s->fetch(); }
function rows($sql, $params=[]) { $s=db()->prepare($sql); $s->execute($params); return $s->fetchAll(); }
function exec_sql($sql, $params=[]) { $s=db()->prepare($sql); $s->execute($params); return db()->lastInsertId(); }
?>
