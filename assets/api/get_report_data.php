<?php
// get_report_data.php - Located in: Project_A2/assets/api/
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json');

try {
    // CORRECT PATH: Go to Project_A2/includes/config.php
    $configPath = __DIR__ . '/../../includes/config.php';
    
    if (!file_exists($configPath)) {
        throw new Exception('Database configuration file not found at: ' . $configPath);
    }
    
    include $configPath;
    
    if (!isset($pdo)) {
        throw new Exception('Database connection not established');
    }

    $period = $_GET['period'] ?? 'monthly';

    // User Overview
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $rejectedUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'rejected'")->fetchColumn();

    // Request Overview based on period
    $requestWhere = "";
    $revenueWhere = "";
    $ticketWhere = "";
    
    switch($period) {
        case 'weekly':
            $requestWhere = "AND YEARWEEK(date_requested, 1) = YEARWEEK(CURDATE(), 1)";
            $revenueWhere = "AND YEARWEEK(dr.date_requested, 1) = YEARWEEK(CURDATE(), 1)";
            $ticketWhere = "AND YEARWEEK(ticket_created_at, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'yearly':
            $requestWhere = "AND YEAR(date_requested) = YEAR(CURDATE())";
            $revenueWhere = "AND YEAR(dr.date_requested) = YEAR(CURDATE())";
            $ticketWhere = "AND YEAR(ticket_created_at) = YEAR(CURDATE())";
            break;
        default: // monthly
            $requestWhere = "AND MONTH(date_requested) = MONTH(CURDATE()) AND YEAR(date_requested) = YEAR(CURDATE())";
            $revenueWhere = "AND MONTH(dr.date_requested) = MONTH(CURDATE()) AND YEAR(dr.date_requested) = YEAR(CURDATE())";
            $ticketWhere = "AND MONTH(ticket_created_at) = MONTH(CURDATE()) AND YEAR(ticket_created_at) = YEAR(CURDATE())";
    }

    $totalRequests = $pdo->query("SELECT COUNT(*) FROM document_requests WHERE 1=1 $requestWhere")->fetchColumn();
    $rejectedRequests = $pdo->query("SELECT COUNT(*) FROM document_requests WHERE request_status = 'rejected' $requestWhere")->fetchColumn();

    // Revenue Overview
    $totalRevenue = $pdo->query("
        SELECT COALESCE(SUM(dt.doc_price), 0) 
        FROM document_requests dr 
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id 
        WHERE dr.request_status IN ('printed', 'signed', 'completed') 
        $revenueWhere
    ")->fetchColumn();

    // Support Tickets
    $totalTickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE 1=1 $ticketWhere")->fetchColumn();

    // Request Chart Data
    $requestChartLabels = [];
    $requestChartData = [];
    
    switch($period) {
        case 'weekly':
            $stmt = $pdo->query("
                SELECT DATE_FORMAT(date_requested, '%a') AS day, COUNT(*) AS count
                FROM document_requests 
                WHERE YEARWEEK(date_requested, 1) = YEARWEEK(CURDATE(), 1)
                GROUP BY DATE(date_requested)
                ORDER BY DATE(date_requested)
            ");
            break;
        case 'yearly':
            $stmt = $pdo->query("
                SELECT DATE_FORMAT(date_requested, '%b') AS month, COUNT(*) AS count
                FROM document_requests 
                WHERE YEAR(date_requested) = YEAR(CURDATE())
                GROUP BY MONTH(date_requested)
                ORDER BY MONTH(date_requested)
            ");
            break;
        default: // monthly
            $stmt = $pdo->query("
                SELECT WEEK(date_requested, 1) - WEEK(DATE_SUB(date_requested, INTERVAL DAYOFMONTH(date_requested)-1 DAY), 1) + 1 AS week_num,
                       COUNT(*) AS count
                FROM document_requests 
                WHERE MONTH(date_requested) = MONTH(CURDATE()) AND YEAR(date_requested) = YEAR(CURDATE())
                GROUP BY week_num
                ORDER BY week_num
            ");
    }
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if($period === 'monthly') {
            $requestChartLabels[] = 'Week ' . $row['week_num'];
        } else {
            $requestChartLabels[] = $row[array_keys($row)[0]];
        }
        $requestChartData[] = (int)$row['count'];
    }

    // Revenue Chart Data
    $revenueChartLabels = [];
    $revenueChartData = [];
    
    switch($period) {
        case 'weekly':
            $stmt = $pdo->query("
                SELECT DATE_FORMAT(dr.date_requested, '%a') AS day, 
                       COALESCE(SUM(dt.doc_price), 0) AS total
                FROM document_requests dr
                JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                WHERE dr.request_status IN ('printed','signed','completed')
                AND YEARWEEK(dr.date_requested, 1) = YEARWEEK(CURDATE(), 1)
                GROUP BY DATE(dr.date_requested)
                ORDER BY DATE(dr.date_requested)
            ");
            break;
        case 'yearly':
            $stmt = $pdo->query("
                SELECT DATE_FORMAT(dr.date_requested, '%b') AS month, 
                       COALESCE(SUM(dt.doc_price), 0) AS total
                FROM document_requests dr
                JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                WHERE dr.request_status IN ('printed','signed','completed')
                AND YEAR(dr.date_requested) = YEAR(CURDATE())
                GROUP BY MONTH(dr.date_requested)
                ORDER BY MONTH(dr.date_requested)
            ");
            break;
        default: // monthly
            $stmt = $pdo->query("
                SELECT WEEK(dr.date_requested, 1) - WEEK(DATE_SUB(dr.date_requested, INTERVAL DAYOFMONTH(dr.date_requested)-1 DAY), 1) + 1 AS week_num,
                       COALESCE(SUM(dt.doc_price), 0) AS total
                FROM document_requests dr
                JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                WHERE dr.request_status IN ('printed','signed','completed')
                AND MONTH(dr.date_requested) = MONTH(CURDATE()) AND YEAR(dr.date_requested) = YEAR(CURDATE())
                GROUP BY week_num
                ORDER BY week_num
            ");
    }
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if($period === 'monthly') {
            $revenueChartLabels[] = 'Week ' . $row['week_num'];
        } else {
            $revenueChartLabels[] = $row[array_keys($row)[0]];
        }
        $revenueChartData[] = (float)$row['total'];
    }

    // Calculate prediction (simple trend based on last two data points)
    $prediction = 0;
    if(count($revenueChartData) >= 2) {
        $lastValue = end($revenueChartData);
        $secondLastValue = $revenueChartData[count($revenueChartData)-2];
        if($secondLastValue > 0) {
            $growthRate = ($lastValue - $secondLastValue) / $secondLastValue;
            $prediction = $lastValue * (1 + $growthRate);
        }
    }

    echo json_encode([
        'userOverview' => [
            'totalUsers' => (int)$totalUsers,
            'rejectedUsers' => (int)$rejectedUsers
        ],
        'requestOverview' => [
            'totalRequests' => (int)$totalRequests,
            'rejectedRequests' => (int)$rejectedRequests
        ],
        'revenueOverview' => [
            'totalRevenue' => (float)$totalRevenue,
            'prediction' => (float)$prediction
        ],
        'supportTickets' => [
            'totalTickets' => (int)$totalTickets
        ],
        'requestChart' => [
            'labels' => $requestChartLabels,
            'data' => $requestChartData
        ],
        'revenueChart' => [
            'labels' => $revenueChartLabels,
            'data' => $revenueChartData
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>