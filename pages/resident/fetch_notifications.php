<?php
require_once __DIR__ . '/../../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn() || !hasResidentAccess()) {
    echo json_encode(['success' => false]);
    exit;
}
$uid = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT notif_id, notif_type, notif_topic, notif_entity_id, notif_created_at, notif_is_read FROM notifications WHERE user_id = ? ORDER BY notif_created_at DESC LIMIT 20");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();
$unread = 0; foreach ($rows as $r) if (!$r['notif_is_read']) $unread++;
echo json_encode(['success' => true, 'notifications' => $rows, 'unread' => $unread]);

