<?php
session_start();
require_once '../../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if (!isset($_POST['id']) || empty($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'No request ID provided']);
    exit;
}

$request_id = (int)$_POST['id'];
$user_id = $_SESSION['user_id'];
// Optional cancellation reason from client (SweetAlert)
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

try {
    $pdo->beginTransaction();
    
    // Verify request belongs to user and is pending or in-progress
    $check_stmt = $pdo->prepare("SELECT dr.request_id, dr.request_remarks FROM document_requests dr WHERE dr.request_id = ? AND dr.resident_id = ? AND LOWER(dr.request_status) IN ('pending','in-progress')");
    $check_stmt->execute([$request_id, $user_id]);
    $request = $check_stmt->fetch();
    
    if (!$request) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Request not found or cannot be cancelled']);
        exit;
    }
    
        // Build updated remarks including cancellation reason (append, preserving existing)
        $existingRemarks = isset($request['request_remarks']) ? trim((string)$request['request_remarks']) : '';
        $reasonText = $reason !== '' ? ('Cancellation Reason: ' . $reason) : 'Cancelled by user';
        $newRemarks = $existingRemarks ? ($existingRemarks . "\n" . $reasonText) : $reasonText;

        // UPDATE the request status to 'cancelled' and store remarks
        $update_stmt = $pdo->prepare("
            UPDATE document_requests 
            SET request_status = 'cancelled', request_remarks = :remarks 
            WHERE request_id = :id
        ");
        $update_stmt->execute([':remarks' => $newRemarks, ':id' => $request_id]);
    
    // Schedule feature removed: no slot restoration needed
        logActivity($user_id, 'Cancelled document request #' . $request_id . ($reason ? (' - Reason: ' . $reason) : ''));
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Request cancelled successfully']);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Cancel request error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>