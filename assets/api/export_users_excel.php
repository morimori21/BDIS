<?php
// export_users_excel.php - User Management Export
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
    $street = $_GET['street'] ?? 'all';

    // Get barangay information
    $barangayQuery = "SELECT brgy_name, municipality, province FROM address_config LIMIT 1";
    $barangayStmt = $pdo->query($barangayQuery);
    $barangayInfo = $barangayStmt->fetch(PDO::FETCH_ASSOC);
    
    $barangayName = $barangayInfo['brgy_name'] ?? 'Barangay';
    $municipality = $barangayInfo['municipality'] ?? 'Municipality';
    $province = $barangayInfo['province'] ?? 'Province';
    $fullAddress = $barangayName . ', ' . $municipality . ', ' . $province;

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

    // Get user statistics for the report
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
            COUNT(*) as count
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
            COUNT(*) as count
        FROM users u
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        $whereClause
        GROUP BY u.street
        ORDER BY count DESC
        LIMIT 15
    ";

    $stmt = $pdo->prepare($streetQuery);
    $stmt->execute($params);
    $streetDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user data - SORTED ALPHABETICALLY with correct table relationships
    $userDataQuery = "
        SELECT 
            u.user_id,
            u.first_name,
            u.middle_name,
            u.surname,
            u.suffix,
            u.street,
            u.contact_number,
            u.birthdate,
            u.sex,
            u.status,
            u.date_registered,
            COALESCE(ur.role, 'resident') as role,
            ur.role_desc,
            ac.brgy_name,
            ac.municipality,
            ac.province,
            e.email
        FROM users u
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        LEFT JOIN address_config ac ON u.address_id = ac.address_id
        LEFT JOIN account a ON u.user_id = a.user_id
        LEFT JOIN email e ON a.email_id = e.email_id
        $whereClause
        ORDER BY u.surname ASC, u.first_name ASC, u.middle_name ASC
    ";

    $stmt = $pdo->prepare($userDataQuery);
    $stmt->execute($params);
    $userData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate total for percentages
    $totalUsers = (int)($stats['totalUsers'] ?? 0);

    // Generate filename
    $filename = $barangayName . "_user_management_report_";
    
    if ($role !== 'all') {
        $filename .= strtolower($role) . "_";
    }
    
    if ($street !== 'all') {
        $filename .= "street_" . str_replace(' ', '_', strtolower($street)) . "_";
    }
    
    $filename .= date('Y-m-d') . ".xls";

    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Excel header with UTF-8 BOM for proper encoding
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    
    // ===== EXCEL FORMATTING =====
    echo "<html xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:x=\"urn:schemas-microsoft-com:office:excel\" xmlns=\"http://www.w3.org/TR/REC-html40\">
    <head>
    <meta name=ProgId content=Excel.Sheet>
    <meta name=Generator content=\"Microsoft Excel 11\">
    <style>
    table {
        border-collapse: collapse;
        font-family: Arial, sans-serif;
        width: 100%;
    }
    .header {
        background-color: #2c3e50;
        color: white;
        font-weight: bold;
        font-size: 16px;
        padding: 12px;
        border: 1px solid #1a252f;
        text-align: center;
    }
    .barangay-header {
        background-color: #1a5276;
        color: white;
        font-weight: bold;
        font-size: 14px;
        padding: 10px;
        border: 1px solid #154360;
        text-align: center;
    }
    .stats-header {
        background-color: #27ae60;
        color: white;
        font-weight: bold;
        padding: 8px;
        border: 1px solid #219653;
    }
    .stats-row {
        background-color: #d5f4e6;
        padding: 6px;
        border: 1px solid #a3e4c1;
    }
    .user-header {
        background-color: #e74c3c;
        color: white;
        font-weight: bold;
        padding: 8px;
        border: 1px solid #c0392b;
    }
    .user-row {
        background-color: #fadbd8;
        padding: 6px;
        border: 1px solid #f5b7b1;
    }
    .user-alt {
        background-color: #fdedec;
        padding: 6px;
        border: 1px solid #f5b7b1;
    }
    .text-center { text-align: center; }
    .text-left { text-align: left; }
    .number { text-align: center; font-weight: bold; }
    .percentage { text-align: center; color: #27ae60; font-weight: bold; }
    .compact-label { 
        font-weight: bold; 
        padding: 4px 8px;
        border-right: 1px solid #a3e4c1;
        width: 40%;
    }
    .compact-value { 
        padding: 4px 8px;
        text-align: center;
        font-weight: bold;
        width: 20%;
        border-right: 1px solid #a3e4c1;
    }
    .compact-percentage { 
        padding: 4px 8px;
        text-align: center;
        color: #27ae60;
        font-weight: bold;
        width: 20%;
    }
    .column-header {
        background-color: #219653;
        color: white;
        font-weight: bold;
        padding: 6px;
        text-align: center;
        border: 1px solid #1e8449;
    }
    </style>
    </head>
    <body>";
    
    echo "<table width=\"100%\" cellpadding=\"3\" cellspacing=\"0\">";
    
    // Barangay Header
    echo "<tr><td colspan=\"13\" class=\"barangay-header\">" . strtoupper($barangayName) . " USER MANAGEMENT SYSTEM</td></tr>";
    echo "<tr><td colspan=\"13\" style=\"background-color: #8e44ad; color: white; font-weight: bold; padding: 8px; text-align: center; border: 1px solid #7d3c98;\">" . $fullAddress . "</td></tr>";
    
    // Main Header
    echo "<tr><td colspan=\"13\" class=\"header\">USER MANAGEMENT REPORT</td></tr>";
    
    // Report Summary
    echo "<tr><td colspan=\"13\" style=\"background-color: #34495e; color: white; font-weight: bold; padding: 8px; border: 1px solid #1a252f;\">REPORT SUMMARY</td></tr>";
    echo "<tr>
        <td style=\"background-color: #34495e; color: white; font-weight: bold; padding: 6px; border: 1px solid #1a252f;\">Generated On</td>
        <td style=\"background-color: #ecf0f1; padding: 6px; border: 1px solid #bdc3c7;\" colspan=\"3\">" . date('Y-m-d H:i:s') . "</td>
        <td style=\"background-color: #34495e; color: white; font-weight: bold; padding: 6px; border: 1px solid #1a252f;\">Role Filter</td>
        <td style=\"background-color: #ecf0f1; padding: 6px; border: 1px solid #bdc3c7;\" colspan=\"3\">" . ($role === 'all' ? 'All Roles' : $role) . "</td>
        <td style=\"background-color: #34495e; color: white; font-weight: bold; padding: 6px; border: 1px solid #1a252f;\">Street Filter</td>
        <td style=\"background-color: #ecf0f1; padding: 6px; border: 1px solid #bdc3c7;\" colspan=\"3\">" . ($street === 'all' ? 'All Streets' : $street) . "</td>
    </tr>";
    
    echo "<tr><td colspan=\"13\" style=\"height: 10px;\"></td></tr>";
    
    // USER STATISTICS - COMPACT LAYOUT
    echo "<tr><td colspan=\"13\" class=\"stats-header\">USER STATISTICS</td></tr>";
    
    $statRows = [
        ['Total Users', $stats['totalUsers']],
        ['Verified Users', $stats['verifiedUsers']],
        ['Pending Users', $stats['pendingUsers']],
        ['Admin Users', $stats['adminUsers']],
        ['Councilor', $stats['councilorUsers']],
        ['Secretary', $stats['secretaryUsers']],
        ['Captain', $stats['captainUsers']],
        ['Treasurer', $stats['treasurerUsers']],
        ['SK Chairman', $stats['skChairmanUsers']]
    ];
    
    // Create two columns for compact layout
    $halfCount = ceil(count($statRows) / 2);
    
    echo "<tr class=\"stats-row\">";
    echo "<td colspan=\"6\" style=\"vertical-align: top;\">";
    echo "<table width=\"100%\" cellpadding=\"2\" cellspacing=\"0\">";
    
    // Column headers for first column
    echo "<tr>
        <td class=\"column-header\">User Type</td>
        <td class=\"column-header\">Frequency (F)</td>
        <td class=\"column-header\">Percentage</td>
    </tr>";
    
    // First column data
    for ($i = 0; $i < $halfCount; $i++) {
        $percentage = $totalUsers > 0 ? round(($statRows[$i][1] / $totalUsers) * 100, 1) : 0;
        echo "<tr>
            <td class=\"compact-label\">" . $statRows[$i][0] . "</td>
            <td class=\"compact-value\">" . $statRows[$i][1] . "</td>
            <td class=\"compact-percentage\">" . $percentage . "%</td>
        </tr>";
    }
    
    echo "</table>";
    echo "</td>";
    
    echo "<td colspan=\"7\" style=\"vertical-align: top;\">";
    echo "<table width=\"100%\" cellpadding=\"2\" cellspacing=\"0\">";
    
    // Column headers for second column
    echo "<tr>
        <td class=\"column-header\">User Type</td>
        <td class=\"column-header\">Frequency (F)</td>
        <td class=\"column-header\">Percentage</td>
    </tr>";
    
    // Second column data
    for ($i = $halfCount; $i < count($statRows); $i++) {
        $percentage = $totalUsers > 0 ? round(($statRows[$i][1] / $totalUsers) * 100, 1) : 0;
        echo "<tr>
            <td class=\"compact-label\">" . $statRows[$i][0] . "</td>
            <td class=\"compact-value\">" . $statRows[$i][1] . "</td>
            <td class=\"compact-percentage\">" . $percentage . "%</td>
        </tr>";
    }
    
    echo "</table>";
    echo "</td>";
    echo "</tr>";
    
    echo "<tr><td colspan=\"13\" style=\"height: 10px;\"></td></tr>";
    
    // STREET DISTRIBUTION - COMPACT LAYOUT
    if (!empty($streetDistribution)) {
        echo "<tr><td colspan=\"13\" class=\"stats-header\">STREET DISTRIBUTION</td></tr>";
        
        // Create two columns for street distribution
        $streetHalfCount = ceil(count($streetDistribution) / 2);
        
        echo "<tr class=\"stats-row\">";
        echo "<td colspan=\"6\" style=\"vertical-align: top;\">";
        echo "<table width=\"100%\" cellpadding=\"2\" cellspacing=\"0\">";
        
        // Column headers for first column
        echo "<tr>
            <td class=\"column-header\">Street Name</td>
            <td class=\"column-header\">Frequency (F)</td>
            <td class=\"column-header\">Percentage</td>
        </tr>";
        
        // First column data
        for ($i = 0; $i < $streetHalfCount; $i++) {
            $streetData = $streetDistribution[$i];
            $percentage = $totalUsers > 0 ? round(($streetData['count'] / $totalUsers) * 100, 1) : 0;
            $streetName = $streetData['street'] === 'No Street Provided' ? 'No Street Provided' : $streetData['street'];
            
            echo "<tr>
                <td class=\"compact-label\">" . $streetName . "</td>
                <td class=\"compact-value\">" . $streetData['count'] . "</td>
                <td class=\"compact-percentage\">" . $percentage . "%</td>
            </tr>";
        }
        
        echo "</table>";
        echo "</td>";
        
        echo "<td colspan=\"7\" style=\"vertical-align: top;\">";
        echo "<table width=\"100%\" cellpadding=\"2\" cellspacing=\"0\">";
        
        // Column headers for second column
        echo "<tr>
            <td class=\"column-header\">Street Name</td>
            <td class=\"column-header\">Frequency (F)</td>
            <td class=\"column-header\">Percentage</td>
        </tr>";
        
        // Second column data
        for ($i = $streetHalfCount; $i < count($streetDistribution); $i++) {
            $streetData = $streetDistribution[$i];
            $percentage = $totalUsers > 0 ? round(($streetData['count'] / $totalUsers) * 100, 1) : 0;
            $streetName = $streetData['street'] === 'No Street Provided' ? 'No Street Provided' : $streetData['street'];
            
            echo "<tr>
                <td class=\"compact-label\">" . $streetName . "</td>
                <td class=\"compact-value\">" . $streetData['count'] . "</td>
                <td class=\"compact-percentage\">" . $percentage . "%</td>
            </tr>";
        }
        
        echo "</table>";
        echo "</td>";
        echo "</tr>";
        
        echo "<tr><td colspan=\"13\" style=\"height: 10px;\"></td></tr>";
    }
    
    // ROLE DISTRIBUTION - COMPACT LAYOUT
    if (!empty($roleDistribution)) {
        echo "<tr><td colspan=\"13\" class=\"stats-header\">ROLE DISTRIBUTION</td></tr>";
        
        // Create two columns for role distribution
        $roleHalfCount = ceil(count($roleDistribution) / 2);
        
        echo "<tr class=\"stats-row\">";
        echo "<td colspan=\"6\" style=\"vertical-align: top;\">";
        echo "<table width=\"100%\" cellpadding=\"2\" cellspacing=\"0\">";
        
        // Column headers for first column
        echo "<tr>
            <td class=\"column-header\">Role Name</td>
            <td class=\"column-header\">Frequency (F)</td>
            <td class=\"column-header\">Percentage</td>
        </tr>";
        
        // First column data
        for ($i = 0; $i < $roleHalfCount; $i++) {
            $roleData = $roleDistribution[$i];
            $percentage = $totalUsers > 0 ? round(($roleData['count'] / $totalUsers) * 100, 1) : 0;
            $roleName = $roleData['role'] === 'resident' ? 'Resident' : ucfirst(str_replace('_', ' ', $roleData['role']));
            
            echo "<tr>
                <td class=\"compact-label\">" . $roleName . "</td>
                <td class=\"compact-value\">" . $roleData['count'] . "</td>
                <td class=\"compact-percentage\">" . $percentage . "%</td>
            </tr>";
        }
        
        echo "</table>";
        echo "</td>";
        
        echo "<td colspan=\"7\" style=\"vertical-align: top;\">";
        echo "<table width=\"100%\" cellpadding=\"2\" cellspacing=\"0\">";
        
        // Column headers for second column
        echo "<tr>
            <td class=\"column-header\">Role Name</td>
            <td class=\"column-header\">Frequency (F)</td>
            <td class=\"column-header\">Percentage</td>
        </tr>";
        
        // Second column data
        for ($i = $roleHalfCount; $i < count($roleDistribution); $i++) {
            $roleData = $roleDistribution[$i];
            $percentage = $totalUsers > 0 ? round(($roleData['count'] / $totalUsers) * 100, 1) : 0;
            $roleName = $roleData['role'] === 'resident' ? 'Resident' : ucfirst(str_replace('_', ' ', $roleData['role']));
            
            echo "<tr>
                <td class=\"compact-label\">" . $roleName . "</td>
                <td class=\"compact-value\">" . $roleData['count'] . "</td>
                <td class=\"compact-percentage\">" . $percentage . "%</td>
            </tr>";
        }
        
        echo "</table>";
        echo "</td>";
        echo "</tr>";
        
        echo "<tr><td colspan=\"13\" style=\"height: 10px;\"></td></tr>";
    }
    
    // User Data Header
    echo "<tr><td colspan=\"13\" style=\"background-color: #f39c12; color: white; font-weight: bold; padding: 8px; text-align: center; border: 1px solid #e67e22;\">
        USER DATABASE
    </td></tr>";
    
    echo "<tr class=\"user-header\">
        <th>#</th>
        <th>Surname</th>
        <th>First Name</th>
        <th>Middle Name</th>
        <th>Suffix</th>
        <th>Street</th>
        <th>Full Address</th>
        <th>Role</th>
        <th>Email</th>
        <th>Contact Number</th>
        <th>Sex</th>
        <th>Birthdate</th>
        <th>Date Registered</th>
    </tr>";
    
    // User Data Rows
    foreach ($userData as $index => $user) {
        $rowClass = $index % 2 === 0 ? 'user-row' : 'user-alt';
        
        // Build full address
        $street = $user['street'] ?? '';
        $brgy = $user['brgy_name'] ?? '';
        $municipality = $user['municipality'] ?? '';
        $province = $user['province'] ?? '';
        
        $fullAddress = trim($street);
        if ($brgy) $fullAddress .= ($fullAddress ? ', ' : '') . $brgy;
        if ($municipality) $fullAddress .= ($fullAddress ? ', ' : '') . $municipality;
        if ($province) $fullAddress .= ($fullAddress ? ', ' : '') . $province;
        
        echo "<tr class=\"$rowClass\">
            <td class=\"number\">" . ($index + 1) . "</td>
            <td><strong>" . ($user['surname'] ?? '') . "</strong></td>
            <td>" . ($user['first_name'] ?? '') . "</td>
            <td>" . ($user['middle_name'] ?? '') . "</td>
            <td class=\"text-center\">" . ($user['suffix'] ?? '') . "</td>
            <td>" . ($user['street'] ?? '') . "</td>
            <td>" . $fullAddress . "</td>
            <td class=\"text-center\">" . ($user['role'] === 'resident' ? 'Resident' : ucfirst(str_replace('_', ' ', $user['role']))) . "</td>
            <td>" . ($user['email'] ?? '') . "</td>
            <td class=\"text-center\">" . ($user['contact_number'] ?? '') . "</td>
            <td class=\"text-center\">" . ($user['sex'] ?? '') . "</td>
            <td class=\"text-center\">" . ($user['birthdate'] ?? '') . "</td>
            <td class=\"text-center\">" . ($user['date_registered'] ?? '') . "</td>
        </tr>";
    }
    
    // Footer
    echo "<tr><td colspan=\"13\" class=\"header\" style=\"text-align: center; font-size: 10px;\">
        Generated by " . $barangayName . " User Management System " . date('F j, Y') . "
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