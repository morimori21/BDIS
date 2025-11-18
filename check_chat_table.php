<?php
require_once 'includes/config.php';

echo "chat_messages table structure:\n";
echo str_repeat("-", 60) . "\n";

$stmt = $pdo->query("SHOW COLUMNS FROM chat_messages");
while ($row = $stmt->fetch()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}

echo "\n";
?>
