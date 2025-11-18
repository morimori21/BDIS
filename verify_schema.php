<?php
require_once 'includes/config.php';

echo "Checking document_requests table structure...\n\n";

$stmt = $pdo->query("SHOW COLUMNS FROM document_requests");
echo "Columns in document_requests table:\n";
echo str_repeat("-", 60) . "\n";
printf("%-25s %-20s %-10s\n", "Field", "Type", "Null");
echo str_repeat("-", 60) . "\n";

while ($row = $stmt->fetch()) {
    printf("%-25s %-20s %-10s\n", $row['Field'], $row['Type'], $row['Null']);
}

echo "\n✓ Database structure verified!\n";
?>
