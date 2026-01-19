<?php
// Early POST handlers: return clean responses for AJAX (no HTML before echo)
require_once '../../includes/config.php';
require_once '../../includes/email_notif.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_template_roles') {
        $templateRoles = [
            'captain' => $_POST['captain'] ?? null,
            'secretary' => $_POST['secretary'] ?? null,
            'treasurer' => $_POST['treasurer'] ?? null,
            'sk_chairman' => $_POST['sk_chairman'] ?? null,
            'councilors' => $_POST['councilors'] ?? []
        ];
        // Clear unique roles
        $pdo->query("DELETE FROM user_roles WHERE role IN ('captain','secretary')");

        foreach (['captain','secretary'] as $role) {
            if (!empty($templateRoles[$role])) {
                $pdo->prepare("INSERT INTO user_roles (user_id, role, role_desc) VALUES (?,?,?)")
                    ->execute([$templateRoles[$role], $role, ucfirst(str_replace('_',' ',$role))]);
            }
        }

        foreach (['treasurer','sk_chairman'] as $r) {
            if (!empty($templateRoles[$r])) {
                $pdo->prepare("REPLACE INTO user_roles (user_id, role, role_desc) VALUES (?,?,?)")
                    ->execute([$templateRoles[$r], $r, ucfirst(str_replace('_',' ',$r))]);
            }
        }

        $pdo->query("DELETE FROM user_roles WHERE role = 'councilor'");
        foreach ($templateRoles['councilors'] as $uid) {
            $pdo->prepare("INSERT INTO user_roles (user_id, role, role_desc) VALUES (?, 'councilor', 'Barangay Councilor')")
                ->execute([$uid]);
        }

        echo 'success';
        exit;
    }

    if ($action === 'change_role') {
        $user_id = (int) ($_POST['user_id'] ?? 0);
        $new_role = trim($_POST['role'] ?? '');

        $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $old_role = $stmt->fetchColumn() ?: 'Resident';

        if ($old_role) {
            $stmt = $pdo->prepare("UPDATE user_roles SET role = ? WHERE user_id = ?");
            $stmt->execute([$new_role, $user_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (?, ?)");
            $stmt->execute([$user_id, $new_role]);
        }

        if (strcasecmp($old_role, $new_role) !== 0 && function_exists('logActivity')) {
            logActivity($_SESSION['user_id'], "Changed role", [
                'target_user_id' => $user_id,
                'old_role' => $old_role,
                'new_role' => $new_role
            ]);
        }

        echo 'success';
        exit;
    }

    if ($action === 'verify_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid user']); exit; }

        // Update user status
        $stmt = $pdo->prepare("UPDATE users SET status = 'verified', remarks = NULL WHERE user_id = ?");
        $stmt->execute([$user_id]);

        // Ensure role exists (default resident)
        $pdo->prepare("INSERT INTO user_roles (user_id, role, role_desc)
                       VALUES (?, 'resident', 'Verified resident user')
                       ON DUPLICATE KEY UPDATE role='resident', role_desc='Verified resident user'")
            ->execute([$user_id]);

        if (function_exists('logActivity')) {
            logActivity($_SESSION['user_id'], 'approve user', ['target_user_id' => $user_id]);
        }

        // Email notify if available
        $stmtUser = $pdo->prepare("SELECT u.first_name, u.surname, e.email FROM users u
                LEFT JOIN account a ON u.user_id = a.user_id
                LEFT JOIN email e ON a.email_id = e.email_id
                WHERE u.user_id = ?");
        $stmtUser->execute([$user_id]);
        $ud = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if ($ud && !empty($ud['email'])) {
            $to = trim($ud['email']);
            $name = trim(($ud['first_name'] ?? '').' '.($ud['surname'] ?? ''));
            try { @sendAccountStatusEmail($to, $name, 'verified', null); } catch (\Throwable $e) { /* ignore */ }
        }

        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'reject_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        if ($user_id <= 0 || $remarks === '') { echo json_encode(['success'=>false,'message'=>'Missing data']); exit; }

        $stmt = $pdo->prepare("UPDATE users SET status = 'rejected', remarks = ? WHERE user_id = ?");
        $stmt->execute([$remarks, $user_id]);

        if (function_exists('logActivity')) {
            logActivity($_SESSION['user_id'], 'reject user', ['target_user_id'=>$user_id,'remarks'=>$remarks]);
        }

        $stmtUser = $pdo->prepare("SELECT u.first_name, u.surname, e.email FROM users u
                LEFT JOIN account a ON u.user_id = a.user_id
                LEFT JOIN email e ON a.email_id = e.email_id
                WHERE u.user_id = ?");
        $stmtUser->execute([$user_id]);
        $ud = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if ($ud && !empty($ud['email'])) {
            $to = trim($ud['email']);
            $name = trim(($ud['first_name'] ?? '').' '.($ud['surname'] ?? ''));
            try { @sendAccountStatusEmail($to, $name, 'rejected', $remarks); } catch (\Throwable $e) { /* ignore */ }
        }

        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'update_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id <= 0) { echo 'invalid'; exit; }

        $fields = [];
        $params = [];

        $map = [
            'first_name'   => 'first_name',
            'middle_name'  => 'middle_name',
            'surname'      => 'surname',
            'suffix'       => 'suffix',
            'street'       => 'street',
            'birthdate'    => 'birthdate'
        ];

        foreach ($map as $postKey => $col) {
            if (isset($_POST[$postKey])) {
                $val = trim($_POST[$postKey]);
                if ($postKey === 'birthdate') {
                    if ($val === '') {
                        $val = null;
                    } else {
                        $ts = strtotime($val);
                        $val = $ts ? date('Y-m-d', $ts) : null;
                    }
                }
                $fields[] = "$col = ?";
                $params[] = $val;
            }
        }

        if (empty($fields)) { echo 'nochanges'; exit; }

        $params[] = $user_id;
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute($params);

        if ($ok && function_exists('logActivity')) {
            logActivity($_SESSION['user_id'], 'update user info', [ 'target_user_id' => $user_id ]);
        }

        if ($ok) {
            echo 'success';
        } else {
            $err = $stmt->errorInfo();
            echo 'error:' . ($err[2] ?? 'unknown');
        }
        exit;
    }
}
?>

    <!-- 
    KUNG MAPAPANSIN NYO KUNG BAKIT MAY CHECK BOXES SA TABLE HEADERS AT EACH ROWS
    PLAN KO UPDATE UNG MULTI ACTIONS PARA MAG APPLY SA MGA SELECTED USERS
    EFFICIENTLY , MULTI MANAGE IDK PARA GOOD UX RIN XD 

    FOR SAMPLE I CAN SELECT MULTIPLE USERS AND CHANGE THEIR ROLES IN BULK

    ⠀⠀⠀⠀⠀⠀⠀⠀⠀⡀⠤⠀⠄⢀⠀⠀⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⠀⡠⢧⣾⣷⣿⣿⣵⣿⣄⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⢰⢱⡿⠟⠛⠛⠉⠙⢿⣿⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⢸⣿⡇⣤⣤⡀⢠⡄⣸⢿⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⢨⡼⡇⠈⠉⣡⠀⠉⢈⣞⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⠈⠱⢢⡄⠀⣬⣤⠀⣾⠋⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⠀⢀⡎⡇⠀⠈⣿⢻⡋⠀⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⣀⣤⣶⣿⣧⢃⠀⠠⣶⣿⢻⣶⣤⣤⣀⠀⠀⠀
    ⢀⣤⣶⣿⣿⣿⣿⣿⣿⡤⠱⢀⣹⠋⠈⣿⣿⣿⣿⣿⣦⠀
    ⣾⣿⣿⣿⣿⡿⢛⣿⣿⣧⣔⣂⣄⠠⣴⣿⣿⣿⣿⣿⣿⡇
    ⡿⣿⣿⣿⣿⣳⠫⠈⣙⣻⡧⠔⢺⣛⣿⣿⣿⣿⣿⣿⣿⡇
    ⣿⣿⣿⣿⣿⠄⠀⢀⣨⣿⡯⠭⢭⣶⣿⣿⣿⣿⣿⣿⣿⡇
    -->


    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <?php include 'header.php'; ?>
    <!-- template thing -->
    





    <?php
    // Fetch user stats
    $user_stats = [
        'total' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'verified' => $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'verified'")->fetchColumn(),
        'pending' => $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn(),

        'admins' => $pdo->query("SELECT COUNT(*) FROM user_roles WHERE role = 'Admin'")->fetchColumn(),
        'secretaries' => $pdo->query("SELECT COUNT(*) FROM user_roles WHERE role = 'Secretary'")->fetchColumn(),
        'captains' => $pdo->query("SELECT COUNT(*) FROM user_roles WHERE role = 'Captain'")->fetchColumn(),
        'residents' => $pdo->query("SELECT COUNT(*) FROM user_roles WHERE role = 'Resident'")->fetchColumn()
    ];

    // Read filters from query
    $filterName = trim($_GET['name'] ?? '');
    $filterRoles = array_map('strtolower', (array)($_GET['roles'] ?? []));
    $filterStatuses = array_map('strtolower', (array)($_GET['statuses'] ?? []));
    $filterFrom = $_GET['df'] ?? '';
    $filterTo = $_GET['dt'] ?? '';

    $whereParts = [];
    $bind = [];

    if ($filterName !== '') {
        $whereParts[] = "(CONCAT_WS(' ', u.first_name, u.middle_name, u.surname, u.suffix) LIKE ? OR e.email LIKE ?)";
        $bind[] = "%$filterName%";
        $bind[] = "%$filterName%";
    }
    if (!empty($filterRoles)) {
        $ph = implode(',', array_fill(0, count($filterRoles), '?'));
        $whereParts[] = "COALESCE(r.role,'resident') IN ($ph)";
        foreach ($filterRoles as $rr) { $bind[] = $rr; }
    }
    if (!empty($filterStatuses)) {
        $ph = implode(',', array_fill(0, count($filterStatuses), '?'));
        $whereParts[] = "LOWER(u.status) IN ($ph)";
        foreach ($filterStatuses as $ss) { $bind[] = $ss; }
    }
    if ($filterFrom !== '') { $whereParts[] = "u.date_registered >= ?"; $bind[] = $filterFrom . ' 00:00:00'; }
    if ($filterTo !== '') { $whereParts[] = "u.date_registered <= ?"; $bind[] = $filterTo . ' 23:59:59'; }

    $whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // Build filter query string for pagination/links
    $filterQuery = http_build_query([
        'name' => $filterName,
        'roles' => $filterRoles,
        'statuses' => $filterStatuses,
        'df' => $filterFrom,
        'dt' => $filterTo,
    ]);
    $filterQueryStr = $filterQuery ? ('&' . $filterQuery) : '';

    // Pagination setup
    $entriesPerPage = isset($_GET['entries']) ? (int)$_GET['entries'] : 10;
    $currentPage = isset($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;

    // Count total users (with filters)
    $countSql = "
        SELECT COUNT(*)
        FROM users u
        LEFT JOIN account a ON a.user_id = u.user_id
        LEFT JOIN email e ON e.email_id = a.email_id
        LEFT JOIN (
            SELECT user_id, SUBSTRING_INDEX(GROUP_CONCAT(role ORDER BY FIELD(role,'admin','captain','secretary','treasurer','sk_chairman','councilor','resident')), ',', 1) AS role
            FROM user_roles
            GROUP BY user_id
        ) r ON r.user_id = u.user_id
        $whereSql
    ";
    $countStmt = $pdo->prepare($countSql);
    foreach ($bind as $i => $v) { $countStmt->bindValue($i+1, $v); }
    $countStmt->execute();
    $totalUsers = (int)$countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalUsers / $entriesPerPage));
    $offset = ($currentPage - 1) * $entriesPerPage;

    // Fetch users with pagination (with filters)
    $sql = "
        SELECT 
            u.user_id,
            u.first_name,
            u.middle_name,
            u.surname,
            u.suffix,
            u.sex,
            u.birthdate,
            u.contact_number,
            u.street,
            u.status,
            u.date_registered,
            u.profile_picture,
            e.email,
            COALESCE(r.role, 'resident') AS role,
            ac.brgy_name,
            ac.municipality,
            ac.province,
            iv.id_type,
            iv.front_img AS front_id,
            iv.back_img AS back_id
        FROM users u
        LEFT JOIN account a ON a.user_id = u.user_id
        LEFT JOIN email e ON e.email_id = a.email_id
        LEFT JOIN (
            SELECT user_id, SUBSTRING_INDEX(GROUP_CONCAT(role ORDER BY FIELD(role,'admin','captain','secretary','treasurer','sk_chairman','councilor','resident')), ',', 1) AS role
            FROM user_roles
            GROUP BY user_id
        ) r ON r.user_id = u.user_id
        LEFT JOIN address_config ac ON u.address_id = ac.address_id
        LEFT JOIN id_verification iv ON u.user_id = iv.user_id
        $whereSql
        ORDER BY u.date_registered DESC
        LIMIT ?, ?
    ";
    $stmt = $pdo->prepare($sql);
    // Bind filters
    foreach ($bind as $i => $v) { $stmt->bindValue($i+1, $v); }
    // Bind offset/limit
    $stmt->bindValue(count($bind)+1, (int)$offset, PDO::PARAM_INT);
    $stmt->bindValue(count($bind)+2, (int)$entriesPerPage, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all users for template modal (without pagination)
    $allUsersStmt = $pdo->query("
        SELECT u.user_id, u.first_name, u.surname, COALESCE(r.role, 'resident') AS role
        FROM users u
        LEFT JOIN (
            SELECT user_id, SUBSTRING_INDEX(GROUP_CONCAT(role ORDER BY FIELD(role,'admin','captain','secretary','treasurer','sk_chairman','councilor','resident')), ',', 1) AS role
            FROM user_roles
            GROUP BY user_id
        ) r ON r.user_id = u.user_id
        ORDER BY u.first_name
    ");
        // Fetch all users for template modal (without pagination)
        $allUsersStmt = $pdo->query("
            SELECT u.user_id, u.first_name, u.surname, COALESCE(r.role, 'resident') AS role
            FROM users u
            LEFT JOIN (
                SELECT user_id, SUBSTRING_INDEX(GROUP_CONCAT(role ORDER BY FIELD(role,'admin','captain','secretary','treasurer','sk_chairman','councilor','resident')), ',', 1) AS role
                FROM user_roles
                GROUP BY user_id
            ) r ON r.user_id = u.user_id
            ORDER BY u.first_name
        ");
            // SELECT u.user_id, u.first_name, u.surname, COALESCE(r.role, 'resident') AS role
            // FROM users u
            // LEFT JOIN (
            //     SELECT user_id, SUBSTRING_INDEX(GROUP_CONCAT(role ORDER BY FIELD(role,'admin','captain','secretary','treasurer','sk_chairman','councilor','resident')), ',', 1) AS role
            //     FROM user_roles
            //     GROUP BY user_id
            // ) r ON r.user_id = u.user_id
            // ORDER BY u.first_name
        // ");
        // SELECT u.user_id, u.first_name, u.surname, COALESCE(ur.role, 'resident') AS role
        // FROM users u
        // LEFT JOIN user_roles ur ON ur.user_id = u.user_id
        // ORDER BY u.first_name
    // ");
    $allUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <style>
    /* Enable scrolling for body */
    body {
        overflow-y: auto !important;
        overflow-x: hidden;
    }

    /* Table container aah */
    .card,
    .card-header {
        overflow: visible !important;
        position: relative;
    }
    .table-responsive {
        overflow-x: auto !important;
        overflow-y: visible !important;
        position: relative;
    }
    .table td, .table th {
        vertical-align: middle;
        white-space: nowrap;
        padding: 0.5rem 0.75rem;
        font-size: 0.9rem;
    }
    .table td img {
        width: 35px;
        height: 35px;
        object-fit: cover;
    }
    .table td span.badge {
        display: inline-block;
        min-width: 60px;
        text-align: center;
    }
    .table td select.form-select-sm {
        width: 120px;
    }
    .table thead th {
        position: relative;
        z-index: 5;
        white-space: nowrap;
    }

    /* column filter sevtion */
    .filter-header {
        align-items: center;
        gap: 6px; /* space between button and title */
        position: relative;
    }

    .filter-btn {
        border: 1px solid #dee2e6;
        background-color: transparent;
        background-repeat: no-repeat;
        color: #495057;
        padding: 3px 6px;
        font-size: 13px;
        line-height: 1;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
        overflow: hidden;
        outline: none;
    }
    .filter-btn:hover {
        color: #000;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .filter-menu {
        display: none;
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 8px 0;
        min-width: 190px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15);
        z-index: 9999;
        animation: dropdownFade 0.15s ease-in-out;
    }
    @keyframes dropdownFade {
        from { opacity: 0; transform: translateY(-5px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .filter-menu label {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        margin: 0;
        font-size: 14px;
        color: #333;
        cursor: pointer;
        transition: background 0.15s;
    }
    .filter-menu label:hover {
        background: #f8f9fa;
    }
    .filter-menu input[type="checkbox"] {
        accent-color: #007bff;
    }
    .dropdown-item {
        display: block;
        width: 100%;
        padding: 6px 12px;
        font-size: 14px;
        color: #333;
        text-align: left;
        border: none;
        background: none;
        cursor: pointer;
        transition: background 0.15s;
    }
    .dropdown-item:hover {
        background: #f8f9fa;
    }

    /* Pagination styling */
    .pagination .page-link {
        border: 1px solid #dee2e6;
        margin: 0 2px;
        border-radius: 4px;
        padding: 0.375rem 0.75rem;
        font-size: 0.9rem;
    }
    .pagination .page-item.active .page-link {
        background-color: #007bff;
        border-color: #007bff;
        color: white;
    }
    .pagination .page-item.disabled .page-link {
        color: #6c757d;
        background-color: #fff;
        border-color: #dee2e6;
    }

    /* Role dropdown: allow menu to escape modal footer without custom positioning */
    #residentInfoModal .modal-content, 
    #residentInfoModal .modal-footer {
        overflow: visible; /* prevent clipping of dropdown */
    }
    /* Custom role menu (replaces broken Bootstrap dropdown) */
    #residentInfoModal .role-menu-wrapper { position: relative; }
    #residentInfoModal .role-menu-toggle i { transition: transform .2s ease; }
    #residentInfoModal .role-menu {
        position: absolute;
        bottom: 42px; /* show above button inside footer */
        left: 0;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: .5rem;
        min-width: 180px;
        padding: 4px 0;
        box-shadow: 0 6px 18px rgba(0,0,0,.15);
        display: none;
        z-index: 1500;
        animation: roleMenuFade .15s ease-in-out;
    }
    #residentInfoModal .role-menu.show { display: block; }
    #residentInfoModal .role-menu a.role-option {
        display: block;
        padding: 6px 14px;
        font-size: 14px;
        color: #333;
        text-decoration: none;
        cursor: pointer;
        transition: background .15s;
    }
    #residentInfoModal .role-menu a.role-option:hover { background: #f8f9fa; }
    @keyframes roleMenuFade { from { opacity:0; transform: translateY(6px);} to { opacity:1; transform: translateY(0);} }

    /* Verify/Reject menu - mirror role menu styling */
    #residentInfoModal .verify-menu-wrapper { position: relative; }
    #residentInfoModal .verify-menu-toggle i { transition: transform .2s ease; }
    #residentInfoModal .verify-menu {
        position: absolute;
        bottom: 42px; /* align above footer */
        left: 0;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: .5rem;
        min-width: 180px;
        padding: 4px 0;
        box-shadow: 0 6px 18px rgba(0,0,0,.15);
        display: none;
        z-index: 1500;
        animation: roleMenuFade .15s ease-in-out;
    }
    #residentInfoModal .verify-menu.show { display: block; }
    #residentInfoModal .verify-menu a.verify-option {
        display: block;
        padding: 6px 14px;
        font-size: 14px;
        color: #333;
        text-decoration: none;
        cursor: pointer;
        transition: background .15s;
    }
    #residentInfoModal .verify-menu a.verify-option:hover { background: #f8f9fa; }





    /* Modal Button Styles */
