<?php
require_once '../../includes/config.php';

header('Content-Type: application/json');

try {
    $query = $_GET['q'] ?? '';
    
    if (strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    $searchQuery = "%$query%";
    $stmt = $pdo->prepare("
        SELECT 
            street,
            COUNT(*) as user_count,
            street as street_name
        FROM users 
        WHERE street LIKE ? 
        AND street IS NOT NULL 
        AND street != ''
        GROUP BY street
        ORDER BY user_count DESC, street ASC
        LIMIT 10
    ");
    
    $stmt->execute([$searchQuery]);
    $streets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($streets);
    
} catch (PDOException $e) {
    echo json_encode([]);
}
?>