<?php
require_once 'includes/config.php';

echo "<h3>Document Types in Database:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Price</th><th>Status</th></tr>";

try {
    $stmt = $pdo->query("SELECT doc_type_id, doc_name, doc_price, doc_status FROM document_types ORDER BY doc_name");
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count++;
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['doc_type_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['doc_name']) . "</td>";
        echo "<td>₱" . htmlspecialchars($row['doc_price']) . "</td>";
        echo "<td>" . htmlspecialchars($row['doc_status']) . "</td>";
        echo "</tr>";
    }
    
    if ($count == 0) {
        echo "<tr><td colspan='4'>No document types found!</td></tr>";
    }
    
    echo "</table>";
    echo "<p>Total: $count document types</p>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
