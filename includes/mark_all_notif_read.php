<?php
require_once '../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $notifIds = $input['notif_ids'] ?? [];
    $userId = $input['user_id'] ?? ($_SESSION['user_id'] ?? null);
    
    if (!empty($notifIds) && $userId) {
        try {
            $placeholders = str_repeat('?,', count($notifIds) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE notifications SET notif_is_read = 1 WHERE notif_id IN ($placeholders) AND user_id = ?");
            $params = array_merge($notifIds, [$userId]);
            
            $success = $stmt->execute($params);
            
            echo json_encode(['success' => $success, 'updated' => $stmt->rowCount()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'No notification IDs provided or user not logged in']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
?>