<?php
// toggle_user_paid.php
header('Content-Type: application/json');
$db = new PDO('sqlite:orders.db'); // 替換為你的連線

$data = json_decode(file_get_contents('php://input'), true);
$userName = trim($data['user_name'] ?? '');

if ($userName !== '') {
    // 檢查目前該同學是否有未付的
    $check = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_name = ? AND is_paid = 0");
    $check->execute([$userName]);
    $unpaidRows = (int)$check->fetchColumn();

    // 如果還有未付的，就全部設為已付(1)；如果全部都已付，就反轉為未付(0)
    $newStatus = ($unpaidRows > 0) ? 1 : 0;

    $stmt = $db->prepare("UPDATE orders SET is_paid = ?, updated_at = CURRENT_TIMESTAMP WHERE user_name = ?");
    $stmt->execute([$newStatus, $userName]);

    echo json_encode(['success' => true, 'is_paid' => $newStatus]);
} else {
    echo json_encode(['success' => false, 'error' => 'No username']);
}