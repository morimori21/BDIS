<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

    $period = $_GET['period'] ?? 'monthly';

    // ========= USER OVERVIEW =========
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $rejectedUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'rejected'")->fetchColumn();
    $verifiedUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'verified'")->fetchColumn();
    $pendingUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();

    // ========= REQUEST WHERE CLAUSES =========
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

    // ========= REQUEST OVERVIEW =========
    // Total Requests: in-progress + completed
    $totalRequests = $pdo->query("
        SELECT COUNT(*) 
        FROM document_requests 
        WHERE request_status IN ('in-progress', 'completed')
        $requestWhere
    ")->fetchColumn();
    
    // Rejected requests
    $rejectedRequests = $pdo->query("
        SELECT COUNT(*) 
        FROM document_requests 
        WHERE request_status = 'rejected' 
        $requestWhere
    ")->fetchColumn();
    
    // Pending requests
    $pendingRequests = $pdo->query("
        SELECT COUNT(*) 
        FROM document_requests 
        WHERE request_status = 'pending' 
        $requestWhere
    ")->fetchColumn();
    
    // In-progress requests
    $inProgressRequests = $pdo->query("
        SELECT COUNT(*) 
        FROM document_requests 
        WHERE request_status = 'in-progress' 
        $requestWhere
    ")->fetchColumn();
    
    // Completed requests
    $completedRequests = $pdo->query("
        SELECT COUNT(*) 
        FROM document_requests 
        WHERE request_status = 'completed' 
        $requestWhere
    ")->fetchColumn();
    
    // Picked-up requests
    $pickedUpRequests = $pdo->query("
        SELECT COUNT(*) 
        FROM document_requests 
        WHERE request_status = 'picked-up' 
        $requestWhere
    ")->fetchColumn();

    // ========= REVENUE OVERVIEW =========
    // Revenue: include in-progress, completed, and picked-up (since they're paid)
    $totalRevenue = $pdo->query("
        SELECT COALESCE(SUM(dt.doc_price), 0) 
        FROM document_requests dr 
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id 
        WHERE dr.request_status IN ('in-progress', 'completed', 'picked-up')
        $revenueWhere
    ")->fetchColumn();

    // ========= SUPPORT TICKETS =========
    $totalTickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE 1=1 $ticketWhere")->fetchColumn();
    $openTickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE ticket_status = 'open' $ticketWhere")->fetchColumn();
    $inProgressTickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE ticket_status = 'in-progress' $ticketWhere")->fetchColumn();
    $resolvedTickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE ticket_status = 'resolved' $ticketWhere")->fetchColumn();

    // ========= REQUEST CHART DATA =========
    $requestChartLabels = [];
    $requestChartData = [];
    
    switch($period) {
        case 'weekly':
            $stmt = $pdo->query("
                SELECT DATE_FORMAT(date_requested, '%a') AS day, COUNT(*) AS count
                FROM document_requests 
                WHERE YEARWEEK(date_requested, 1) = YEARWEEK(CURDATE(), 1)
                AND request_status IN ('in-progress', 'completed')
                GROUP BY DATE(date_requested)
                ORDER BY DATE(date_requested)
            ");
            break;
        case 'yearly':
            $stmt = $pdo->query("
                SELECT DATE_FORMAT(date_requested, '%b') AS month, COUNT(*) AS count
                FROM document_requests 
                WHERE YEAR(date_requested) = YEAR(CURDATE())
                AND request_status IN ('in-progress', 'completed')
                GROUP BY MONTH(date_requested)
                ORDER BY MONTH(date_requested)
            ");
            break;
        default: // monthly
            $stmt = $pdo->query("
                SELECT WEEK(date_requested, 1) - WEEK(DATE_SUB(date_requested, INTERVAL DAYOFMONTH(date_requested)-1 DAY), 1) + 1 AS week_num,
                       COUNT(*) AS count
                FROM document_requests 
                WHERE MONTH(date_requested) = MONTH(CURDATE()) 
                AND YEAR(date_requested) = YEAR(CURDATE())
                AND request_status IN ('in-progress', 'completed')
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

    // ========= REVENUE CHART DATA =========
    $revenueChartLabels = [];
    $revenueChartData = [];
    
    switch($period) {
        case 'weekly':
            $stmt = $pdo->query("
                SELECT DATE_FORMAT(dr.date_requested, '%a') AS day, 
                       COALESCE(SUM(dt.doc_price), 0) AS total
                FROM document_requests dr
                JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                WHERE dr.request_status IN ('in-progress', 'completed', 'picked-up')
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
                WHERE dr.request_status IN ('in-progress', 'completed', 'picked-up')
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
                WHERE dr.request_status IN ('in-progress', 'completed', 'picked-up')
                AND MONTH(dr.date_requested) = MONTH(CURDATE()) 
                AND YEAR(dr.date_requested) = YEAR(CURDATE())
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

    // ========= PREDICTION CALCULATION =========
    $prediction = 0;
    if(count($revenueChartData) >= 2) {
        $lastValue = end($revenueChartData);
        $secondLastValue = $revenueChartData[count($revenueChartData)-2];
        if($secondLastValue > 0) {
            $growthRate = ($lastValue - $secondLastValue) / $secondLastValue;
            $prediction = $lastValue * (1 + $growthRate);
        }
    }

    // Return the complete data
    echo json_encode([
        'success' => true,
        'userOverview' => [
            'totalUsers' => (int)$totalUsers,
            'rejectedUsers' => (int)$rejectedUsers,
            'verifiedUsers' => (int)$verifiedUsers,
            'pendingUsers' => (int)$pendingUsers
        ],
        'requestOverview' => [
            'totalRequests' => (int)$totalRequests,
            'rejectedRequests' => (int)$rejectedRequests,
            'pendingRequests' => (int)$pendingRequests,
            'inProgressRequests' => (int)$inProgressRequests,
            'completedRequests' => (int)$completedRequests,
            'pickedUpRequests' => (int)$pickedUpRequests
        ],
        'revenueOverview' => [
            'totalRevenue' => (float)$totalRevenue,
            'prediction' => (float)$prediction
        ],
        'supportTickets' => [
            'totalTickets' => (int)$totalTickets,
            'openTickets' => (int)$openTickets,
            'inProgressTickets' => (int)$inProgressTickets,
            'resolvedTickets' => (int)$resolvedTickets
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
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
?>