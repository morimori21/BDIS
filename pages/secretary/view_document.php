<?php
// Clean any previous output and start output buffering
ob_start();

// Start session only if one doesn't exist
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../includes/config.php';
require_once '../../includes/document_generator.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(403);
    die("Unauthorized access");
}

// Get request ID
$request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;

if (!$request_id) {
    ob_end_clean();
    die("Invalid request ID");
}

// Verify user has permission to view this document
$role = getUserRole();
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT dr.*, dt.doc_name as doc_type_name
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    WHERE dr.request_id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch();

if (!$request) {
    die("Document request not found");
}

// Check permissions
if ($role == 'resident' || $role == 'councilor' || $role == 'sk_chairman' || $role == 'treasurer') {
    // Residents can only view their own documents
    if ($request['resident_id'] != $user_id) {
        ob_end_clean();
        die("Access denied");
    }
} elseif (!in_array($role, ['admin', 'secretary', 'captain'])) {
    ob_end_clean();
    die("Access denied");
}

// Clean the output buffer before generating document
ob_end_clean();

// Generate HTML document
try {
    $docGenerator = new DocumentGenerator($pdo);
    $html = $docGenerator->generateDocument($request_id);
    
    // Output the HTML directly
    echo $html;
    
} catch (Exception $e) {
    echo "<div style='padding: 20px; text-align: center;'>";
    echo "<h2>Error Generating Document</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<a href='javascript:history.back()' class='btn btn-secondary'>Go Back</a>";
    echo "</div>";
}
?>
