<?php
require_once '../../includes/config.php';
// Assuming 'secretary_id' or 'user_id' is used for sessions
if(!isset($_SESSION['user_id'])){ 
    header('location: /Project_A2/login.php');
}
$stmt = $pdo->prepare("
    SELECT
        u.user_id,
        p.passkey,
        COALESCE(ur.role, 'user') AS role,
        u.status
    FROM
        users u
    JOIN
        account a ON u.user_id = a.user_id
    JOIN
        password p ON a.password_id = p.password_id
    LEFT JOIN
        user_roles ur ON u.user_id = ur.user_id
    WHERE
        u.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$role = $user['role'] ?? 'user';
$isSecretary = ($role === 'secretary');
if(!$isSecretary){
    header('location: /Project_A2/index.php');
}
// fetch user info and profile img for header drop menu
$stmt = $pdo->prepare("
    SELECT first_name, middle_name, surname, profile_picture
    FROM users
    WHERE user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$headerUser = $stmt->fetch(PDO::FETCH_ASSOC);
$profileImage = "";
if(!empty($headerUser['profile_picture'])){
    $profileImage = 'data:image/png;base64,' . base64_encode($headerUser['profile_picture']);
}
global $pdo;
$logoStmt = $pdo->query("SELECT brgy_logo FROM address_config LIMIT 1");
$logoData = $logoStmt->fetch(PDO::FETCH_ASSOC);
$logoImage = "";
if (!empty($logoData['brgy_logo'])){
    $logoImage = 'data:image/png;base64,' . base64_encode($logoData['brgy_logo']);
}
// Get email from email table using correct relationship
if ($headerUser) {
    try {
        $emailStmt = $pdo->prepare("
            SELECT e.email 
            FROM email e 
            JOIN account a ON e.email_id = a.email_id 
            WHERE a.user_id = ?
        ");
        $emailStmt->execute([$_SESSION['user_id']]);
        $emailResult = $emailStmt->fetch();
        $headerUser['email'] = $emailResult ? $emailResult['email'] : '';
    } catch (PDOException $e) {
        $headerUser['email'] = '';
    }
}

// Build display name
$username = $headerUser['first_name'] 
             . (!empty($headerUser['middle_name']) ? ' ' . $headerUser['middle_name'] : '') 
             . ' ' . $headerUser['surname'];

// Fetch notifications (assuming notifications table is used for all user types)
$notifStmt = $pdo->prepare("
    SELECT notif_id, notif_type, notif_topic, notif_entity_id, notif_created_at, notif_is_read
    FROM notifications
    WHERE user_id = ?
    ORDER BY notif_created_at DESC
    LIMIT 10
");
$notifStmt->execute([$_SESSION['user_id']]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

// Deduplicate dropdown notifications (type+topic+entity+minute)
$unique = [];
$deduped = [];
foreach ($notifications as $n) {
    $key = ($n['notif_type'] ?? '') . '|' . ($n['notif_topic'] ?? '') . '|' . ($n['notif_entity_id'] ?? '') . '|' . date('Y-m-d H:i', strtotime($n['notif_created_at']));
    if (!isset($unique[$key])) {
        $unique[$key] = true;
        $deduped[] = $n;
    }
}
// keep top 10 after dedup
$notifications = array_slice($deduped, 0, 10);

// Count unread notifications
$unreadCount = 0;
foreach ($notifications as $n) {
    if (!$n['notif_is_read']) $unreadCount++;
}

// Dummy functions for Logo and Baranggay Name - replace with your actual implementation
if (!function_exists('Logo')) {
    function Logo($context) {
        // Placeholder path - replace with your actual path
        return '<img src="../../assets/logo.png" alt="Logo">';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BDIS - Secretary Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-default@5/default.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>

body {
    background: #f8f9fa !important;
    color: #333;
    overflow-x: hidden;
}

body::-webkit-scrollbar, 
html::-webkit-scrollbar {
    display: none;
}


.sidebar-wrapper {
    width: 260px;
    height: 100vh;
    background: #fff;
    position: fixed;
    left: 0;
    top: 0;
    border-right: 1px solid #e5e7eb;
    padding-top: 20px;
    transition: 0.3s ease;
    z-index: 1000;
}

.sidebar-wrapper.minimized {
    width: 70px;
}

.sidebar-wrapper .nav-link {
    color: #333 !important;
    padding: 12px 15px;
    font-weight: 500;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 5px;
}

.sidebar-wrapper .nav-link i {
    font-size: 1.2rem;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.sidebar-wrapper .nav-link:hover,
.sidebar-wrapper .nav-link.active {
    background: #e9ecef !important;
    color: #0d6efd !important;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
}

.sidebar-wrapper.minimized .nav-link span {
    display: none !important;
}

.sidebar-wrapper.minimized .sidebar-title {
    display: none !important;
}

.sidebar-wrapper.minimized .nav-link {
    padding: 12px 10px;
}

.sidebar-wrapper.minimized .nav-link i {
    margin: 0;
}
.sidebar-logo img {
    width: 70px; 
    height: 70px; 
    border-radius: 50%; 
    object-fit: cover; 
    display: block; 
    margin: 0 auto;
}

.sidebar-title { 
    padding: 5px; 
    text-align: center; 
}

.sidebar-wrapper .nav-item.dropdown-hover {
    position: relative;
}

.sidebar-wrapper .collapse-menu-wrap {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.2s ease, visibility 0.2s ease;
    position: absolute;
    left: 100%;
    top: 0;
    width: 250px;
    padding: 0 10px;
    z-index: 1050;
    display: block !important;
    max-height: none !important;
    overflow: visible !important;
}

.sidebar-wrapper .collapse-menu-wrap.open-dropdown {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
}

.sidebar-wrapper .collapse-menu-wrap .sub-menu-content {
    background-color: #fff;
    padding: 10px 0;
    margin-top: 0;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    border: 1px solid #e5e7eb;
}

.sidebar-wrapper.minimized .collapse-menu-wrap {
    left: 70px;
    top: -20px;
}

.sidebar-wrapper .sub-nav .nav-link {
    padding-left: 15px !important;
    font-size: 0.95rem;
}

.sidebar-wrapper .sub-nav .nav-link i {
    display: none;
}

.sidebar-wrapper .collapse-menu-wrap .nav-link span {
    display: inline !important;
    margin-left: 10px;
}

.sidebar-wrapper .collapse-menu-wrap .sub-menu-content .nav-link {
    text-align: left !important;
    padding-left: 15px !important;
}
.sidebar-wrapper .notification-dot {
    height: 8px;
    width: 8px;
    background-color: red;
    border-radius: 50%;
    display: inline-block;
    position: absolute;
    right: 15px;
    top: 15px;
}

.sidebar-wrapper.minimized .notification-dot {
    right: 8px;
}

.main-content {
    margin-left: 260px;
    padding: 90px 20px 20px 20px;
    transition: 0.3s ease;
}

.main-content.expanded {
    margin-left: 70px;
}

.navbar {
    position: fixed;
    top: 0;
    left: 260px;
    right: 0;
    padding: 10px 20px;
    background: #fff !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    transition: 0.3s ease;
    z-index: 900;
}

.navbar.expanded {
    left: 70px;
}

.sidebar-wrapper,
.main-content,
.navbar {
    transition: all 0.3s ease;
}

.burger-btn {
    font-size: 1.8rem;
    cursor: pointer;
    margin-right: 15px;
    z-index: 1100;
    position: relative;
}
.sub-menu-wrap {
    position: absolute;
    top: 100%;
    right: 0;
    width: 280px;
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
    z-index: 999;
}

.sub-menu-wrap.open-menu {
    max-height: 400px;
}

.sub-menu {
    background-color: #fff;
    padding: 20px;
    margin: 10px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.user-info {
    display: flex;
    align-items: center;
}

.sub-menu-link {
    display: flex;
    align-items: center;
    text-decoration: none;
    color: #525252;
    margin: 12px 0;
    border-radius: 6px;
    padding: 8px;
    transition: all 0.3s ease;
}

.sub-menu-link p {
    width: 100%;
    margin: 0;
}

.sub-menu-link i {
    width: 40px;
    color: #1e3a8a;
}

.sub-menu-link:hover {
    background-color: #f3f4f6;
}

.rounded-circle {
    width: 40px;
    height: 40px;
}

.notif-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #dc3545;
    color: #fff;
    font-size: 0.7rem;
    font-weight: bold;
    padding: 3px 6px;
    border-radius: 50%;
    min-width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.notif-menu-wrap {
    position: absolute;
    top: 120%;
    right: 0;
    width: 320px;
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.4s ease;
    z-index: 999;
}

.notif-menu-wrap.open-menu {
    max-height: 500px;
}

.notif-menu {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    padding: 0;
    max-height: 480px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    border: 1px solid #e9ecef;
}

.notif-menu .d-flex.justify-content-between {
    padding: 14px 16px 10px;
    margin-bottom: 0;
    border-bottom: 2px solid #f1f3f4;
    background: #fff;
    position: sticky;
    top: 0;
    z-index: 10;
    flex-shrink: 0;
}

.notif-menu h6 {
    font-size: 0.95rem;
    font-weight: 600;
    margin: 0;
    color: #2c3e50;
}

.mark-all-read-btn {
    font-size: 0.75rem !important;
    padding: 0.35rem 0.6rem !important;
    border-radius: 6px !important;
    white-space: nowrap;
    height: auto !important;
    width: auto !important;
    background: #28a745 !important;
    border: 1px solid #28a745 !important;
    color: white !important;
    transition: all 0.2s ease !important;
}

.mark-all-read-btn:hover {
    background: #218838 !important;
    border-color: #1e7e34 !important;
    transform: translateY(-1px);
}

.notif-items-container {
    flex: 1;
    overflow-y: auto;
    max-height: calc(480px - 140px);
    padding: 8px 4px;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e0 transparent;
}

.notif-items-container::-webkit-scrollbar {
    width: 6px;
}

.notif-items-container::-webkit-scrollbar-track {
    background: #f8f9fa;
    border-radius: 3px;
}

.notif-items-container::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 3px;
}

.notif-items-container::-webkit-scrollbar-thumb:hover {
    background: #a0aec0;
}

.notif-item {
    display: block;
    padding: 10px 12px;
    text-decoration: none;
    color: #2d3748;
    border-radius: 8px;
    margin: 4px 8px;
    transition: all 0.2s ease;
    font-size: 0.88rem;
    line-height: 1.4;
    border-left: 4px solid transparent;
    word-wrap: break-word;
    overflow-wrap: break-word;
    background: #fafbfc;
}

.notif-item strong,
.notif-item small {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
}

.notif-item strong {
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 2px;
    color: #2d3748;
    white-space: normal;
    line-height: 1.3;
}

.notif-item small {
    font-size: 0.78rem;
    color: #718096;
    white-space: nowrap;
}

.notif-item:hover {
    background: #f7fafc;
    transform: translateX(2px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.notif-item.unread {
    background: #ebf8ff;
    border-left: 4px solid #3182ce;
    box-shadow: 0 1px 3px rgba(49, 130, 206, 0.1);
}

.notif-item.unread strong {
    color: #2b6cb0;
}

.notif-item:not(.unread) {
    background: #fafbfc;
    opacity: 1;
}

.notif-item:not(.unread) strong {
    color: #4a5568;
    font-weight: 500;
}

.notif-item:not(.unread) small {
    color: #718096;
    font-size: 0.76rem;
}

/* Notification Footer - IMPROVED */
.notif-footer {
    padding: 12px 16px;
    border-top: 2px solid #f1f3f4;
    background: #fff;
    position: sticky;
    bottom: 0;
    z-index: 10;
    flex-shrink: 0;
}

.notif-menu .btn {
    width: 100%;
    margin: 0;
    font-size: 0.85rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    font-weight: 500;
}

.notif-menu .text-center {
    padding: 12px 16px;
    font-size: 0.85rem;
    color: #718096;
    margin: 0;
    font-style: italic;
}

/* Mobile Responsive Styles */
@media (max-width: 575px) {
    .notification-dropdown {
        position: static;
    }
    
    .notif-menu-wrap {
        position: fixed !important;
        top: 70px !important;
        left: 10px !important;
        right: 10px !important;
        bottom: auto !important;
        width: calc(100vw - 20px) !important;
        height: auto !important;
        max-height: 0 !important;
        margin: 0 !important;
        background: #fff !important;
        border-radius: 12px !important;
        box-shadow: 0 8px 30px rgba(0,0,0,0.15) !important;
        z-index: 999 !important;
        overflow: hidden;
    }
    
    .notif-menu-wrap.open-menu {
        max-height: 500px !important;
    }
    
    .notif-menu {
        padding: 16px !important;
        max-height: 480px !important;
    }
    
    .notif-items-container {
        max-height: 380px;
    }
    
    .notif-item {
        font-size: 0.85rem;
        padding: 12px;
        margin: 6px 0;
    }
    
    .notif-item strong {
        font-size: 0.87rem;
        line-height: 1.3;
    }
}

@media (max-width: 430px) {
    .notif-menu-wrap {
        left: 5px !important;
        right: 5px !important;
        width: calc(100vw - 10px) !important;
    }
    
    .notif-menu {
        padding: 14px !important;
    }
    
    .notif-item {
        padding: 10px;
        font-size: 0.83rem;
    }
    
    .notif-item strong {
        font-size: 0.85rem;
    }
}

/* ===== RESPONSIVE STYLES ===== */

/* Tablet and Mobile (≤ 991px) */
@media (max-width: 991px) {
    /* Sidebar Mobile */
    .sidebar-wrapper {
        width: 70px;
        left: -70px;
    }
    
    .sidebar-wrapper.mobile-open {
        left: 0;
    }
    
    .sidebar-wrapper.mobile-open .collapse-menu-wrap {
        left: 70px;
        top: 0;
    }
    
    .sidebar-wrapper .collapse-menu-wrap {
        left: -250px;
    }
    
    .main-content {
        margin-left: 0 !important;
    }
    
    .navbar {
        left: 0 !important;
    }
    
    .sidebar-wrapper > .sidebar-nav > .nav-item > .nav-link {
        padding: 8px;
        background: transparent !important;
        box-shadow: none !important;
        justify-content: center;
    }
    
    .sidebar-wrapper > .sidebar-nav > .nav-item > .nav-link i {
        width: 32px;
        height: 32px;
        font-size: 1.1rem;
        background: #f8f9fa;
        border-radius: 8px;
        padding: 6px;
    }
    
    .sidebar-wrapper > .sidebar-nav > .nav-item > .nav-link:hover i,
    .sidebar-wrapper > .sidebar-nav > .nav-item > .nav-link.active i {
        background: #e7f1ff;
        color: #0d6efd;
    }
    
    .sidebar-wrapper > .sidebar-nav > .nav-item > .nav-link span {
        display: none;
    }
    
    /* Floating Menu Links */
    .sidebar-wrapper .collapse-menu-wrap .nav-link {
        justify-content: flex-start !important;
        padding: 8px 15px !important;
    }
    
    .sidebar-wrapper .collapse-menu-wrap .nav-link:hover {
        background: #e9ecef !important;
    }
}
@media (max-width: 575px) {

    .notification-dropdown {
        position: static;
    }
      .notif-menu-wrap {
        position: fixed !important;
        top: 70px !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100vw !important;
        height: 3000vh !important; 
        max-height: 0 !important; 
        margin: 0 !important;
        background: #fff !important;
        z-index: 999 !important;
        overflow: hidden;
    }
    
    .notif-menu-wrap.open-menu {
        max-height: 450px !important;
    }
    
    .notif-menu {
        padding: 12px !important;

        
    }
    
    .notif-item {
        font-size: 0.85rem;
        padding: 8px;
    }
    
    .sidebar-logo img {
        width: 20px;
        height: 20px;
    }
    
    .navbar .navbar-brand {
        font-size: 0.8rem;
        max-width: 100px;
    }
    
    .rounded-circle {
        width: 35px;
        height: 35px;
    }
}

/* Mobile Full Screen (≤ 430px) */
@media (max-width: 430px) {
    .notification-dropdown {
        position: static;
    }
    
    .notif-menu-wrap {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        max-height: 100vh !important;
        margin: 0 !important;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        z-index: 9999 !important;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .notif-menu-wrap.open-menu {
        display: block !important;
        max-height: 100vh !important;
        opacity: 1;
    }
    
    .notif-menu {
        position: absolute;
        top: 60px;
        left: 10px;
        right: 10px;
        bottom: 10px;
        width: calc(100% - 20px) !important;
        height: calc(100vh - 70px) !important;
        max-width: 100vw !important;
        margin: 0 !important;
        border-radius: 12px !important;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3) !important;
        padding: 15px;
        overflow-y: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
        transform: translateY(-20px);
        transition: transform 0.3s ease;
    }
    
    .notif-menu-wrap.open-menu .notif-menu {
        transform: translateY(0);
    }
    
    .notif-menu::-webkit-scrollbar {
        display: none;
    }
    
    /* Full width elements */
    .notif-item {
        margin: 8px 0;
        padding: 12px;
        width: 100%;
        box-sizing: border-box;
    }
    
    .notif-menu .btn {
        width: 100%;
        margin: 10px 0 0 0;
    }
    
    /* Header styling */
    .notif-menu .d-flex.justify-content-between {
        padding: 0 0 15px 0;
        margin-bottom: 15px;
        border-bottom: 2px solid #e9ecef;
        position: sticky;
        top: 0;
        background: white;
        z-index: 10;
    }
    
    /* Mobile close button */
    .notif-menu .mobile-close-btn {
        display: block;
        position: absolute;
        top: 10px;
        right: 15px;
        background: none;
        border: none;
        font-size: 1.5rem;
        color: #6c757d;
        cursor: pointer;
        z-index: 11;
        padding: 5px;
    }
    
    .mobile-close-btn:hover {
        color: #333;
    }
}

/* Extra Small Devices (≤ 320px) */
@media (max-width: 320px) {
    .notif-menu {
        top: 50px;
        left: 5px;
        right: 5px;
        bottom: 5px;
        width: calc(100% - 10px) !important;
        height: calc(100vh - 60px) !important;
        padding: 10px;
    }
}
    </style>
</head>

<body>

<div class="sidebar-wrapper minimized">
    <div class="sidebar-header">
            <?php if (!empty($logoImage)): ?>
            <div class="sidebar-logo">
                <img src = "<?php echo $logoImage; ?>" alt = "Barangay Logo">
            </div>
            <?php endif; ?>
            <h4 class="sidebar-title">BDIS</h4>
    </div>
    
    <ul class="nav flex-column ps-2 pe-2 mt-3 sidebar-nav">
        
        <li class="nav-item">
            <a href="/Project_A2/pages/secretary/dashboard.php" class="nav-link">
                <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
            </a>
        </li>

        <li class="nav-item">
            <a href="/Project_A2/pages/secretary/user_management.php" class="nav-link">
                <i class="bi bi-people"></i> <span>User Management</span>
            </a>
        </li>

        <!-- Manage Schedule removed: scheduling feature deprecated -->

        <li class="nav-item dropdown-hover" id="doc-nav-item">
            <a class="nav-link" href="#" role="button" aria-expanded="false" onclick="event.preventDefault(); toggleFloatingMenu('documentCollapse')">
                <i class="bi bi-folder"></i> <span>Document Management</span>
            </a>
            
            <div class="collapse-menu-wrap" id="documentCollapse">
                <div class="sub-menu-content">
                    <ul class="nav flex-column sub-nav">
                        <li class="nav-item">
                            <a href="/Project_A2/pages/secretary/document_management.php" class="nav-link">
                                <span>All Requests</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/Project_A2/pages/secretary/document_management.php?status=pending" class="nav-link">
                                <span>Pending</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/Project_A2/pages/secretary/document_management.php?status=in-progress" class="nav-link">
                                <span>In Progress</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/Project_A2/pages/secretary/document_management.php?status=completed" class="nav-link">
                                <span>Complete</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/Project_A2/pages/secretary/document_management.php?status=picked-up" class="nav-link">
                                <span>Picked Up</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/Project_A2/pages/secretary/document_management.php?status=rejected" class="nav-link">
                                <span>Rejected</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </li>

        <li class="nav-item dropdown-hover" id="system-nav-item">
            <a class="nav-link" href="#" role="button" aria-expanded="false" onclick="event.preventDefault(); toggleFloatingMenu('systemCollapse')">
                <i class="bi bi-sliders"></i> <span>System</span>
            </a>
            <div class="collapse-menu-wrap" id="systemCollapse">
                <div class="sub-menu-content">
                    <ul class="nav flex-column sub-nav">
                        <li class="nav-item">
                            <a href="/Project_A2/pages/secretary/system_configuration.php" class="nav-link">
                                <span>System Configuration</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/Project_A2/pages/secretary/activity_logs.php" class="nav-link">
                                <span>Activity Logs</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </li>

        <li class="nav-item">
            <a href="/Project_A2/pages/secretary/support_tickets.php" class="nav-link">
                <i class="bi bi-ticket-perforated"></i> <span>Support Tickets</span>
            </a>
        </li>
    </ul>
</div>

<nav class="navbar expanded navbar-expand-lg">
    <i class="bi bi-list burger-btn" onclick="toggleSidebar()"></i>
    <span class="navbar-brand">Secretary - Portal</span>
    
<!-- Notifications -->
<!-- Notifications -->
<div class="notification-dropdown ms-auto position-relative me-3">
    <i class="bi bi-bell fs-4" style="cursor:pointer; position:relative;" onclick="toggleNotifMenu(event)">
        <?php if($unreadCount > 0): ?>
            <span class="notif-badge"><?php echo $unreadCount; ?></span>
        <?php endif; ?>
    </i>
    <div class="notif-menu-wrap" id="notifMenu">
        <div class="notif-menu">
            <!-- FIXED HEADER -->
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Notifications</h6>
                <button class="btn btn-sm btn-outline-success mark-all-read-btn" id="markAllReadBtn" 
                        onclick="markAllAsRead(event)" 
                        style="display: <?php echo $unreadCount > 0 ? 'block' : 'none'; ?>;">
                    <i class="bi bi-check-lg"></i> Mark as Read
                </button>
            </div>
            
            <!-- SCROLLABLE ITEMS CONTAINER -->
            <div class="notif-items-container">
                <?php if(count($notifications) > 0): ?>
                <?php foreach($notifications as $notif): 
                    // Determine the target URL based on notification type for SECRETARY
                    $notifUrl = '#'; // default
                    switch($notif['notif_type']) {
                        case 'document_request':
                            $notifUrl = "/Project_A2/pages/secretary/document_management.php?id=" . $notif['notif_entity_id'];
                            break;
                        case 'ticket_open':
                            $notifUrl = "/Project_A2/pages/secretary/support_tickets.php?ticket_id=" . $notif['notif_entity_id'];
                            break;
                        case 'action':
                            $notifUrl = "/Project_A2/pages/secretary/document_management.php?id=" . $notif['notif_entity_id'];
                            break;
                        case 'chat':
                            $notifUrl = "/Project_A2/pages/secretary/view_ticket.php?ticket_id=" . $notif['notif_entity_id'];
                            break;
                        // Add other types as needed
                    }
                ?>
                    <a href="<?php echo $notifUrl; ?>" class="notif-item <?php echo $notif['notif_is_read'] ? '' : 'unread'; ?>" 
                       onclick="handleNotificationClick(<?php echo $notif['notif_id']; ?>, '<?php echo $notifUrl; ?>', event)">
                        <div>
                            <strong><?php echo htmlspecialchars($notif['notif_topic']); ?></strong>
                            <small><?php echo date('M d, Y H:i', strtotime($notif['notif_created_at'])); ?></small>
                        </div>
                    </a>
                <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-center mb-0">No notifications</p>
                <?php endif; ?>
            </div>
            
            <!-- FIXED FOOTER -->
            <div class="notif-footer">
                <a href="/Project_A2/pages/secretary/notifications.php" class="btn btn-sm btn-outline-primary w-100">View All Notifications</a>
            </div>
        </div>
    </div>
</div>

    <div class="profile-dropdown position-relative" onclick="toggleMenu(event)">
        <div class="d-flex align-items-center gap-2" style="cursor:pointer">
            <?php if (!empty($headerUser['profile_picture'])): ?>
                <img src="<?php echo $profileImage; ?>" alt = "Profile Picture" class="rounded-circle" style="object-fit:cover;">
            <?php else: ?>
                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center">
                    <i class="bi bi-person-fill text-white"></i>
                </div>
            <?php endif; ?>

            <div class="d-flex flex-column">
                <span class="fw-semibold"><?php echo ucwords(htmlspecialchars($headerUser['first_name'])); ?></span>
                <span class="badge bg-success">Secretary</span> </div>
        </div>

        <div class="sub-menu-wrap" id="subMenu">
            <div class="sub-menu">
                <a href="/Project_A2/pages/secretary/profile.php" class="sub-menu-link">
                    <i class="bi bi-person-circle me-2"></i> <p>Profile</p>
                </a>
                <hr>
                <a href="/Project_A2/pages/secretary/activity_logs.php" class="sub-menu-link">
                    <i class="bi bi-clipboard-data me-2"></i> <p> Activity Logs</p>
                </a>
                <hr>
                <a href="../../logout.php" class="sub-menu-link text-danger">
                    <i class="bi bi-box-arrow-right me-2"></i> <p>Logout</p>
                </a>
            </div>
        </div>
    </div>
</nav>


<div class="main-content expanded">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ==================== SIDEBAR & MENU FUNCTIONS ====================

function closeAllFloatingMenus() {
    document.querySelectorAll('.collapse-menu-wrap').forEach(el => {
        el.classList.remove('open-dropdown');
    });
}

closeAllFloatingMenus();

function toggleFloatingMenu(menuId) {
    const menu = document.getElementById(menuId);
    const isOpen = menu.classList.contains('open-dropdown');
    
    closeAllFloatingMenus();
    
    if (!isOpen) {
        menu.classList.add('open-dropdown');
    }
}

document.addEventListener('click', function(e) {
    const docNavItem = document.getElementById('doc-nav-item');
    const systemNavItem = document.getElementById('system-nav-item');
    const sidebar = document.querySelector('.sidebar-wrapper');
    const burgerBtn = document.querySelector('.burger-btn');
    
    if (docNavItem && !docNavItem.contains(e.target)) {
        document.getElementById('documentCollapse').classList.remove('open-dropdown');
    }
    if (systemNavItem && !systemNavItem.contains(e.target)) {
        document.getElementById('systemCollapse').classList.remove('open-dropdown');
    }

    if (window.innerWidth <= 991 && sidebar.classList.contains('mobile-open')) {
        if (!sidebar.contains(e.target) && !burgerBtn.contains(e.target)) {
            sidebar.classList.remove('mobile-open');
            closeAllFloatingMenus();
        }
    }
});

function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar-wrapper');
    const content = document.querySelector('.main-content');
    const navbar = document.querySelector('.navbar');

    closeAllFloatingMenus();

    if (window.innerWidth <= 991) {
        sidebar.classList.toggle('mobile-open');
        return;
    }

    sidebar.classList.toggle('minimized');
    content.classList.toggle('expanded');
    navbar.classList.toggle('expanded');
}

// ==================== PROFILE & NOTIFICATION DROPDOWNS ====================

const subMenu = document.getElementById("subMenu");
const notifMenu = document.getElementById("notifMenu");

function toggleMenu(event) {
    event.stopPropagation();
    notifMenu.classList.remove("open-menu");
    subMenu.classList.toggle("open-menu");
}

function toggleNotifMenu(event) {
    event.stopPropagation();
    subMenu.classList.remove("open-menu");
    notifMenu.classList.toggle("open-menu");
}

document.addEventListener('click', function(e) {
    const profileDropdown = document.querySelector('.profile-dropdown');
    const notifWrap = document.querySelector('.notification-dropdown');
    
    if (!profileDropdown.contains(e.target)) {
        subMenu.classList.remove('open-menu');
    }
    if (!notifWrap.contains(e.target)) {
        notifMenu.classList.remove('open-menu');
    }
});

notifMenu.addEventListener('click', function(e) {
    e.stopPropagation();
});

subMenu.addEventListener('click', function(e) {
    e.stopPropagation();
});

// ==================== NOTIFICATION FUNCTIONS ====================

function handleNotificationClick(notifId, notifUrl, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    console.log('Handling notification click:', notifId, notifUrl);
    
    fetch('/Project_A2/includes/mark_notif_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ notif_id: notifId })
    })
    .then(res => {
        if (!res.ok) {
            throw new Error('Network response was not ok');
        }
        return res.json();
    })
    .then(data => {
        console.log('Mark as read response:', data);
        if(data.success){
            const notifItem = event.target.closest('.notif-item');
            if(notifItem) {
                notifItem.classList.remove('unread');
                const badge = notifItem.querySelector('.notification-badge');
                if(badge) badge.remove();
            }
            
            updateBadgeCount();
            if (notifUrl && notifUrl !== '#') {
                window.location.href = notifUrl;
            }
        } else {
            console.error('Failed to mark as read:', data.error);
            if (notifUrl && notifUrl !== '#') {
                window.location.href = notifUrl;
            }
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        if (notifUrl && notifUrl !== '#') {
            window.location.href = notifUrl;
        }
    });
}

function markAllAsRead(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    console.log('Marking all as read...');
    
    const unreadNotifIds = [];
    document.querySelectorAll('.notif-item.unread').forEach(item => {
        const onclickAttr = item.getAttribute('onclick');
        if (onclickAttr) {
            const match = onclickAttr.match(/handleNotificationClick\((\d+),/);
            if (match) {
                unreadNotifIds.push(parseInt(match[1]));
            }
        }
    });

    console.log('Unread notification IDs:', unreadNotifIds);

    if (unreadNotifIds.length === 0) {
        console.log('No unread notifications');
        return;
    }

    fetch('mark_all_notif_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            notif_ids: unreadNotifIds
        })
    })
    .then(res => {
        if (!res.ok) {
            throw new Error('Network response was not ok');
        }
        return res.json();
    })
    .then(data => {
        console.log('Mark all response data:', data);
        if(data.success){
            document.querySelectorAll('.notif-item.unread').forEach(item => {
                item.classList.remove('unread');
                const badge = item.querySelector('.notification-badge');
                if(badge) badge.remove();
            });
            
            updateBadgeCount();
            
            console.log('Marked', data.updated, 'notifications as read');
            
            setTimeout(() => {
                notifMenu.classList.remove('open-menu');
            }, 500);
        } else {
            console.error('Failed to mark all as read:', data.error);
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        alert('Network error: ' + error.message);
    });
}

function updateBadgeCount() {
    const unreadItems = document.querySelectorAll('.notif-item.unread');
    const badge = document.querySelector('.notif-badge');
    const markAllBtn = document.getElementById('markAllReadBtn');
    
    if (unreadItems.length === 0) {
        if (badge) badge.remove();
        if (markAllBtn) markAllBtn.style.display = 'none';
    } else {
        if (!badge) {
            const bellIcon = document.querySelector('.bi-bell');
            if (bellIcon) {
                const newBadge = document.createElement('span');
                newBadge.className = 'notif-badge';
                newBadge.textContent = unreadItems.length;
                bellIcon.appendChild(newBadge);
            }
        } else {
            badge.textContent = unreadItems.length;
        }
        if (markAllBtn) {
            markAllBtn.style.display = 'block';
        }
    }
}

// ==================== INITIALIZATION ====================

document.addEventListener('DOMContentLoaded', function() {
    if (window.innerWidth > 991) {
        const sidebar = document.querySelector('.sidebar-wrapper');
        const content = document.querySelector('.main-content');
        const navbar = document.querySelector('.navbar');
        
        if (!sidebar.classList.contains('minimized')) sidebar.classList.add('minimized');
        if (!content.classList.contains('expanded')) content.classList.add('expanded');
        if (!navbar.classList.contains('expanded')) navbar.classList.add('expanded');
    }

    const currentPath = window.location.pathname + window.location.search;
    let bestMatch = null;
    let bestMatchLength = 0;

    document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
        const linkHref = link.getAttribute('href');
        if (linkHref && linkHref !== '#' && currentPath.startsWith(linkHref)) {
            if (linkHref.length > bestMatchLength) {
                bestMatch = link;
                bestMatchLength = linkHref.length;
            }
        }
    });

    document.querySelectorAll('.sidebar-nav .nav-link.active').forEach(link => {
        link.classList.remove('active');
    });

    if (bestMatch) {
        bestMatch.classList.add('active');
    }

    updateBadgeCount();
    
    // Debug info
    console.log('Notifications initialized');
    console.log('Unread count:', document.querySelectorAll('.notif-item.unread').length);
});
</script>
</body>
</html>