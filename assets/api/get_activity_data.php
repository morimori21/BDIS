<?php
// get_activity_data.php - Located in: Project_A2/assets/api/
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json');

try {
    $configPath = __DIR__ . '/../../includes/config.php';
    
    if (!file_exists($configPath)) {
        throw new Exception('Database configuration file not found at: ' . $configPath);
    }
    
    include $configPath;
    
    if (!isset($pdo)) {
        throw new Exception('Database connection not established');
    }

    // Get filter parameters
    $user_id = $_GET['user_id'] ?? 'all';
    $date = $_GET['date'] ?? date('Y-m-d');
    $action_type = $_GET['action_type'] ?? 'all';

    // Build WHERE conditions
    $whereConditions = ["DATE(al.action_time) = :date"];
    $params = [':date' => $date];

    if ($user_id !== 'all') {
        $whereConditions[] = "al.user_id = :user_id";
        $params[':user_id'] = $user_id;
    }

    if ($action_type !== 'all') {
        $whereConditions[] = "al.action LIKE :action_type";
        $params[':action_type'] = '%' . $action_type . '%';
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Get activity statistics
    $statsQuery = "
        SELECT 
    COUNT(*) as totalActivities,
    SUM(CASE WHEN al.action LIKE '%Logged in%' THEN 1 ELSE 0 END) as loginCount,
    SUM(CASE WHEN al.action LIKE '%Changed user role%' OR al.action LIKE '%role_changed%' THEN 1 ELSE 0 END) as roleChangeCount,
    SUM(CASE WHEN al.action LIKE '%Resident requested document%' THEN 1 ELSE 0 END) as requestCount,
    SUM(CASE WHEN al.action LIKE '%Sent message to support ticket%' THEN 1 ELSE 0 END) as ticketAppealCount,
    SUM(CASE WHEN al.action LIKE '%system%' OR al.action LIKE '%config%' OR al.action LIKE '%price%' OR al.action LIKE '%Updated price%' OR al.action LIKE '%Updated barangay details%' THEN 1 ELSE 0 END) as systemChangeCount
FROM activity_logs al
LEFT JOIN users u ON al.user_id = u.user_id
LEFT JOIN user_roles ur ON u.user_id = ur.user_id
WHERE $whereClause
AND (ur.role != 'RESIDENT' OR ur.role IS NULL OR al.action LIKE '%system%' OR al.action LIKE '%config%' OR al.action LIKE '%price%' OR al.action LIKE '%Updated price%' OR al.action LIKE '%Updated barangay details%')
    ";

    $stmt = $pdo->prepare($statsQuery);
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get activity logs with user details
    $logsQuery = "
        SELECT 
            al.log_id,
            al.action,
            al.action_details,
            al.action_time,
            CONCAT(u.first_name, ' ', u.surname) as user_name,
            ur.role as user_role
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        WHERE $whereClause
        AND (ur.role != 'RESIDENT' OR ur.role IS NULL OR al.action LIKE '%system%' OR al.action LIKE '%config%' OR al.action LIKE '%price%')
        ORDER BY al.action_time DESC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($logsQuery);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get action breakdown for the day (hourly)
    $timelineQuery = "
        SELECT 
            HOUR(al.action_time) as hour,
            COUNT(*) as count
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        WHERE $whereClause
        AND (ur.role != 'RESIDENT' OR ur.role IS NULL OR al.action LIKE '%system%' OR al.action LIKE '%config%' OR al.action LIKE '%price%')
        GROUP BY HOUR(al.action_time)
        ORDER BY hour
    ";

    $stmt = $pdo->prepare($timelineQuery);
    $stmt->execute($params);
    $timelineData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format timeline data
    $timelineLabels = [];
    $timelineCounts = [];
    for ($i = 0; $i < 24; $i++) {
        $timelineLabels[] = sprintf('%02d:00', $i);
        $timelineCounts[] = 0;
    }

    foreach ($timelineData as $item) {
        $hour = (int)$item['hour'];
        $timelineCounts[$hour] = (int)$item['count'];
    }

    // Get action breakdown
    $actionBreakdownQuery = "
        SELECT 
            CASE 
                WHEN action LIKE '%login%' THEN 'login'
                WHEN action LIKE '%role%' OR action LIKE '%role_changed%' THEN 'role_changed'
                WHEN action LIKE '%request%' THEN 'request'
                WHEN action LIKE '%ticket%' THEN 'ticket_appealed'
                WHEN action LIKE '%system%' OR action LIKE '%config%' OR action LIKE '%price%' THEN 'system_changes'
                ELSE 'other'
            END as action_type,
            COUNT(*) as count
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        WHERE $whereClause
        AND (ur.role != 'RESIDENT' OR ur.role IS NULL OR al.action LIKE '%system%' OR al.action LIKE '%config%' OR al.action LIKE '%price%')
        GROUP BY action_type
    ";

    $stmt = $pdo->prepare($actionBreakdownQuery);
    $stmt->execute($params);
    $actionBreakdownData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $actionBreakdown = [
        'login' => 0,
        'role_changed' => 0,
        'request' => 0,
        'ticket_appealed' => 0,
        'system_changes' => 0
    ];

    foreach ($actionBreakdownData as $item) {
        $actionBreakdown[$item['action_type']] = (int)$item['count'];
    }

    echo json_encode([
        'success' => true,
        'stats' => [
            'totalActivities' => (int)$stats['totalActivities'],
            'loginCount' => (int)$stats['loginCount'],
            'roleChangeCount' => (int)$stats['roleChangeCount'],
            'requestCount' => (int)$stats['requestCount'],
            'ticketAppealCount' => (int)$stats['ticketAppealCount'],
            'systemChangeCount' => (int)$stats['systemChangeCount']
        ],
        'charts' => [
            'timeline' => [
                'labels' => $timelineLabels,
                'data' => $timelineCounts
            ],
            'actionBreakdown' => $actionBreakdown
        ],
        'logs' => $logs
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
?>