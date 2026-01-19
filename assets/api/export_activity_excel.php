<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    $configPath = __DIR__ . '/../../includes/config.php';
    
    if (!file_exists($configPath)) {
        throw new Exception('Database configuration file not found');
    }
    
    include $configPath;
    
    if (!isset($pdo)) {
        throw new Exception('Database connection not established');
    }

    // Get filter parameters
    $role = $_GET['role'] ?? 'all';
    $user_id = $_GET['user_id'] ?? 'all';
    $date = $_GET['date'] ?? date('Y-m-d');
    $action_type = $_GET['action_type'] ?? 'all';

    // Get barangay information
    $barangayQuery = "SELECT brgy_name, municipality, province FROM address_config LIMIT 1";
    $barangayStmt = $pdo->query($barangayQuery);
    $barangayInfo = $barangayStmt->fetch(PDO::FETCH_ASSOC);
    
    $barangayName = $barangayInfo['brgy_name'] ?? 'Barangay';
    $municipality = $barangayInfo['municipality'] ?? 'Municipality';
    $province = $barangayInfo['province'] ?? 'Province';

    // Build WHERE conditions
    $whereConditions = ["DATE(al.action_time) = :date"];
    $params = [':date' => $date];

    if ($user_id !== 'all') {
        $whereConditions[] = "al.user_id = :user_id";
        $params[':user_id'] = $user_id;
    }

    if ($role !== 'all') {
        $whereConditions[] = "ur.role = :role";
        $params[':role'] = $role;
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
            SUM(CASE WHEN al.action LIKE '%login%' THEN 1 ELSE 0 END) as loginCount,
            SUM(CASE WHEN al.action LIKE '%role%' OR al.action LIKE '%role_changed%' THEN 1 ELSE 0 END) as roleChangeCount,
            SUM(CASE WHEN al.action LIKE '%request%' THEN 1 ELSE 0 END) as requestCount,
            SUM(CASE WHEN al.action LIKE '%ticket%' THEN 1 ELSE 0 END) as ticketAppealCount,
            SUM(CASE WHEN al.action LIKE '%system%' OR al.action LIKE '%config%' OR al.action LIKE '%price%' THEN 1 ELSE 0 END) as systemChangeCount
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        WHERE $whereClause
    ";

    $stmt = $pdo->prepare($statsQuery);
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get activity data
    $activityQuery = "
        SELECT 
            CONCAT(u.first_name, ' ', u.surname) as user_name,
            ur.role as user_role,
            al.action,
            al.action_details,
            al.action_time
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        WHERE $whereClause
        ORDER BY al.action_time DESC
    ";

    $stmt = $pdo->prepare($activityQuery);
    $stmt->execute($params);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate filename
    $filename = $barangayName . "_activity_report_" . $date . ".xls";

    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Excel header with UTF-8 BOM
    echo "\xEF\xBB\xBF";
    
    // ===== COMPACT EXCEL FORMAT =====
    echo "<html>
    <head>
    <meta charset=\"UTF-8\">
    <style>
    body { font-family: Arial, sans-serif; font-size: 10px; }
    table { border-collapse: collapse; width: 100%; }
    .header { background: #2c3e50; color: white; padding: 8px; font-weight: bold; text-align: center; }
    .subheader { background: #34495e; color: white; padding: 6px; font-weight: bold; }
    .summary-row { background: #ecf0f1; }
    .summary-label { background: #95a5a6; color: white; padding: 4px; font-weight: bold; width: 15%; }
    .summary-value { background: #ecf0f1; padding: 4px; text-align: center; width: 10%; }
    .stats-header { background: #3498db; color: white; padding: 6px; font-weight: bold; }
    .stats-label { background: #2980b9; color: white; padding: 4px; font-weight: bold; }
    .stats-value { background: #d6eaf8; padding: 4px; text-align: center; font-weight: bold; }
    .activity-header { background: #e74c3c; color: white; padding: 6px; font-weight: bold; }
    .activity-row { background: #fadbd8; }
    .activity-alt { background: #fdedec; }
    .timestamp { white-space: nowrap; }
    .text-center { text-align: center; }
    .text-left { text-align: left; }
    .border { border: 1px solid #bdc3c7; }
    </style>
    </head>
    <body>";
    
    echo "<table class=\"border\">";
    
    // Main Header
    echo "<tr><td colspan=\"6\" class=\"header\">" . strtoupper($barangayName) . " ACTIVITY REPORT</td></tr>";
    
    // Report Summary - Compact horizontal layout
    echo "<tr><td colspan=\"6\" class=\"subheader\">REPORT SUMMARY</td></tr>";
    echo "<tr class=\"summary-row\">
        <td class=\"summary-label\">Date</td>
        <td class=\"summary-value\">" . $date . "</td>
        <td class=\"summary-label\">Barangay</td>
        <td class=\"summary-value\">" . $barangayName . "</td>
        <td class=\"summary-label\">Location</td>
        <td class=\"summary-value\">" . $municipality . ", " . $province . "</td>
    </tr>";
    echo "<tr class=\"summary-row\">
        <td class=\"summary-label\">Role</td>
        <td class=\"summary-value\">" . ($role === 'all' ? 'All' : $role) . "</td>
        <td class=\"summary-label\">Action Type</td>
        <td class=\"summary-value\">" . ($action_type === 'all' ? 'All' : $action_type) . "</td>
        <td class=\"summary-label\">Generated</td>
        <td class=\"summary-value\">" . date('M j, Y g:i A') . "</td>
    </tr>";
    
    // Activity Statistics - Compact grid
    echo "<tr><td colspan=\"6\" class=\"stats-header\">ACTIVITY STATISTICS</td></tr>";
    echo "<tr>
        <td class=\"stats-label\">Total Activities</td>
        <td class=\"stats-value\">" . $stats['totalActivities'] . "</td>
        <td class=\"stats-label\">Logins</td>
        <td class=\"stats-value\">" . $stats['loginCount'] . "</td>
        <td class=\"stats-label\">Role Changes</td>
        <td class=\"stats-value\">" . $stats['roleChangeCount'] . "</td>
    </tr>";
    echo "<tr>
        <td class=\"stats-label\">Document Requests</td>
        <td class=\"stats-value\">" . $stats['requestCount'] . "</td>
        <td class=\"stats-label\">Ticket Appeals</td>
        <td class=\"stats-value\">" . $stats['ticketAppealCount'] . "</td>
        <td class=\"stats-label\">System Changes</td>
        <td class=\"stats-value\">" . $stats['systemChangeCount'] . "</td>
    </tr>";
    
    echo "<tr><td colspan=\"6\" style=\"height: 10px;\"></td></tr>";
    
    // Activity Logs - Clean table
    echo "<tr><td colspan=\"6\" class=\"activity-header\">ACTIVITY LOGS</td></tr>";
    echo "<tr class=\"activity-header\">
        <th width=\"15%\">User</th>
        <th width=\"12%\">Role</th>
        <th width=\"18%\">Action</th>
        <th width=\"45%\">Details</th>
        <th width=\"10%\">Time</th>
    </tr>";
    
    foreach ($activities as $index => $activity) {
        $userName = $activity['user_name'] ?? 'System';
        $userRole = $activity['user_role'] ?? 'N/A';
        $action = $activity['action'];
        
        // Clean details for Excel
        $details = $activity['action_details'];
        $details = str_replace(["\t", "\n", "\r"], ' ', $details);
        $details = preg_replace('/[^\x20-\x7E]/', '', $details);
        $details = trim($details);
        
        $timestamp = date('H:i:s', strtotime($activity['action_time']));
        
        $rowClass = $index % 2 === 0 ? 'activity-row' : 'activity-alt';
        
        echo "<tr class=\"$rowClass\">
            <td><strong>" . $userName . "</strong></td>
            <td>" . $userRole . "</td>
            <td>" . $action . "</td>
            <td class=\"text-left\">" . $details . "</td>
            <td class=\"timestamp text-center\">" . $timestamp . "</td>
        </tr>";
    }
    
    // Footer
    echo "<tr><td colspan=\"6\" class=\"header\" style=\"font-size: 9px;\">
        Generated by " . $barangayName . " Management System • " . date('F j, Y') . "
    </td></tr>";
    
    echo "</table>";
    echo "</body></html>";

    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo "Error generating Excel file: " . $e->getMessage();
} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}
?>