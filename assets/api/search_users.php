<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

    $query = $_GET['q'] ?? '';
    $role = $_GET['role'] ?? 'all';

    if (strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    $searchQuery = "
        SELECT 
            u.user_id,
            CONCAT(u.first_name, ' ', u.surname) as name,
            CONCAT(u.street, ', ', ac.brgy_name, ', ', ac.municipality) as address,
            ur.role
        FROM users u
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        LEFT JOIN address_config ac ON u.address_id = ac.address_id
        WHERE (u.first_name LIKE :query OR u.surname LIKE :query OR CONCAT(u.first_name, ' ', u.surname) LIKE :query)
    ";

    if ($role !== 'all') {
        $searchQuery .= " AND ur.role = :role";
    }

    $searchQuery .= " ORDER BY u.first_name, u.surname LIMIT 10";

    $stmt = $pdo->prepare($searchQuery);
    $params = [':query' => '%' . $query . '%'];
    
    if ($role !== 'all') {
        $params[':role'] = $role;
    }
    
    $stmt->execute($params);
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