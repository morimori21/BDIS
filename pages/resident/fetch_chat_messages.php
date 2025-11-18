<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotResident();

header('Content-Type: application/json');

$ticket_id = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

if (!$ticket_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid ticket ID']);
    exit;
}

// Verify ticket belongs to this resident
$checkStmt = $pdo->prepare("SELECT user_id FROM support_tickets WHERE ticket_id = ?");
$checkStmt->execute([$ticket_id]);
$ticket = $checkStmt->fetch();

if (!$ticket || $ticket['user_id'] != $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Fetch messages newer than last_id
$stmt = $pdo->prepare("
    SELECT 
        cm.message_id,
        cm.user_id,
        cm.message,
        cm.message_sent_at as sent_at,
        CONCAT(u.first_name, ' ', u.surname) AS sender_name,
        u.profile_picture,
        CASE
            WHEN TIMESTAMPDIFF(MINUTE, cm.message_sent_at, NOW()) < 1 THEN 'Just now'
            WHEN TIMESTAMPDIFF(MINUTE, cm.message_sent_at, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(MINUTE, cm.message_sent_at, NOW()), 'm ago')
            WHEN TIMESTAMPDIFF(HOUR, cm.message_sent_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, cm.message_sent_at, NOW()), 'h ago')
            ELSE DATE_FORMAT(cm.message_sent_at, '%b %d, %I:%i %p')
        END AS time_ago
    FROM chat_messages cm
    JOIN users u ON cm.user_id = u.user_id
    WHERE cm.ticket_id = ? AND cm.message_id > ?
    ORDER BY cm.message_id ASC
");
$stmt->execute([$ticket_id, $last_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process profile pictures
foreach ($messages as &$msg) {
    if ($msg['profile_picture']) {
        $msg['profile_picture'] = '/Project_A2/uploads/' . $msg['profile_picture'];
    } else {
        $msg['profile_picture'] = '/Project_A2/assets/images/default-avatar.png';
    }
}

echo json_encode([
    'success' => true,
    'messages' => $messages
]);
