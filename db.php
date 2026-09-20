<?php
// db.php
date_default_timezone_set('Asia/Taipei');

$db_host = 'localhost';
$db_user = '594mesa';
$db_pass = 'ns2dsNsUoBc4@sDJ';
$db_name = 'bento_system';

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die(json_encode(['status' => 'error', 'message' => '資料庫連線失敗: ' . $e->getMessage()]));
}