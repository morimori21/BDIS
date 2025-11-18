<?php
require_once 'includes/config.php';

echo "Document Requests Table Structure:\n";
$stmt = $pdo->query('DESCRIBE document_requests');
while($row = $stmt->fetch()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}
?>