<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json');

try {
    $configPath = __DIR__ . '/../../includes/config.php';
    
    if (!file_exists($configPath)) {
        throw new Exception('Database configuration file not found');
    }
    
    include $configPath;
    
    if (!isset($pdo)) {
        throw new Exception('Database connection not established');
    }

    // Get users with their roles (excluding residents for admin view)
    $query = "
        SELECT 
            u.user_id,
            CONCAT(u.first_name, ' ', u.surname) as name,
            ur.role
        FROM users u
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        WHERE ur.role != 'RESIDENT' OR ur.role IS NULL
        ORDER BY u.first_name, u.surname
    ";

    $stmt = $pdo->query($query);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($users);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>