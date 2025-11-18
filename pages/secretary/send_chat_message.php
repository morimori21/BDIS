<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('secretary');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if (!$ticket_id || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Verify ticket exists
$checkStmt = $pdo->prepare("SELECT ticket_id, ticket_status FROM support_tickets WHERE ticket_id = ?");
$checkStmt->execute([$ticket_id]);
$ticket = $checkStmt->fetch();

if (!$ticket) {
    echo json_encode(['success' => false, 'error' => 'Ticket not found']);
    exit;
}

if ($ticket['ticket_status'] === 'closed') {
    echo json_encode(['success' => false, 'error' => 'This ticket is closed']);
    exit;
}

try {
    // AUTO-UPDATE: If ticket status is 'open', change to 'in-progress'
    if ($ticket['ticket_status'] === 'open') {
        $updateStmt = $pdo->prepare("UPDATE support_tickets SET ticket_status = 'in-progress' WHERE ticket_id = ?");
        $updateStmt->execute([$ticket_id]);
    }

    // Insert message
    $stmt = $pdo->prepare("
        INSERT INTO chat_messages (ticket_id, user_id, message, message_sent_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$ticket_id, $_SESSION['user_id'], $message]);
    
    // Log activity
    logActivity($_SESSION['user_id'], "Sent message to support ticket #$ticket_id");
    
    echo json_encode([
        'success' => true,
        'message_id' => $pdo->lastInsertId(),
        'status_updated' => ($ticket['ticket_status'] === 'open') // Return if status was updated
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>