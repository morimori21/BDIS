<?php
require_once '../../includes/config.php';

// Residents are not allowed to download documents
header('Location: dashboard.php?error=Download not permitted for residents');
exit;

// Get document request details
$request_id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT dr.*, dt.doc_name as doc_name FROM document_requests dr JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id WHERE dr.request_id = ? AND dr.resident_id = ?");
$stmt->execute([$request_id, $_SESSION['user_id']]);
$request = $stmt->fetch();

if (!$request || $request['request_status'] !== 'completed') {
    header('Location: request_history.php');
    exit;
}

// Check if document file exists
$filename = $request['generated_document'];
if (!$filename) {
    header('Location: request_history.php');
    exit;
}

$file_path = '../../uploads/generated_documents/' . $filename;
if (!file_exists($file_path)) {
    echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'File Not Found',
            text: 'Document file not found.'
        }).then(() => {
            window.history.back();
        });
    </script>";
    exit;
}

// Log download activity
logActivity($_SESSION['user_id'], "Downloaded document: {$request['doc_name']} (Request #{$request_id})");

// Force download
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $request['doc_name'] . '_' . $request_id . '.pdf"');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
?>