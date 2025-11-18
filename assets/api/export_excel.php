<?php
session_start();
require_once '../../includes/config.php';

// Check if user is authorized - based on your database structure
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

// Check if user has secretary role
$stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userRole = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userRole || $userRole['role'] != 'secretary') {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

// Fetch barangay address information
$addressStmt = $pdo->prepare("SELECT brgy_name, municipality, province FROM address_config LIMIT 1");
$addressStmt->execute();
$barangayInfo = $addressStmt->fetch(PDO::FETCH_ASSOC);

// Set default values if no address config found
$brgyName = $barangayInfo['brgy_name'] ?? 'Barangay';
$municipality = $barangayInfo['municipality'] ?? 'Municipality';
$province = $barangInfo['province'] ?? 'Province';

// Set headers for Excel file download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="barangay_report_' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Get period from request
$period = $_GET['period'] ?? 'monthly';
$periodText = ucfirst($period);

// Fetch data based on period
$reportData = fetchReportData($pdo, $period);

function fetchReportData($pdo, $period) {
    $data = [];
    
    // Date range based on period - using date_registered for users and date_requested for requests
    $userDateCondition = "";
    $requestDateCondition = "";
    $ticketDateCondition = "";
    
    switch($period) {
        case 'weekly':
            $userDateCondition = "WHERE YEARWEEK(u.date_registered, 1) = YEARWEEK(CURDATE(), 1)";
            $requestDateCondition = "WHERE YEARWEEK(dr.date_requested, 1) = YEARWEEK(CURDATE(), 1)";
            $ticketDateCondition = "WHERE YEARWEEK(st.ticket_created_at, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'monthly':
            $userDateCondition = "WHERE DATE_FORMAT(u.date_registered, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
            $requestDateCondition = "WHERE DATE_FORMAT(dr.date_requested, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
            $ticketDateCondition = "WHERE DATE_FORMAT(st.ticket_created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
            break;
        case 'yearly':
            $userDateCondition = "WHERE YEAR(u.date_registered) = YEAR(CURDATE())";
            $requestDateCondition = "WHERE YEAR(dr.date_requested) = YEAR(CURDATE())";
            $ticketDateCondition = "WHERE YEAR(st.ticket_created_at) = YEAR(CURDATE())";
            break;
        default:
            $userDateCondition = "WHERE DATE_FORMAT(u.date_registered, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
            $requestDateCondition = "WHERE DATE_FORMAT(dr.date_requested, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
            $ticketDateCondition = "WHERE DATE_FORMAT(st.ticket_created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    }

    // User Overview
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN u.status = 'rejected' THEN 1 ELSE 0 END) as rejected_users,
            SUM(CASE WHEN u.status = 'verified' THEN 1 ELSE 0 END) as verified_users,
            SUM(CASE WHEN u.status = 'pending' THEN 1 ELSE 0 END) as pending_users
        FROM users u
        $userDateCondition
    ");
    $stmt->execute();
    $data['userOverview'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // Request Overview - Updated to include all statuses
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN dr.request_status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
            SUM(CASE WHEN dr.request_status = 'in-progress' THEN 1 ELSE 0 END) as inprogress_requests,
            SUM(CASE WHEN dr.request_status = 'printed' THEN 1 ELSE 0 END) as printed_requests,
            SUM(CASE WHEN dr.request_status = 'signed' THEN 1 ELSE 0 END) as signed_requests,
            SUM(CASE WHEN dr.request_status = 'completed' THEN 1 ELSE 0 END) as completed_requests,
            SUM(CASE WHEN dr.request_status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests,
            SUM(CASE WHEN dr.request_status IN ('signed', 'completed') THEN dt.doc_price ELSE 0 END) as total_revenue
        FROM document_requests dr
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
        $requestDateCondition
    ");
    $stmt->execute();
    $data['requestOverview'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // Support Tickets
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_tickets,
            SUM(CASE WHEN st.ticket_status = 'open' THEN 1 ELSE 0 END) as open_tickets,
            SUM(CASE WHEN st.ticket_status = 'resolved' THEN 1 ELSE 0 END) as resolved_tickets
        FROM support_tickets st
        $ticketDateCondition
    ");
    $stmt->execute();
    $data['supportTickets'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // Detailed Requests by Type - Updated to include all statuses
    $stmt = $pdo->prepare("
        SELECT 
            dt.doc_name,
            COUNT(*) as request_count,
            SUM(CASE WHEN dr.request_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN dr.request_status = 'in-progress' THEN 1 ELSE 0 END) as inprogress,
            SUM(CASE WHEN dr.request_status = 'printed' THEN 1 ELSE 0 END) as printed,
            SUM(CASE WHEN dr.request_status = 'signed' THEN 1 ELSE 0 END) as signed,
            SUM(CASE WHEN dr.request_status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN dr.request_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN dr.request_status IN ('signed', 'completed') THEN dt.doc_price ELSE 0 END) as total_revenue
        FROM document_requests dr
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
        $requestDateCondition
        GROUP BY dt.doc_type_id, dt.doc_name
    ");
    $stmt->execute();
    $data['requestByType'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $data;
}
?>

<html>
<head>
    <meta charset="UTF-8">
    <style>
        .header { background-color: #2c3e50; color: white; font-weight: bold; }
        .section { background-color: #ecf0f1; font-weight: bold; }
        .total { background-color: #3498db; color: white; font-weight: bold; }
        .rejected { background-color: #e74c3c; color: white; }
        .completed { background-color: #27ae60; color: white; }
        .pending { background-color: #f39c12; color: white; }
        .inprogress { background-color: #3498db; color: white; }
        .printed { background-color: #9b59b6; color: white; }
        .signed { background-color: #16a085; color: white; }
        .excel-table { border-collapse: collapse; width: 100%; }
        .excel-table td, .excel-table th { border: 1px solid #ddd; padding: 8px; }
        .excel-table tr:nth-child(even) { background-color: #f2f2f2; }
        .excel-table tr:hover { background-color: #ddd; }
        .barangay-header { background-color: #34495e; color: white; text-align: center; }
        .barangay-name { font-size: 18px; font-weight: bold; }
        .barangay-address { font-size: 14px; }
    </style>
</head>
<body>
    <table class="excel-table">
        <!-- Barangay Header -->
        <tr class="barangay-header">
            <td colspan="8" style="padding: 15px;">
                <div class="barangay-name"><?php echo strtoupper($brgyName); ?></div>
                <div class="barangay-address"><?php echo $municipality . ', ' . $province; ?></div>
            </td>
        </tr>
        
        <!-- Report Header -->
        <tr class="header">
            <td colspan="8" style="text-align: center; font-size: 16px; padding: 10px;">
                BARANGAY MANAGEMENT SYSTEM - REPORT
            </td>
        </tr>
        <tr>
            <td colspan="8" style="padding: 8px;">
                <strong>Period:</strong> <?php echo $periodText; ?> (<?php echo date('F j, Y'); ?>)<br>
                <strong>Generated On:</strong> <?php echo date('F j, Y g:i A'); ?>
            </td>
        </tr>
        <tr><td colspan="8" style="background-color: #eee; height: 10px;">&nbsp;</td></tr>

        <!-- User Overview Section -->
        <tr class="section">
            <td colspan="8">USER OVERVIEW</td>
        </tr>
        <tr>
            <td><strong>Metric</strong></td>
            <td><strong>Total</strong></td>
            <td><strong>Verified</strong></td>
            <td><strong>Pending</strong></td>
            <td><strong>Rejected</strong></td>
            <td><strong>Active Rate</strong></td>
            <td colspan="2">&nbsp;</td>
        </tr>
        <tr>
            <td>Users</td>
            <td><?php echo $reportData['userOverview']['total_users'] ?? 0; ?></td>
            <td class="completed"><?php echo $reportData['userOverview']['verified_users'] ?? 0; ?></td>
            <td class="pending"><?php echo $reportData['userOverview']['pending_users'] ?? 0; ?></td>
            <td class="rejected"><?php echo $reportData['userOverview']['rejected_users'] ?? 0; ?></td>
            <td>
                <?php 
                $totalUsers = $reportData['userOverview']['total_users'] ?? 1;
                $verifiedUsers = $reportData['userOverview']['verified_users'] ?? 0;
                echo $totalUsers > 0 ? round(($verifiedUsers / $totalUsers) * 100, 1) . '%' : '0%';
                ?>
            </td>
            <td colspan="2">&nbsp;</td>
        </tr>
        <tr><td colspan="8" style="background-color: #eee; height: 10px;">&nbsp;</td></tr>

        <!-- Request Overview Section -->
        <tr class="section">
            <td colspan="8">REQUEST OVERVIEW</td>
        </tr>
        <tr>
            <td><strong>Metric</strong></td>
            <td><strong>Total</strong></td>
            <td><strong>Pending</strong></td>
            <td><strong>In Progress</strong></td>
            <td><strong>Printed</strong></td>
            <td><strong>Signed</strong></td>
            <td><strong>Completed</strong></td>
            <td><strong>Rejected</strong></td>
        </tr>
        <tr>
            <td>Document Requests</td>
            <td><?php echo $reportData['requestOverview']['total_requests'] ?? 0; ?></td>
            <td class="pending"><?php echo $reportData['requestOverview']['pending_requests'] ?? 0; ?></td>
            <td class="inprogress"><?php echo $reportData['requestOverview']['inprogress_requests'] ?? 0; ?></td>
            <td class="printed"><?php echo $reportData['requestOverview']['printed_requests'] ?? 0; ?></td>
            <td class="signed"><?php echo $reportData['requestOverview']['signed_requests'] ?? 0; ?></td>
            <td class="completed"><?php echo $reportData['requestOverview']['completed_requests'] ?? 0; ?></td>
            <td class="rejected"><?php echo $reportData['requestOverview']['rejected_requests'] ?? 0; ?></td>
        </tr>
        <tr>
            <td><strong>Completion Rate</strong></td>
            <td colspan="7">
                <?php 
                $totalRequests = $reportData['requestOverview']['total_requests'] ?? 1;
                $completedRequests = $reportData['requestOverview']['completed_requests'] ?? 0;
                echo $totalRequests > 0 ? round(($completedRequests / $totalRequests) * 100, 1) . '%' : '0%';
                ?>
            </td>
        </tr>
        <tr class="total">
            <td><strong>Total Revenue</strong></td>
            <td colspan="7" style="text-align: center;">
                <strong>₱<?php echo number_format($reportData['requestOverview']['total_revenue'] ?? 0, 2); ?></strong>
                (Only from Signed and Completed requests)
            </td>
        </tr>
        <tr><td colspan="8" style="background-color: #eee; height: 10px;">&nbsp;</td></tr>

        <!-- Requests by Document Type -->
        <tr class="section">
            <td colspan="8">REQUESTS BY DOCUMENT TYPE</td>
        </tr>
        <tr>
            <td><strong>Document Type</strong></td>
            <td><strong>Total</strong></td>
            <td><strong>Pending</strong></td>
            <td><strong>In Progress</strong></td>
            <td><strong>Printed</strong></td>
            <td><strong>Signed</strong></td>
            <td><strong>Completed</strong></td>
            <td><strong>Rejected</strong></td>
            <td><strong>Revenue</strong></td>
        </tr>
        <?php 
        $totalAllRequests = 0;
        $totalAllRevenue = 0;
        if (!empty($reportData['requestByType'])): 
            foreach($reportData['requestByType'] as $type): 
                $totalAllRequests += $type['request_count'];
                $totalAllRevenue += $type['total_revenue'];
        ?>
        <tr>
            <td><?php echo htmlspecialchars($type['doc_name']); ?></td>
            <td><?php echo $type['request_count']; ?></td>
            <td class="pending"><?php echo $type['pending']; ?></td>
            <td class="inprogress"><?php echo $type['inprogress']; ?></td>
            <td class="printed"><?php echo $type['printed']; ?></td>
            <td class="signed"><?php echo $type['signed']; ?></td>
            <td class="completed"><?php echo $type['completed']; ?></td>
            <td class="rejected"><?php echo $type['rejected']; ?></td>
            <td>₱<?php echo number_format($type['total_revenue'], 2); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr>
            <td colspan="9" style="text-align: center;">No data available</td>
        </tr>
        <?php endif; ?>
        
        <!-- Total Row for Requests by Type -->
        <tr class="total">
            <td><strong>GRAND TOTAL</strong></td>
            <td><strong><?php echo $totalAllRequests; ?></strong></td>
            <td colspan="6">&nbsp;</td>
            <td><strong>₱<?php echo number_format($totalAllRevenue, 2); ?></strong></td>
        </tr>
        <tr><td colspan="9" style="background-color: #eee; height: 10px;">&nbsp;</td></tr>

        <!-- Support Tickets Section -->
        <tr class="section">
            <td colspan="8">SUPPORT TICKETS OVERVIEW</td>
        </tr>
        <tr>
            <td><strong>Metric</strong></td>
            <td><strong>Total</strong></td>
            <td><strong>Open</strong></td>
            <td><strong>Resolved</strong></td>
            <td><strong>Resolution Rate</strong></td>
            <td><strong>Status</strong></td>
            <td colspan="3">&nbsp;</td>
        </tr>
        <tr>
            <td>Support Tickets</td>
            <td><?php echo $reportData['supportTickets']['total_tickets'] ?? 0; ?></td>
            <td class="rejected"><?php echo $reportData['supportTickets']['open_tickets'] ?? 0; ?></td>
            <td class="completed"><?php echo $reportData['supportTickets']['resolved_tickets'] ?? 0; ?></td>
            <td>
                <?php 
                $totalTickets = $reportData['supportTickets']['total_tickets'] ?? 1;
                $resolvedTickets = $reportData['supportTickets']['resolved_tickets'] ?? 0;
                echo $totalTickets > 0 ? round(($resolvedTickets / $totalTickets) * 100, 1) . '%' : '0%';
                ?>
            </td>
            <td>
                <?php 
                $openTickets = $reportData['supportTickets']['open_tickets'] ?? 0;
                echo $openTickets > 0 ? 'Needs Attention' : 'All Good';
                ?>
            </td>
            <td colspan="3">&nbsp;</td>
        </tr>
        <tr><td colspan="8" style="background-color: #eee; height: 10px;">&nbsp;</td></tr>

        <!-- Summary Section -->
        <tr class="section">
            <td colspan="8">SUMMARY</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Overall Performance</strong></td>
            <td colspan="6">
                <?php
                $totalRequests = $reportData['requestOverview']['total_requests'] ?? 1;
                $completedRequests = $reportData['requestOverview']['completed_requests'] ?? 0;
                $totalUsers = $reportData['userOverview']['total_users'] ?? 1;
                $verifiedUsers = $reportData['userOverview']['verified_users'] ?? 0;
                $totalTickets = $reportData['supportTickets']['total_tickets'] ?? 1;
                $resolvedTickets = $reportData['supportTickets']['resolved_tickets'] ?? 0;
                
                $completionRate = $totalRequests > 0 ? round(($completedRequests / $totalRequests) * 100, 1) : 0;
                $verificationRate = $totalUsers > 0 ? round(($verifiedUsers / $totalUsers) * 100, 1) : 0;
                $resolutionRate = $totalTickets > 0 ? round(($resolvedTickets / $totalTickets) * 100, 1) : 0;
                
                if ($completionRate >= 80 && $verificationRate >= 80 && $resolutionRate >= 80) {
                    echo "Excellent Performance - All systems running efficiently";
                } elseif ($completionRate >= 60 && $verificationRate >= 60) {
                    echo "Good Performance - Room for improvement";
                } else {
                    echo "Needs Attention - Review processes required";
                }
                ?>
            </td>
        </tr>
        <tr>
            <td colspan="2"><strong>Key Metrics</strong></td>
            <td>Request Completion: <?php echo $completionRate; ?>%</td>
            <td>User Verification: <?php echo $verificationRate; ?>%</td>
            <td>Ticket Resolution: <?php echo $resolutionRate; ?>%</td>
            <td colspan="3">Total Revenue: ₱<?php echo number_format($reportData['requestOverview']['total_revenue'] ?? 0, 2); ?></td>
        </tr>
    </table>
</body>
</html>