.analytics-btn {
  position: fixed;
  bottom: 20px;
  right: 20px;
  width: 50px;
  height: 50px;
  border-radius: 50%;
  z-index: 1000;
}

.analytics-btn:hover {
  transform: scale(1.1);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4);
}

.distribution-bars {
  height: 200px;
  display: flex;
  align-items: end;
  gap: 8px;
  padding: 10px 0;
}

.distribution-bar {
  flex: 1;
  text-align: center;
  transition: all 0.3s ease;
  cursor: pointer;
}

.distribution-bar:hover {
  opacity: 0.8;
  transform: scale(1.05);
}

.bar-fill {
  border-radius: 4px 4px 0 0;
  margin: 0 auto;
  transition: height 0.5s ease;
  width: 20px;
  min-height: 5px;
}

.bar-label {
  margin-top: 5px;
  font-size: 0.7rem;
  line-height: 1.2;
}

.bar-count {
  font-weight: bold;
  font-size: 0.75rem;
}

.bar-percentage {
  color: #6c757d;
  font-size: 0.65rem;
}

/* Modal Content Styles */
.modal-xl {
  max-width: 1200px;
}

.modal-header {
  border-bottom: 1px solid #dee2e6;
}

.modal-footer {
  border-top: 1px solid #dee2e6;
}

