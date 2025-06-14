<?php
// config.php

$host = 'localhost'; // 資料庫主機名
$db = 'dorm_system'; // 資料庫名稱
$user = 'root'; // 資料庫使用者名稱 (XAMPP 預設為 root)
$pass = ''; // 資料庫密碼 (XAMPP 預設為空)
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>