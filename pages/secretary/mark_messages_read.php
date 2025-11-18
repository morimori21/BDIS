<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('secretary');

if (!isset($_POST['ticket_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing ticket ID']);
    exit;
}

$ticket_id = intval($_POST['ticket_id']);
$current_user = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    UPDATE chat_messages 
    SET message_is_read = 1 
    WHERE ticket_id = ? 
      AND user_id != ?
");
$stmt->execute([$ticket_id, $current_user]);

echo json_encode(['success' => true]);