/* Card Styles for Modal */
.card {
  border: none;
  box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.card-header {
  border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.chart-container {
  position: relative;
  width: 100%;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .modal-xl {
    margin: 0.5rem;
  }
  
  .analytics-btn {
    bottom: 20px;
    right: 20px;
    width: 50px;
    height: 50px;
    font-size: 1rem;
  }
  
  .chart-container {
    height: 150px !important;
  }
}

/* Animation for loading states */
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.modal-content {
  animation: fadeIn 0.3s ease-in-out;
}
    </style>


    <div class="container" style="padding-top: 20px; padding-bottom: 40px;">

    <style>
    .resident-card { border: 1px solid #ddd; border-radius: 12px; padding: 20px; background: #fff; }
    #residentInfoModal .row > .col-5 { font-size: 14px; }
    #residentInfoModal .row > .col-7 { font-size: 14px; font-weight: 600; }
    .resident-section-title { font-size: 14px; font-weight: bold; text-transform: uppercase; color: #555; margin-bottom: 6px; }
    .resident-info { font-size: 15px; font-weight: 600; margin-bottom: 10px; }
    .divider-line { width: 100%; border-bottom: 1px solid #d9d9d9; margin: 12px 0; }
    </style>

    

        <!-- Page Title + Filters Toggle -->
        <?php $filtersActive = ($filterName !== '' || !empty($filterRoles) || !empty($filterStatuses) || $filterFrom !== '' || $filterTo !== ''); ?>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
            <h2 class="mb-2 mb-md-0">User Management</h2>
            <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#userFilterPanel" aria-expanded="<?= $filtersActive ? 'true' : 'false' ?>" aria-controls="userFilterPanel">
                <i class="fa fa-filter me-1"></i> Filter
            </button>
        </div>

        <!-- Inline Filter Panel (collapsible) -->
        <div class="collapse mb-3 <?= $filtersActive ? 'show' : '' ?>" id="userFilterPanel">
            <div class="card card-body shadow-sm">
                <form class="row g-3" onsubmit="event.preventDefault(); applyUserFilters();">
                    <div class="col-12 col-md-6 col-lg-4">
                        <label class="form-label">Name or Email</label>
                        <input type="text" id="filterName" class="form-control" placeholder="Search name or email..." value="<?= htmlspecialchars($filterName) ?>">
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <label class="form-label">Date Registered (From)</label>
                        <input type="date" id="filterDateFrom" class="form-control" value="<?= htmlspecialchars($filterFrom) ?>">
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <label class="form-label">Date Registered (To)</label>
                        <input type="date" id="filterDateTo" class="form-control" value="<?= htmlspecialchars($filterTo) ?>">
                    </div>

                    <div class="col-12 col-lg-6">
                        <label class="form-label">Roles</label>
                        <div class="row g-2">
                            <div class="col-6 col-md-4"><label class="form-check"><input class="form-check-input filter-role" type="checkbox" value="admin" <?= in_array('admin',$filterRoles)?'checked':''; ?>> <span class="form-check-label">Admin</span></label></div>
                            <div class="col-6 col-md-4"><label class="form-check"><input class="form-check-input filter-role" type="checkbox" value="secretary" <?= in_array('secretary',$filterRoles)?'checked':''; ?>> <span class="form-check-label">Secretary</span></label></div>
                            <div class="col-6 col-md-4"><label class="form-check"><input class="form-check-input filter-role" type="checkbox" value="captain" <?= in_array('captain',$filterRoles)?'checked':''; ?>> <span class="form-check-label">Captain</span></label></div>
                            <div class="col-6 col-md-4"><label class="form-check"><input class="form-check-input filter-role" type="checkbox" value="treasurer" <?= in_array('treasurer',$filterRoles)?'checked':''; ?>> <span class="form-check-label">Treasurer</span></label></div>
                            <div class="col-6 col-md-4"><label class="form-check"><input class="form-check-input filter-role" type="checkbox" value="sk_chairman" <?= in_array('sk_chairman',$filterRoles)?'checked':''; ?>> <span class="form-check-label">S.K. Chairman</span></label></div>
                            <div class="col-6 col-md-4"><label class="form-check"><input class="form-check-input filter-role" type="checkbox" value="councilor" <?= in_array('councilor',$filterRoles)?'checked':''; ?>> <span class="form-check-label">Councilor</span></label></div>
                            <div class="col-6 col-md-4"><label class="form-check"><input class="form-check-input filter-role" type="checkbox" value="resident" <?= in_array('resident',$filterRoles)?'checked':''; ?>> <span class="form-check-label">Resident</span></label></div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <label class="form-label">Statuses</label>
                        <div class="row g-2">
                            <div class="col-6 col-md-4"><label class="form-check"><input class="form-check-input filter-status" type="checkbox" value="verified" <?= in_array('verified',$filterStatuses)?'checked':''; ?>> <span class="form-check-label">Verified</span></label></div>
                            <div class="col-6 col-md-4"><label class="form-check"><input class="form-check-input filter-status" type="checkbox" value="pending" <?= in_array('pending',$filterStatuses)?'checked':''; ?>> <span class="form-check-label">Pending</span></label></div>
                             <div class="col-6 col-md-4"><label class="form-check"><input class="form-check-input filter-status" type="checkbox" value="rejected" <?= in_array('rejected',$filterStatuses)?'checked':''; ?>> <span class="form-check-label">Rejected</span></label></div>
                        </div>
                    </div>

                    <div class="col-12 d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary" id="clearFiltersBtn"><i class="fa fa-undo me-1"></i> Reset</button>
                        <button type="submit" class="btn btn-primary" id="applyFiltersBtn"><i class="fa fa-check me-1"></i> Apply</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <h5 class="mb-0 me-3">Users</h5>
                <select id="entriesPerPage" class="form-select form-select-sm" style="width: auto;" onchange="changeEntries()">
                    <option value="10" <?= $entriesPerPage == 10 ? 'selected' : '' ?>>10</option>
                    <option value="25" <?= $entriesPerPage == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= $entriesPerPage == 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $entriesPerPage == 100 ? 'selected' : '' ?>>100</option>
                </select>
            </div>
            <!-- filter button moved above as collapsible toggle -->
        </div>

        <!-- modal template -->
    <div class="modal fade" id="templateRolesModal" tabindex="-1" aria-labelledby="templateRolesModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title" id="templateRolesModalLabel">
          <i class="fa fa-file-text-o me-2"></i>Configure Barangay Template Roles
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="templateRolesForm">
          <div class="row g-3">

            <!-- 🧑 Captain -->
            <div class="col-md-6">
              <label class="form-label fw-bold">Punong Barangay (Captain)</label>
              <select name="captain" class="form-select" required>
                <option value="">-- Select Captain --</option>
                <?php
                $hasCaptain = false;
                foreach ($allUsers as $u) {
                  if ($u['role'] === 'captain') {
                    $hasCaptain = true;
                    break;
                  }
                }
                foreach ($allUsers as $u):
                  if ($hasCaptain) {
                    if ($u['role'] === 'captain' || $u['role'] === 'resident'):
                ?>
                      <option value="<?= $u['user_id'] ?>" <?= $u['role'] === 'captain' ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                      </option>
                <?php
                    endif;
                  } else {
                    if ($u['role'] === 'resident'):
                ?>
                      <option value="<?= $u['user_id'] ?>">
                        <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                      </option>
                <?php
                    endif;
                  }
                endforeach;
                ?>
              </select>
            </div>

            <!-- 📜 Secretary -->
            <div class="col-md-6">
              <label class="form-label fw-bold">Barangay Secretary</label>
              <select name="secretary" class="form-select" required>
                <option value="">-- Select Secretary --</option>
                <?php
                $hasSecretary = false;
                foreach ($allUsers as $u) {
                  if ($u['role'] === 'secretary') {
                    $hasSecretary = true;
                    break;
                  }
                }
                foreach ($allUsers as $u):
                  if ($hasSecretary) {
                    if ($u['role'] === 'secretary' || $u['role'] === 'resident'):
                ?>
                      <option value="<?= $u['user_id'] ?>" <?= $u['role'] === 'secretary' ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                      </option>
                <?php
                    endif;
                  } else {
                    if ($u['role'] === 'resident'):
                ?>
                      <option value="<?= $u['user_id'] ?>">
                        <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                      </option>
                <?php
                    endif;
                  }
                endforeach;
                ?>
              </select>
            </div>

            <!-- 💰 Treasurer -->
            <div class="col-md-6">
              <label class="form-label fw-bold">Barangay Treasurer</label>
              <select name="treasurer" class="form-select" required>
                <option value="">-- Select Treasurer --</option>
                <?php foreach ($allUsers as $u): ?>
                  <?php if (in_array($u['role'], ['resident', 'treasurer'])): ?>
                    <option value="<?= $u['user_id'] ?>" <?= $u['role'] === 'treasurer' ? 'selected' : '' ?>>
                      <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 🧒 SK Chairman -->
            <div class="col-md-6">
              <label class="form-label fw-bold">S.K. Chairman</label>
              <select name="sk_chairman" class="form-select" required>
                <option value="">-- Select SK Chairman --</option>
                <?php foreach ($allUsers as $u): ?>
                  <?php if (in_array($u['role'], ['resident', 'sk_chairman'])): ?>
                    <option value="<?= $u['user_id'] ?>" <?= $u['role'] === 'sk_chairman' ? 'selected' : '' ?>>
                      <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 👥 Councilors -->
            <div class="col-12">
              <label class="form-label fw-bold">Barangay Councilors (7)</label>
              <select name="councilors[]" class="form-select" multiple size="7" required>
                <?php foreach ($allUsers as $u): ?>
                  <?php if (in_array($u['role'], ['resident', 'councilor'])): ?>
                    <option value="<?= $u['user_id'] ?>" <?= $u['role'] === 'councilor' ? 'selected' : '' ?>>
                      <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">Hold Ctrl/Cmd to select multiple.</small>
            </div>

          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success" id="saveTemplateRolesBtn">
          <i class="fa fa-save me-1"></i>Save Template
        </button>
      </div>
    </div>
  </div>
</div>

        

        <!-- COLUMNS -->
        <div class="card-body">
            <div class="table-responsive position-relative">
                <table class="table align-middle" id="usersTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Date Registered</th>
                        <th>Action</th>
                    </tr>
                </thead>
    <!-- CONTENTS -->
                <tbody>
                        <?php foreach ($users as $user):
                            $fullName = htmlspecialchars($user['first_name'] . ' ' . $user['surname']);
                            $email = htmlspecialchars($user['email']);
                            $role = htmlspecialchars($user['role'] ?? 'Not Registered');
                            $status = htmlspecialchars($user['status']);
                            $profile_src = htmlspecialchars($user['profile_picture'] ?? '');
                            if(!empty($user['profile_picture'])){
                                    $profileSrc = 'data:image/png;base64,' . base64_encode($user['profile_picture']);
                            }
                        ?>
                        <tr data-role="<?= $role ?>" data-status="<?= $status ?>" data-name="<?= $fullName ?>" data-date="<?= $user['date_registered'] ?>" data-user-id="<?= $user['user_id'] ?>">
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($user['profile_picture'])): ?>
                                        <img src="<?= $profileSrc ?>" alt="Profile" class="rounded-circle me-3" width="45" height="45" style="object-fit: cover;">
                                    <?php else: ?>
                                        <div class="rounded-circle me-3 bg-secondary d-flex align-items-center justify-content-center class="rounded-circle me-3" width="45" height="45" style="object-fit: cover;">
                                        <i class="bi bi-person-fill text-white" alt="Profile" width="45" height="45" style="object-fit: cover;"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= $fullName ?></strong><br>
                                        <small class="text-muted"><?= $email ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge bg-secondary"><?= ucfirst($role) ?></span></td>
                            <td>
                                <?php $statusClass = ($status === 'verified') ? 'success' : (($status === 'rejected') ? 'danger' : 'warning'); ?>
                                <span class="badge bg-<?= $statusClass ?> status-badge"><?= ucfirst($status) ?></span>
                            </td>
                            <td><?= date('M j, Y', strtotime($user['date_registered'])) ?></td>
                            <td>
                                <?php
                                    // Prepare safe JS payload for modal (reuses structure from verification modal)
                                    $user_for_js = [
                                        'user_id'        => (int)$user['user_id'],
                                        'first_name'     => $user['first_name'] ?? '',
                                        'middle_name'    => $user['middle_name'] ?? '',
                                        'surname'        => $user['surname'] ?? '',
                                        'suffix'         => $user['suffix'] ?? '',
                                        'sex'            => $user['sex'] ?? '',
                                        'birthdate'      => $user['birthdate'] ?? '',
                                        'contact_number' => $user['contact_number'] ?? '',
                                        'street'         => $user['street'] ?? '',
                                        'brgy_name'      => $user['brgy_name'] ?? '',
                                        'municipality'   => $user['municipality'] ?? '',
                                        'province'       => $user['province'] ?? '',
                                        'email'          => $user['email'] ?? '',
                                        'role'           => $user['role'] ?? 'resident',
                                        'status'         => $user['status'] ?? 'pending',
                                        'id_type'        => $user['id_type'] ?? '',
                                        'front_b64'      => !empty($user['front_id']) ? base64_encode($user['front_id']) : '',
                                        'back_b64'       => !empty($user['back_id']) ? base64_encode($user['back_id']) : '',
                                        'date_registered'=> !empty($user['date_registered']) ? date('M j, Y g:i A', strtotime($user['date_registered'])) : ''
                                    ];
                                    $data_user_attr = htmlspecialchars(json_encode($user_for_js), ENT_QUOTES, 'UTF-8');
                                ?>
                                <button class="btn btn-primary btn-sm view-info-btn" data-user='<?= $data_user_attr ?>'>
                                    View Info
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                </table>
            </div>

            <!-- Pagination Section -->
            <div class="d-flex justify-content-between align-items-center mt-2 px-3 pb-3">
                <div class="text-muted" style="font-size: 0.9rem;">
                    Showing <?= min($offset + 1, $totalUsers) ?> to <?= min($offset + $entriesPerPage, $totalUsers) ?> of <?= $totalUsers ?> entries
                </div>
                <nav>
                    <ul class="pagination mb-0">
                        <!-- Previous Button -->
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $currentPage - 1 ?>&entries=<?= $entriesPerPage ?><?= $filterQueryStr ?>" style="color: #6c757d;">Previous</a>
                        </li>
                        
                        <!-- Page Numbers -->
                        <?php 
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $currentPage + 2);
                        
                        for($p = $startPage; $p <= $endPage; $p++): 
                        ?>
                            <li class="page-item <?= $currentPage == $p ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $p ?>&entries=<?= $entriesPerPage ?><?= $filterQueryStr ?>" 
                                   style="<?= $currentPage == $p ? 'background-color: #007bff; border-color: #007bff; color: white;' : 'color: #6c757d;' ?>">
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <!-- Next Button -->
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $currentPage + 1 ?>&entries=<?= $entriesPerPage ?><?= $filterQueryStr ?>" style="color: #6c757d;">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>


        <!-- Resident Info Modal (no verify/reject) -->
        <div class="modal fade" id="residentInfoModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="fas fa-user"></i> Resident Information</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body resident-card">
                        <div class="row">
                            <!-- LEFT: Personal Info -->
                            <div class="col-md-6 col-12 border-md-end pe-md-4 mb-3" style="position: relative;">
                                <button id="editAllBtn" class="btn btn-sm btn-link p-0" style="position: absolute; top: 0; right: 0; z-index: 10;" title="Edit">
                                    <i class="fa fa-pencil"></i>
                                </button>
                                <div class="row mb-2 align-items-start">
                                    <div class="col-5 fw-bold">Name:</div>
                                    <div class="col-7 position-relative">
                                        <div id="nameDisplay">
                                            <span id="dispFirst"></span>
                                            <span id="dispMiddle"></span>
                                            <span id="dispSurname"></span>
                                            <span id="dispSuffix"></span>
                                        </div>
                                        <div id="nameEdit" class="d-none">
                                            <input id="editFirst" class="form-control form-control-sm" placeholder="First">
                                        </div>
                                    </div>
                                </div>

                                <!-- Middle Name (only visible in edit mode) -->
                                <div class="row mb-2 d-none" id="middleRow">
                                    <div class="col-5 fw-bold">Middle Name:</div>
                                    <div class="col-7">
                                        <input id="editMiddle" class="form-control form-control-sm" placeholder="Middle Name">
                                    </div>
                                </div>

                                <!-- Surname (only visible in edit mode) -->
                                <div class="row mb-2 d-none" id="surnameRow">
                                    <div class="col-5 fw-bold">Surname:</div>
                                    <div class="col-7">
                                        <input id="editSurname" class="form-control form-control-sm" placeholder="Surname">
                                    </div>
                                </div>

                                <!-- Suffix (only visible in edit mode) -->
                                <div class="row mb-2 d-none" id="suffixRow">
                                    <div class="col-5 fw-bold">Suffix:</div>
                                    <div class="col-7">
                                        <input id="editSuffix" class="form-control form-control-sm" placeholder="Suffix">
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 fw-bold">Role:</div>
                                    <div class="col-7"><span id="resRoleBadge" class="badge bg-secondary"></span></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 fw-bold">Address:</div>
                                    <div class="col-7 position-relative">
                                        <div id="addressDisplay">
                                            <span id="resStreet"></span><br>
                                            <small id="resBrgy" class="text-muted"></small>
                                        </div>
                                        <div id="addressEdit" class="d-none">
                                            <input id="editStreet" class="form-control form-control-sm" placeholder="Street">
                                        </div>
                                    </div>
                                </div>
                                <hr>
                                <div class="row mb-2">
                                    <div class="col-5 fw-bold">Contact Number:</div>
                                    <div class="col-7" id="resContact"></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 fw-bold">Email:</div>
                                    <div class="col-7" id="resEmail"></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 fw-bold">Sex:</div>
                                    <div class="col-7" id="resSex"></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 fw-bold">Birthdate:</div>
                                    <div class="col-7 position-relative">
                                        <div id="birthdateDisplay"><span id="resBirthdate"></span></div>
                                        <div id="birthdateEdit" class="d-none">
                                            <div class="input-group input-group-sm">
                                                <input type="date" id="editBirthdate" class="form-control form-control-sm">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 fw-bold">Date Registered:</div>
                                    <div class="col-7" id="resRegistered"></div>
                                </div>
                            </div>

                            <!-- RIGHT: ID Type and Images -->
                            <div class="col-md-6 col-12 ps-md-4">
                                <div class="text-center fw-bold mb-2">ID TYPE</div>
                                <div class="text-center mb-3">
                                    <span id="resIDType" class="fw-semibold"></span>
                                </div>
                                <div class="text-center mb-3">
                                    <img id="idFront" class="border rounded w-100" style="height:160px; object-fit:cover; background:#e5e5e5;">
                                    <div class="fw-bold mt-2">Front ID</div>
                                </div>
                                <div class="text-center">
                                    <img id="idBack" class="border rounded w-100" style="height:160px; object-fit:cover; background:#e5e5e5;">
                                    <div class="fw-bold mt-2">Back ID</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" id="manageUserId" />
                    <div class="modal-footer justify-content-between">
                        <div class="role-menu-wrapper d-flex align-items-center gap-2 flex-wrap">
                            <button type="button" class="btn btn-outline-secondary role-menu-toggle" id="roleMenuToggle">
                                Change Role <i class="fa fa-chevron-up ms-1" id="roleMenuIcon"></i>
                            </button>
                            <div class="role-menu" id="roleMenu" aria-hidden="true">
                                <a class="role-option" data-role="resident">Resident</a>
                                <a class="role-option" data-role="secretary">Secretary</a>
                                <a class="role-option" data-role="captain">Captain</a>
                                <a class="role-option" data-role="treasurer">Treasurer</a>
                                <a class="role-option" data-role="sk_chairman">S.K. Chairman</a>
                                <a class="role-option" data-role="councilor">Councilor</a>
                                <a class="role-option" data-role="admin">Admin</a>
                            </div>

                            <!-- Verify/Reject custom dropdown (same style as Change Role) -->
                            <div class="verify-menu-wrapper" id="verifyActionGroup">
                                <button type="button" class="btn btn-outline-primary verify-menu-toggle" id="verifyMenuToggle">
                                    Verify / Reject <i class="fa fa-chevron-up ms-1" id="verifyMenuIcon"></i>
                                </button>
                                <div class="verify-menu" id="verifyMenu" aria-hidden="true">
                                    <a class="verify-option" data-action="verify">Verify</a>
                                    <hr class="dropdown-divider m-0">
                                    <a class="verify-option text-danger" data-action="reject">Reject…</a>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-success d-none" id="saveUserChanges">Save</button>
                            <button type="button" class="btn btn-outline-secondary d-none" id="cancelUserChanges">Cancel</button>
                            <button type="button" class="btn btn-secondary" id="closeUserModalBtn" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>









<!-- MODAL -->
<!-- Analytics Button -->
<button class="btn btn-dark shadow-lg analytics-btn" data-bs-toggle="modal" data-bs-target="#analyticsModal" title="User Analytics Report">
    <i class="fa fa-bar-chart"></i>
</button>

<!-- User Analytics Modal -->
<div class="modal fade" id="analyticsModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="fa fa-users"></i> User Management Analytics</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <!-- Filter Controls -->
        <div class="row mb-3">
          <div class="col-md-12">
            <div class="card shadow-sm border-0">
              <div class="card-body py-2">
                <div class="row align-items-center">
                  <div class="col-md-3">
                    <label class="form-label fw-semibold mb-1">Filter by Role</label>
                    <select class="form-select form-select-sm" id="roleFilter">
                      <option value="all">All Roles</option>
                      <option value="resident">Resident</option>
                      <option value="captain">Captain</option>
                      <option value="secretary">Secretary</option>
                      <option value="admin">Admin</option>
                      <option value="treasurer">Treasurer</option>
                      <option value="sk_chairman">S.K. Chairman</option>
                      <option value="councilor">Councilor</option>
                    </select>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label fw-semibold mb-1">Filter by Street</label>
                    <div class="input-group input-group-sm">
                      <input type="text" class="form-control" id="streetSearch" placeholder="Type street name to search...">
                      <button class="btn btn-outline-secondary" type="button" id="clearStreetSearch">
                        <i class="fa fa-times"></i>
                      </button>
                    </div>
                    <div class="mt-1" id="streetResults" style="max-height: 120px; overflow-y: auto; display: none;">
                      <!-- Street search results will appear here -->
                    </div>
                  </div>
                  <div class="col-md-4 text-end">
                    <button class="btn btn-success btn-sm" id="exportUserExcel">
                      <i class="fa fa-file-excel-o"></i> Export Users to Excel
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- User Statistics Cards -->
        <div class="row g-3 mb-4">
          <!-- Total Users -->
          <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-primary text-white py-2">
                <h6 class="mb-0"><i class="fa fa-users me-2"></i> Total Users</h6>
              </div>
              <div class="card-body p-3 text-center">
                <h2 class="text-primary mb-1" id="totalUsers">0</h2>
                <small class="text-muted">All registered users</small>
              </div>
            </div>
          </div>

          <!-- Verified Users -->
          <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-success text-white py-2">
                <h6 class="mb-0"><i class="fa fa-check-circle me-2"></i> Verified</h6>
              </div>
              <div class="card-body p-3 text-center">
                <h2 class="text-success mb-1" id="verifiedUsers">0</h2>
                <small class="text-muted">Verified accounts</small>
              </div>
            </div>
          </div>

          <!-- Pending Users -->
          <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-warning text-dark py-2">
                <h6 class="mb-0"><i class="fa fa-clock-o me-2"></i> Pending</h6>
              </div>
              <div class="card-body p-3 text-center">
                <h2 class="text-warning mb-1" id="pendingUsers">0</h2>
                <small class="text-muted">Pending verification</small>
              </div>
            </div>
          </div>

          <!-- Admin Users -->
          <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-danger text-white py-2">
                <h6 class="mb-0"><i class="fa fa-shield me-2"></i> Admins</h6>
              </div>
              <div class="card-body p-3 text-center">
                <h2 class="text-danger mb-1" id="adminUsers">0</h2>
                <small class="text-muted">Administrator accounts</small>
              </div>
            </div>
          </div>
        </div>

        <!-- Role Distribution -->
        <div class="row g-3">
          <!-- Role Breakdown -->
          <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-info text-white py-2">
                <h6 class="mb-0"><i class="fa fa-pie-chart me-2"></i> Role Distribution</h6>
              </div>
              <div class="card-body">
                <div id="roleDistribution">
                  <div class="text-center text-muted py-4">
                    <i class="fa fa-spinner fa-spin"></i> Loading role data...
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Street Distribution -->
          <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-secondary text-white py-2">
                <h6 class="mb-0"><i class="fa fa-map-marker me-2"></i> Street Distribution</h6>
              </div>
              <div class="card-body">
                <div id="streetDistribution">
                  <div class="text-center text-muted py-4">
                    <i class="fa fa-spinner fa-spin"></i> Loading street data...
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


    <script>
    // Preserve filter params in entries change
    const FILTER_QS = <?= json_encode($filterQueryStr) ?>;

    // Change entries per page
    function changeEntries() {
        const entries = document.getElementById('entriesPerPage').value;
        window.location.href = '?page=1&entries=' + entries + (FILTER_QS || '');
    }
    // Removed legacy column filters, sorting, and selection checkboxes.

    // Unified filtering logic (modal-based)
    function parseMysqlDate(val) {
        if (!val) return null;
        // Replace space with 'T' to ensure consistent parsing
        const iso = val.replace(' ', 'T');
        const d = new Date(iso);
        return isNaN(d.getTime()) ? null : d;
    }

    function applyUserFilters() {
        const name = (document.getElementById('filterName')?.value || '').trim();
        const roles = Array.from(document.querySelectorAll('.filter-role:checked')).map(cb => cb.value);
        const statuses = Array.from(document.querySelectorAll('.filter-status:checked')).map(cb => cb.value);
        const df = document.getElementById('filterDateFrom')?.value || '';
        const dt = document.getElementById('filterDateTo')?.value || '';

        const params = new URLSearchParams(window.location.search);
        params.set('page','1');
        const entries = document.getElementById('entriesPerPage')?.value || '10';
        params.set('entries', entries);
        params.delete('name');
        params.delete('df');
        params.delete('dt');
        params.delete('roles[]');
        params.delete('statuses[]');
        if (name) params.set('name', name);
        if (df) params.set('df', df);
        if (dt) params.set('dt', dt);
        roles.forEach(r => params.append('roles[]', r));
        statuses.forEach(s => params.append('statuses[]', s));

        window.location.search = params.toString();
    }

    function clearUserFilters() {
        const entries = document.getElementById('entriesPerPage')?.value || '10';
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('page','1');
        url.searchParams.set('entries', entries);
        window.location.href = url.toString();
    }

    document.getElementById('applyFiltersBtn')?.addEventListener('click', applyUserFilters);
    document.getElementById('clearFiltersBtn')?.addEventListener('click', clearUserFilters);


    // CHANGE ROLE with SweetAlert confirmation
    function changeRole(userId, newRole) {
        const pretty = newRole.replace(/_/g,' ').replace(/^\w/, c => c.toUpperCase());

        const proceed = () => {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'change_role',
                    user_id: userId,
                    role: newRole
                })
            })
            .then(res => res.text())
            .then(txt => {
                if (txt.trim() === 'success') {
                    // Update the table row badge and dataset
                    const row = document.querySelector(`#usersTable tbody tr[data-role][data-user-id='${userId}']`);
                    if (row) {
                        row.dataset.role = newRole;
                        // Role column now at 2nd position after removing checkbox column
                        const badge = row.querySelector('td:nth-child(2) span.badge');
                        if (badge) badge.textContent = pretty;
                    }
                    // Update the modal badge if visible
                    const modalBadge = document.getElementById('resRoleBadge');
                    if (modalBadge) modalBadge.textContent = pretty;

                    if (window.Swal) {
                        Swal.fire({ icon: 'success', title: 'Role updated', timer: 1200, showConfirmButton: false });
                    }
                } else {
                    if (window.Swal) {
                        Swal.fire({ icon: 'error', title: 'Update failed', text: 'Unable to change role. Please try again.' });
                    } else {
                        alert('Unable to change role.');
                    }
                }
            })
            .catch(() => {
                if (window.Swal) Swal.fire({ icon: 'error', title: 'Network error' });
            });
        };

        if (window.Swal) {
            Swal.fire({
                icon: 'question',
                title: 'Change role?',
                text: `Are you sure you want to change this user's role to "${pretty}"?`,
                showCancelButton: true,
                confirmButtonText: 'Change Role',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then(result => { if (result.isConfirmed) proceed(); });
        } else {
            if (confirm(`Are you sure you want to change this user's role to "${pretty}"?`)) proceed();
        }
    }



    document.getElementById('saveTemplateRolesBtn').addEventListener('click', () => {
    const form = document.getElementById('templateRolesForm');
    const formData = new FormData(form);
    formData.append('action', 'save_template_roles');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.text())
    .then(res => {
        if (res.trim() === 'success') {
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: 'Template roles saved successfully!'
        }).then(() => {
            bootstrap.Modal.getInstance(document.getElementById('templateRolesModal')).hide();
            location.reload();
        });
        } else {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Something went wrong while saving.'
        });
        }
    })
    .catch(err => console.error(err));
    });


    </script>



        <script>
        // Wire up View Info buttons to populate and open the modal
        (function(){
            document.addEventListener('click', function(e){
                const btn = e.target.closest('.view-info-btn');
                if (!btn) return;
                try {
                    const u = JSON.parse(btn.getAttribute('data-user'));

                    document.getElementById('resIDType').textContent = u.id_type || '';
                    document.getElementById('idFront').src = u.front_b64 ? `data:image/jpeg;base64,${u.front_b64}` : '';
                    document.getElementById('idBack').src  = u.back_b64  ? `data:image/jpeg;base64,${u.back_b64}`  : '';

                    // Fill display name pieces
                    document.getElementById('dispFirst').textContent   = (u.first_name || '').trim();
                    document.getElementById('dispMiddle').textContent  = (u.middle_name || '').trim();
                    document.getElementById('dispSurname').textContent = (u.surname || '').trim();
                    document.getElementById('dispSuffix').textContent  = (u.suffix || '').trim();

                    // Prefill edit inputs
                    document.getElementById('editFirst').value   = u.first_name || '';
                    document.getElementById('editMiddle').value  = u.middle_name || '';
                    document.getElementById('editSurname').value = u.surname || '';
                    document.getElementById('editSuffix').value  = u.suffix || '';

                    document.getElementById('resStreet').textContent = u.street || '';
                    const brgyParts = [u.brgy_name, u.municipality, u.province].filter(Boolean);
                    document.getElementById('resBrgy').textContent = brgyParts.join(', ');

                    document.getElementById('editStreet').value = u.street || '';

                    document.getElementById('resContact').textContent = u.contact_number || '';
                    document.getElementById('resEmail').textContent   = u.email || '';

                    if ((u.sex || '').toLowerCase() === 'male') {
                        document.getElementById('resSex').innerHTML = '<span class="text-primary fw-bold">♂ Male</span>';
                    } else if ((u.sex || '').toLowerCase() === 'female') {
                        document.getElementById('resSex').innerHTML = '<span class="text-danger fw-bold">♀ Female</span>';
                    } else {
                        document.getElementById('resSex').textContent = u.sex || '';
                    }

                    document.getElementById('resBirthdate').textContent = u.birthdate
                        ? (new Date(u.birthdate).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }))
                        : '';
                    document.getElementById('editBirthdate').value = u.birthdate ? new Date(u.birthdate).toISOString().slice(0,10) : '';
                    document.getElementById('resRegistered').textContent = u.date_registered || '';

                    // Store current user id and role for role management
                    const manageUserId = document.getElementById('manageUserId');
                    if (manageUserId) manageUserId.value = u.user_id || '';
                    const roleBadge = document.getElementById('resRoleBadge');
                    if (roleBadge) roleBadge.textContent = (u.role || 'resident').replace(/_/g,' ').replace(/^\w/, c => c.toUpperCase());

                    // Reset edit states and buttons
                    ['name','address','birthdate'].forEach(t => {
                        const edit = document.getElementById(t+'Edit');
                        const disp = document.getElementById(t+'Display');
                        if (edit) edit.classList.add('d-none');
                        if (disp) disp.classList.remove('d-none');
                    });
                    ['middleRow','surnameRow','suffixRow'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.classList.add('d-none');
                    });
                    document.getElementById('nameDisplay').classList.remove('d-none');
                    document.getElementById('addressDisplay').classList.remove('d-none');
                    document.getElementById('birthdateDisplay').classList.remove('d-none');
                    document.getElementById('saveUserChanges').classList.add('d-none');
                    document.getElementById('cancelUserChanges').classList.add('d-none');
                    const editBtn = document.getElementById('editAllBtn');
                    if (editBtn) editBtn.classList.remove('d-none');
                    const closeBtn = document.getElementById('closeUserModalBtn');
                    if (closeBtn) closeBtn.classList.remove('d-none');

                    // Show Verify/Reject actions for all statuses (pending, verified, rejected)
                    const actionGroup = document.getElementById('verifyActionGroup');
                    if (actionGroup) {
                        actionGroup.style.display = '';
                    }

                    new bootstrap.Modal(document.getElementById('residentInfoModal')).show();
                } catch (err) {
                    console.error('Failed to open resident modal:', err);
                    if (window.Swal) {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Unable to open resident info.' });
                    }
                }
            });
        })();
        </script>

        <script>
        // Verify / Reject actions in modal + custom dropdown toggle
        (function(){
            function showConfirm(opts){
                if (window.Swal && Swal.fire) return Swal.fire(opts);
                const ok = confirm(opts.text || opts.title || 'Are you sure?');
                return Promise.resolve({ isConfirmed: ok, value: null });
            }
            async function postForm(data){
                const res = await fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data) });
                const text = await res.text();
                if (!res.ok) throw new Error(text || 'Network error');
                try {
                    return JSON.parse(text);
                } catch (e) {
                    // Clean potential HTML errors to a concise message
                    const cleaned = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                    throw new Error(cleaned || 'Invalid server response');
                }
            }

            const reasons = [
                'Incomplete information',
                'Invalid or fake information',
                'Non-residency',
                'Invalid or expired identification',
                'Duplicate registration',
                'Unverified address',
                'Unclear or blurry uploaded documents',
                'System or technical error',
                'Violation of data policy or misuse of system'
            ];

            function updateStatusRow(userId, newStatus){
                const row = document.querySelector(`#usersTable tbody tr[data-user-id='${userId}']`);
                if (!row) return;
                row.dataset.status = newStatus;
                const badge = row.querySelector('td:nth-child(3) span.badge');
                if (badge){
                    badge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                    badge.classList.remove('bg-success','bg-warning','bg-danger','bg-secondary');
                    if (newStatus === 'verified') badge.classList.add('bg-success');
                    else if (newStatus === 'rejected') badge.classList.add('bg-danger');
                    else if (newStatus === 'pending') badge.classList.add('bg-warning');
                    else badge.classList.add('bg-secondary');
                }

                // Keep the View Info payload in sync so future opens reflect new status
                const btn = row.querySelector('.view-info-btn');
                if (btn){
                    try {
                        const payload = JSON.parse(btn.getAttribute('data-user') || '{}');
                        payload.status = newStatus;
                        btn.setAttribute('data-user', JSON.stringify(payload));
                    } catch (_) {}
                }
            }

            // Toggle logic for Verify/Reject custom dropdown
            (function(){
                const modal = document.getElementById('residentInfoModal');
                const toggleBtn = document.getElementById('verifyMenuToggle');
                const menu = document.getElementById('verifyMenu');
                const icon = document.getElementById('verifyMenuIcon');
                function closeMenu(){
                    if(menu && menu.classList.contains('show')){
                        menu.classList.remove('show');
                        menu.setAttribute('aria-hidden','true');
                        if(icon) icon.style.transform = 'rotate(0deg)';
                    }
                }
                function openMenu(){
                    if(menu){
                        menu.classList.add('show');
                        menu.setAttribute('aria-hidden','false');
                        if(icon) icon.style.transform = 'rotate(180deg)';
                    }
                }
                if (toggleBtn && menu) {
                    toggleBtn.addEventListener('click', function(e){
                        e.preventDefault();
                        menu.classList.contains('show') ? closeMenu() : openMenu();
                    });
                    document.addEventListener('click', function(e){
                        if(!e.target.closest('.verify-menu-wrapper')) closeMenu();
                    });
                    if (modal) modal.addEventListener('hide.bs.modal', closeMenu);
                }
            })();

            // Delegate clicks for verify/reject options
            document.addEventListener('click', async function(e){
                const item = e.target.closest('.verify-option');
                if (!item) return;
                e.preventDefault();
                const action = item.dataset.action;
                const userId = document.getElementById('manageUserId')?.value;
                if (!userId) return;

                if (action === 'verify'){
                    const res = await showConfirm({ icon: 'question', title: 'Verify this user?', text: 'This will mark the user as Verified.', showCancelButton: true, confirmButtonText: 'Yes, verify' });
                    if (!res.isConfirmed) return;
                    try {
                        const json = await postForm({ action: 'verify_user', user_id: userId });
                        if (json && json.success){
                            updateStatusRow(userId, 'verified');
                            // Keep Verify/Reject available for verified users
                            const ag = document.getElementById('verifyActionGroup'); if (ag) ag.style.display = '';
                            if (window.Swal && Swal.fire) Swal.fire({ icon: 'success', title: 'Verified', timer: 1200, showConfirmButton: false });
                        } else throw new Error(json?.message || 'Failed to verify');
                    } catch(err){
                        if (window.Swal && Swal.fire) Swal.fire({ icon: 'error', title: 'Error', text: err.message || 'Failed to verify' });
                        else alert(err.message || 'Failed to verify');
                    }
                }

                if (action === 'reject'){
                    const html = `<select id=\"rejSel\" class=\"form-select\">${reasons.map(r=>`<option value=\"${r}\">${r}</option>`).join('')}<option value=\"Others\">Others</option></select>` +
                                 `<textarea id=\"rejTxt\" class=\"form-control mt-2\" placeholder=\"Enter other reason (optional)\"></textarea>`;
                    const res = await showConfirm({ title: 'Reject this user?', html, icon: 'warning', showCancelButton: true, confirmButtonText: 'Reject', preConfirm: ()=>{
                        const s = document.getElementById('rejSel'); const t = document.getElementById('rejTxt');
                        let reason = s ? s.value : '';
                        if (reason === 'Others') reason = (t?.value || '').trim() || 'Others';
                        return reason;
                    } });
                    if (!res.isConfirmed) return;
                    const reason = res.value || 'Rejected';
                    try {
                        const json = await postForm({ action: 'reject_user', user_id: userId, remarks: reason });
                        if (json && json.success){
                            updateStatusRow(userId, 'rejected');
                            // Keep Verify/Reject available for rejected users as requested
                            const ag = document.getElementById('verifyActionGroup'); if (ag) ag.style.display = '';
                            if (window.Swal && Swal.fire) Swal.fire({ icon: 'success', title: 'Rejected', timer: 1200, showConfirmButton: false });
                        } else throw new Error(json?.message || 'Failed to reject');
                    } catch(err){
                        if (window.Swal && Swal.fire) Swal.fire({ icon: 'error', title: 'Error', text: err.message || 'Failed to reject' });
                        else alert(err.message || 'Failed to reject');
                    }
                }
            });
        })();
        </script>

        <script>
        // Handle role change clicks inside the modal dropdown
        document.addEventListener('click', function(e){
            const item = e.target.closest('.role-option');
            if (!item) return;
            
            e.preventDefault();
            
            const role = item.dataset.role;
            const userIdEl = document.getElementById('manageUserId');
            if (!userIdEl || !role) return;
            const userId = userIdEl.value;
            
            if (!userId) {
                console.error('No user ID found');
                return;
            }
            
            // Execute role change
            changeRole(userId, role);
        });

        // Custom role menu toggle logic (replaces Bootstrap dropdown)
        (function(){
            const modal = document.getElementById('residentInfoModal');
            const toggleBtn = document.getElementById('roleMenuToggle');
            const menu = document.getElementById('roleMenu');
            const icon = document.getElementById('roleMenuIcon');
            function closeMenu(){
                if(menu && menu.classList.contains('show')){
                    menu.classList.remove('show');
                    menu.setAttribute('aria-hidden','true');
                    if(icon) icon.style.transform = 'rotate(0deg)';
                }
            }
            function openMenu(){
                if(menu){
                    menu.classList.add('show');
                    menu.setAttribute('aria-hidden','false');
                    if(icon) icon.style.transform = 'rotate(180deg)';
                }
            }
            if (toggleBtn && menu) {
                toggleBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    menu.classList.contains('show') ? closeMenu() : openMenu();
                });
                // Outside click close (within document scope)
                document.addEventListener('click', function(e){
                    if(!e.target.closest('.role-menu-wrapper')) closeMenu();
                });
                // Close when modal hides
                if (modal) modal.addEventListener('hide.bs.modal', closeMenu);
            }
        })();
        </script>

        <script>
        // Inline edit toggles
        let originalFields = null;
        let changeListenersWired = false;

        function setSaveEnabled(enabled) {
            const btn = document.getElementById('saveUserChanges');
            if (btn) { btn.disabled = !enabled; btn.classList.toggle('disabled', !enabled); }
        }

        function collectCurrentFields() {
            return {
                first_name: (document.getElementById('editFirst')?.value || '').trim(),
                middle_name: (document.getElementById('editMiddle')?.value || '').trim(),
                surname: (document.getElementById('editSurname')?.value || '').trim(),
                suffix: (document.getElementById('editSuffix')?.value || '').trim(),
                street: (document.getElementById('editStreet')?.value || '').trim(),
                birthdate: (document.getElementById('editBirthdate')?.value || '')
            };
        }

        function refreshSaveEnabled() {
            if (!originalFields) { setSaveEnabled(false); return; }
            const cur = collectCurrentFields();
            let changed = false;
            for (const k in originalFields) {
                if ((originalFields[k] ?? '') !== (cur[k] ?? '')) { changed = true; break; }
            }
            setSaveEnabled(changed);
        }

        function wireChangeListenersOnce() {
            if (changeListenersWired) return;
            changeListenersWired = true;
            const ids = ['editFirst','editMiddle','editSurname','editSuffix','editStreet'];
            ids.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', refreshSaveEnabled);
            });
            const bd = document.getElementById('editBirthdate');
            if (bd) { bd.addEventListener('change', refreshSaveEnabled); bd.addEventListener('input', refreshSaveEnabled); }
        }

        function showSaveCancel(show) {
            document.getElementById('saveUserChanges').classList.toggle('d-none', !show);
            document.getElementById('cancelUserChanges').classList.toggle('d-none', !show);
            const closeBtn = document.getElementById('closeUserModalBtn');
            if (closeBtn) closeBtn.classList.toggle('d-none', !!show);
            if (show) { setSaveEnabled(false); } else { setSaveEnabled(false); originalFields = null; }
        }

        // Single pencil toggles all fields to edit mode
        document.addEventListener('click', function(e){
            const btn = e.target.closest('#editAllBtn');
            if (!btn) return;
            ['name','address','birthdate'].forEach(t => {
                const disp = document.getElementById(t + 'Display');
                const edit = document.getElementById(t + 'Edit');
                if (disp && edit) { disp.classList.add('d-none'); edit.classList.remove('d-none'); }
            });
            // Show additional name rows in edit mode
            ['middleRow','surnameRow','suffixRow'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.classList.remove('d-none');
            });
            showSaveCancel(true);
            btn.classList.add('d-none');
            // Capture originals and wire listeners
            originalFields = collectCurrentFields();
            wireChangeListenersOnce();
            refreshSaveEnabled();
        });

        document.getElementById('cancelUserChanges').addEventListener('click', function(){
            // Revert all edits
            ['name','address','birthdate'].forEach(t => {
                const disp = document.getElementById(t + 'Display');
                const edit = document.getElementById(t + 'Edit');
                if (disp && edit) { disp.classList.remove('d-none'); edit.classList.add('d-none'); }
            });
            ['middleRow','surnameRow','suffixRow'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.classList.add('d-none');
            });
            showSaveCancel(false);
            const editBtn = document.getElementById('editAllBtn');
            if (editBtn) editBtn.classList.remove('d-none');
        });

        document.getElementById('saveUserChanges').addEventListener('click', function(){
            if (this.disabled) return; // Guard: no save if nothing changed
            const doSave = () => {
                const userId = document.getElementById('manageUserId').value;
                const body = new URLSearchParams({ action: 'update_user', user_id: userId });

                // Collect fields only from visible edit sections
                if (!document.getElementById('nameEdit').classList.contains('d-none')) {
                    body.append('first_name', document.getElementById('editFirst').value.trim());
                    body.append('middle_name', document.getElementById('editMiddle').value.trim());
                    body.append('surname', document.getElementById('editSurname').value.trim());
                    body.append('suffix', document.getElementById('editSuffix').value.trim());
                }
                if (!document.getElementById('addressEdit').classList.contains('d-none')) {
                    body.append('street', document.getElementById('editStreet').value.trim());
                }
                if (!document.getElementById('birthdateEdit').classList.contains('d-none')) {
                    body.append('birthdate', document.getElementById('editBirthdate').value);
                }

                fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                    .then(r => r.text())
                    .then(res => {
                        const txt = res.trim();
                        if (txt === 'success' || txt === 'nochanges') {
                            // Update displays from inputs
                            if (!document.getElementById('nameEdit').classList.contains('d-none')) {
                                document.getElementById('dispFirst').textContent = document.getElementById('editFirst').value.trim();
                                document.getElementById('dispMiddle').textContent = document.getElementById('editMiddle').value.trim();
                                document.getElementById('dispSurname').textContent = document.getElementById('editSurname').value.trim();
                                document.getElementById('dispSuffix').textContent = document.getElementById('editSuffix').value.trim();
                            }
                            if (!document.getElementById('addressEdit').classList.contains('d-none')) {
                                const street = document.getElementById('editStreet').value.trim();
                                document.getElementById('resStreet').textContent = street;
                            }
                            if (!document.getElementById('birthdateEdit').classList.contains('d-none')) {
                                const d = document.getElementById('editBirthdate').value;
                                document.getElementById('resBirthdate').textContent = d ? (new Date(d).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })) : '';
                            }

                            // Also update the corresponding table row and its data payload (AJAX no-reload)
                            const row = document.querySelector(`#usersTable tbody tr[data-user-id='${userId}']`);
                            if (row) {
                                const first = document.getElementById('editFirst').value.trim();
                                const middle = document.getElementById('editMiddle').value.trim();
                                const surname = document.getElementById('editSurname').value.trim();
                                const suffix = document.getElementById('editSuffix').value.trim();
                                const street = document.getElementById('editStreet').value.trim();
                                const bdate  = document.getElementById('editBirthdate').value;

                                const fullNameForTable = [first, surname].filter(Boolean).join(' ');
                                const nameEl = row.querySelector('td:first-child strong');
                                if (nameEl) nameEl.textContent = fullNameForTable;
                                row.dataset.name = fullNameForTable.toLowerCase();

                                const viewBtn = row.querySelector('.view-info-btn');
                                if (viewBtn) {
                                    let payload = {};
                                    try { payload = JSON.parse(viewBtn.getAttribute('data-user') || '{}'); } catch(e) { payload = {}; }
                                    payload.first_name = first;
                                    payload.middle_name = middle;
                                    payload.surname = surname;
                                    payload.suffix = suffix;
                                    payload.street = street;
                                    payload.birthdate = bdate || null;
                                    viewBtn.setAttribute('data-user', JSON.stringify(payload));
                                }
                            }

                            // Exit edit mode
                            ['name','address','birthdate'].forEach(t => {
                                const disp = document.getElementById(t + 'Display');
                                const edit = document.getElementById(t + 'Edit');
                                if (disp && edit) { disp.classList.remove('d-none'); edit.classList.add('d-none'); }
                            });
                            ['middleRow','surnameRow','suffixRow'].forEach(id => {
                                const el = document.getElementById(id);
                                if (el) el.classList.add('d-none');
                            });
                            showSaveCancel(false);
                            const editBtn = document.getElementById('editAllBtn');
                            if (editBtn) editBtn.classList.remove('d-none');
                            originalFields = null;

                            if (window.Swal) Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
                        } else {
                            let msg = 'Please try again.';
                            if (txt.startsWith('error:')) msg = txt.substring(6).trim() || msg;
                            if (window.Swal) Swal.fire({ icon: 'error', title: 'Update failed', text: msg });
                        }
                    })
                    .catch(() => { if (window.Swal) Swal.fire({ icon: 'error', title: 'Network error' }); });
            };

            if (window.Swal) {
                Swal.fire({
                    icon: 'question',
                    title: 'Save changes?',
                    text: 'This will update the user\'s information.',
                    showCancelButton: true,
                    confirmButtonText: 'Save',
                    cancelButtonText: 'Cancel',
                    reverseButtons: true
                }).then(res => { if (res.isConfirmed) doSave(); });
            } else {
                if (confirm('Save changes?')) doSave();
            }
        });



        
        //modal
