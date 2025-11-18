<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$ticket_id = isset($data['ticket_id']) ? intval($data['ticket_id']) : 0;

if ($ticket_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid ticket ID']);
    exit;
}

try {
    // Mark all messages in this ticket as read where the current user is NOT the sender
    // and the message is not yet read
    $stmt = $pdo->prepare("
        UPDATE chat_messages 
        SET is_read = 1, read_at = CURRENT_TIMESTAMP 
        WHERE ticket_id = ? 
        AND sender_id != ? 
        AND is_read = 0
    ");
    $stmt->execute([$ticket_id, $_SESSION['user_id']]);
    
    $rowsAffected = $stmt->rowCount();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Messages marked as read',
        'updated_count' => $rowsAffected
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
