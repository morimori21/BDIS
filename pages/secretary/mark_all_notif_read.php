<?php
require_once '../../includes/config.php';
session_start();

// Clear any previous output
if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $notif_ids = $input['notif_ids'] ?? [];
    $user_id = $_SESSION['user_id'];

    if (!empty($notif_ids)) {
        // Mark specific notifications as read
        $placeholders = str_repeat('?,', count($notif_ids) - 1) . '?';
        $sql = "UPDATE notifications SET notif_is_read = 1 WHERE notif_id IN ($placeholders) AND user_id = ?";
        $stmt = $pdo->prepare($sql);
        
        $params = array_merge($notif_ids, [$user_id]);
        $stmt->execute($params);
        
        $updated = $stmt->rowCount();
        echo json_encode(['success' => true, 'updated' => $updated]);
    } else {
        // Mark all notifications for user as read
        $stmt = $pdo->prepare("UPDATE notifications SET notif_is_read = 1 WHERE user_id = ? AND notif_is_read = 0");
        $stmt->execute([$user_id]);
        
        $updated = $stmt->rowCount();
        echo json_encode(['success' => true, 'updated' => $updated]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in mark_all_notif_read.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
} catch (Exception $e) {
    http_response_code(500);
    error_log("Error in mark_all_notif_read.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

// Ensure no extra output
exit;
?>