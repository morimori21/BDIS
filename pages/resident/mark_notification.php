<?php
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');
if (!isLoggedIn() || !hasResidentAccess()) {
    echo json_encode(['success' => false]);
    exit;
}
$uid = $_SESSION['user_id'];
$notif_id = isset($_POST['notif_id']) ? (int)$_POST['notif_id'] : null;
if ($notif_id) {
    $stmt = $pdo->prepare("UPDATE notifications SET notif_is_read = 1 WHERE notif_id = ? AND user_id = ?");
    $stmt->execute([$notif_id, $uid]);
    echo json_encode(['success' => true]);
    exit;
}
// mark all
$stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
$stmt->execute([$uid]);
$stmt = null;
echo json_encode(['success' => true]);
