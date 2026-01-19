<?php
require_once 'config.php';
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$notif_id = $input['notif_id'] ?? null;

if (!$notif_id) {
    echo json_encode(['success' => false, 'error' => 'Notification ID is required']);
    exit;
}

try {
    // Update the notification as read
    $stmt = $pdo->prepare("UPDATE notifications SET notif_is_read = 1 WHERE notif_id = ? AND user_id = ?");
    $success = $stmt->execute([$notif_id, $_SESSION['user_id']]);
    
    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update notification']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>