document.addEventListener('DOMContentLoaded', function() {
    let selectedStreet = null;

    // Search Streets
    function searchStreets(query) {
        if (query.length < 2) {
            document.getElementById('streetResults').style.display = 'none';
            return;
        }

        fetch(`/Project_A2/assets/api/search_streets.php?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(streets => {
                const resultsContainer = document.getElementById('streetResults');
                resultsContainer.innerHTML = '';
                
                if (streets.length === 0) {
                    resultsContainer.innerHTML = '<div class="text-center text-muted p-2">No streets found</div>';
                    resultsContainer.style.display = 'block';
                    return;
                }

                streets.forEach(street => {
                    const streetItem = document.createElement('div');
                    streetItem.className = 'street-item p-2 border-bottom cursor-pointer';
                    streetItem.style.cursor = 'pointer';
                    streetItem.innerHTML = `
                        <div class="fw-semibold">${street.street_name}</div>
                        <small class="text-muted">${street.user_count} users</small>
                    `;
                    
                    streetItem.addEventListener('click', function() {
                        selectedStreet = street.street_name;
                        document.getElementById('streetSearch').value = street.street_name;
                        resultsContainer.style.display = 'none';
                        loadUserAnalytics();
                    });
                    
                    resultsContainer.appendChild(streetItem);
                });
                
                resultsContainer.style.display = 'block';
            })
            .catch(error => {
                console.error('Error searching streets:', error);
            });
    }

    // Load User Analytics Data
    function loadUserAnalytics() {
        const role = document.getElementById('roleFilter').value;

        // Show loading state
        document.getElementById('totalUsers').textContent = '...';
        document.getElementById('verifiedUsers').textContent = '...';
        document.getElementById('pendingUsers').textContent = '...';
        document.getElementById('adminUsers').textContent = '...';

        const params = new URLSearchParams({
            role: role,
            street: selectedStreet || 'all'
        });

        fetch(`/Project_A2/assets/api/get_user_analytics.php?${params}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update stats
                    document.getElementById('totalUsers').textContent = data.stats.totalUsers;
                    document.getElementById('verifiedUsers').textContent = data.stats.verifiedUsers;
                    document.getElementById('pendingUsers').textContent = data.stats.pendingUsers;
                    document.getElementById('adminUsers').textContent = data.stats.adminUsers;

                    // Update role distribution
                    updateRoleDistribution(data.roleDistribution);
                    
                    // Update street distribution
                    updateStreetDistribution(data.streetDistribution);
                } else {
                    throw new Error(data.error);
                }
            })
            .catch(error => {
                console.error('Error loading user analytics:', error);
                // Set default values on error
                document.getElementById('totalUsers').textContent = '0';
                document.getElementById('verifiedUsers').textContent = '0';
                document.getElementById('pendingUsers').textContent = '0';
                document.getElementById('adminUsers').textContent = '0';
            });
    }

    // Update Role Distribution with Bars
    function updateRoleDistribution(roleData) {
        const container = document.getElementById('roleDistribution');
        if (!roleData || roleData.length === 0) {
            container.innerHTML = '<div class="text-center text-muted py-4">No role data available</div>';
            return;
        }

        const maxCount = Math.max(...roleData.map(item => item.count));
        
        let html = `
            <div class="distribution-bars">
        `;
        
        roleData.forEach(role => {
            const height = maxCount > 0 ? Math.max((role.count / maxCount) * 150, 10) : 10;
            const color = getRoleColor(role.role);
            
            html += `
                <div class="distribution-bar" title="${role.role}: ${role.count} users (${role.percentage}%)">
                    <div class="bar-fill bg-${color}" style="height: ${height}px;"></div>
                    <div class="bar-label">
                        <div class="bar-count">${role.count}</div>
                        <div class="text-capitalize">${role.role}</div>
                        <div class="bar-percentage">${role.percentage}%</div>
                    </div>
                </div>
            `;
        });
        
        html += `</div>`;
        container.innerHTML = html;
    }

    // Update Street Distribution with Bars
    function updateStreetDistribution(streetData) {
        const container = document.getElementById('streetDistribution');
        if (!streetData || streetData.length === 0) {
            container.innerHTML = '<div class="text-center text-muted py-4">No street data available</div>';
            return;
        }

        const maxCount = Math.max(...streetData.map(item => item.count));
        
        let html = `
            <div class="distribution-bars">
        `;
        
        streetData.forEach((street, index) => {
            const height = maxCount > 0 ? Math.max((street.count / maxCount) * 150, 10) : 10;
            const color = getStreetColor(index);
            const displayName = street.street || 'Unknown Street';
            const shortName = displayName.length > 12 ? displayName.substring(0, 12) + '...' : displayName;
            
            html += `
                <div class="distribution-bar" title="${displayName}: ${street.count} users (${street.percentage}%)">
                    <div class="bar-fill bg-${color}" style="height: ${height}px;"></div>
                    <div class="bar-label">
                        <div class="bar-count">${street.count}</div>
                        <div class="text-truncate" style="max-width: 60px;">${shortName}</div>
                        <div class="bar-percentage">${street.percentage}%</div>
                    </div>
                </div>
            `;
        });
        
        html += `</div>`;
        container.innerHTML = html;
    }

    // Color helpers
    function getRoleColor(role) {
        const colors = {
            'admin': 'danger',
            'captain': 'warning',
            'secretary': 'info',
            'treasurer': 'success',
            'sk_chairman': 'primary',
            'councilor': 'secondary',
            'resident': 'dark'
        };
        return colors[role] || 'primary';
    }

    function getStreetColor(index) {
        const colors = ['primary', 'success', 'info', 'warning', 'danger', 'secondary', 'dark', 'primary', 'success', 'info'];
        return colors[index % colors.length];
    }

    // Initialize modal when opened
    document.getElementById('analyticsModal').addEventListener('show.bs.modal', function () {
        loadUserAnalytics();
    });

    // Event listeners for filters
    document.getElementById('roleFilter').addEventListener('change', loadUserAnalytics);

    // Street search functionality
    document.getElementById('streetSearch').addEventListener('input', function() {
        searchStreets(this.value);
    });

    document.getElementById('clearStreetSearch').addEventListener('click', function() {
        document.getElementById('streetSearch').value = '';
        document.getElementById('streetResults').style.display = 'none';
        selectedStreet = null;
        loadUserAnalytics();
    });

    // Export functionality
    document.getElementById('exportUserExcel').addEventListener('click', function() {
        const role = document.getElementById('roleFilter').value;
        const street = selectedStreet || 'all';

        const originalText = this.innerHTML;
        this.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Exporting...';
        this.disabled = true;
        
        const exportUrl = `/Project_A2/assets/api/export_users_excel.php?role=${role}&street=${encodeURIComponent(street)}`;
        
        // Create a temporary iframe for download
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.src = exportUrl;
        document.body.appendChild(iframe);
        
        // Remove iframe after download
        setTimeout(() => {
            document.body.removeChild(iframe);
            this.innerHTML = originalText;
            this.disabled = false;
        }, 3000);
    });
});
        </script>


    <?php include 'footer.php'; ?>
