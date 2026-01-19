<?php
require_once '../../includes/config.php';
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $notif_id = $input['notif_id'] ?? null;
    
    if ($notif_id) {
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET notif_is_read = 1 WHERE notif_id = ?");
            $stmt->execute([$notif_id]);
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'No notification ID provided']);
    }
}
?>