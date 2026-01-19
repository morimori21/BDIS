<?php
require_once '../../includes/config.php';

header('Content-Type: application/json');

try {
    // Get filter parameters
    $role = $_GET['role'] ?? 'all';
    $street = $_GET['street'] ?? 'all';

    // Build WHERE conditions for user data
    $whereConditions = ["u.status != 'deleted'"];
    $params = [];

    if ($role !== 'all') {
        $whereConditions[] = "COALESCE(ur.role, 'resident') = :role";
        $params[':role'] = $role;
    }

    if ($street !== 'all') {
        $whereConditions[] = "u.street = :street";
        $params[':street'] = $street;
    }

    $whereClause = count($whereConditions) > 0 ? "WHERE " . implode(' AND ', $whereConditions) : "";

    // Get user statistics
    $statsQuery = "
        SELECT 
            COUNT(*) as totalUsers,
            SUM(CASE WHEN u.status = 'verified' THEN 1 ELSE 0 END) as verifiedUsers,
            SUM(CASE WHEN u.status = 'pending' THEN 1 ELSE 0 END) as pendingUsers,
            SUM(CASE WHEN COALESCE(ur.role, 'resident') = 'admin' THEN 1 ELSE 0 END) as adminUsers,
            SUM(CASE WHEN COALESCE(ur.role, 'resident') = 'councilor' THEN 1 ELSE 0 END) as councilorUsers,
            SUM(CASE WHEN COALESCE(ur.role, 'resident') = 'secretary' THEN 1 ELSE 0 END) as secretaryUsers,
            SUM(CASE WHEN COALESCE(ur.role, 'resident') = 'captain' THEN 1 ELSE 0 END) as captainUsers,
            SUM(CASE WHEN COALESCE(ur.role, 'resident') = 'treasurer' THEN 1 ELSE 0 END) as treasurerUsers,
            SUM(CASE WHEN COALESCE(ur.role, 'resident') = 'sk_chairman' THEN 1 ELSE 0 END) as skChairmanUsers
        FROM users u
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        $whereClause
    ";

    $stmt = $pdo->prepare($statsQuery);
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get role distribution
    $roleQuery = "
        SELECT 
            COALESCE(ur.role, 'resident') as role,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM users u LEFT JOIN user_roles ur ON u.user_id = ur.user_id $whereClause)), 1) as percentage
        FROM users u
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        $whereClause
        GROUP BY COALESCE(ur.role, 'resident')
        ORDER BY count DESC
    ";

    $stmt = $pdo->prepare($roleQuery);
    $stmt->execute($params);
    $roleDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get street distribution
    $streetQuery = "
        SELECT 
            COALESCE(u.street, 'No Street Provided') as street,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM users u LEFT JOIN user_roles ur ON u.user_id = ur.user_id $whereClause)), 1) as percentage
        FROM users u
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        $whereClause
        AND u.street IS NOT NULL 
        AND u.street != ''
        GROUP BY u.street
        ORDER BY count DESC
        LIMIT 10
    ";

    $stmt = $pdo->prepare($streetQuery);
    $stmt->execute($params);
    $streetDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'roleDistribution' => $roleDistribution,
        'streetDistribution' => $streetDistribution
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>