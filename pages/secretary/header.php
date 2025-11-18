<?php
require_once '../../includes/config.php';
// Assuming 'secretary_id' or 'user_id' is used for sessions
if(!isset($_SESSION['user_id'])){ 
    header('location: /Project_A2/login.php');
}
// fetch user info and profile img for header drop menu
$stmt = $pdo->prepare("
    SELECT first_name, middle_name, surname, profile_picture
    FROM users
    WHERE user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$headerUser = $stmt->fetch(PDO::FETCH_ASSOC);

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
    <style>
        body {
            background: #f8f9fa !important;
            color: #333;
            overflow-x: hidden;
        }
        body::-webkit-scrollbar, html::-webkit-scrollbar {
            display: none;
        }

        /* Sidebar */
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
        }
        .sidebar-wrapper .nav-link:hover,
        .sidebar-wrapper .nav-link.active {
            background: #e9ecef !important;
            color: #0d6efd !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important; /* subtle shadow on hover/active */
        }
        
        /* Hide text in minimized view */
        .sidebar-wrapper.minimized .nav-link span,
        .sidebar-wrapper.minimized .sidebar-title {
            display: none !important;
        }
        /* Center icons in minimized view */
        .sidebar-wrapper.minimized .nav-link i {
            display: block;
            margin: 0 auto;
            text-align: center;
        }
        /* Mobile Sidebar */
        @media (max-width: 991px) {
            .sidebar-wrapper {
                left: -260px;
            }
            .sidebar-wrapper.mobile-open {
                left: 0;
            }
        }

        /* Main content */
        .main-content {
            margin-left: 260px;
            padding: 90px 20px 20px 20px;
            transition: 0.3s ease;
        }
        .main-content.expanded {
            margin-left: 70px;
        }

        @media (max-width: 991px) {
            .main-content {
                margin-left: 0 !important;
            }
        }
        .sidebar-wrapper,
        .main-content,
        .navbar {
            transition: all 0.3s ease;
        }
        
        /* Navbar */
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
        @media (max-width: 991px) {
            .navbar {
                left: 0 !important;
            }
        }

        /* Burger Button */
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
    max-height: 400px; /* expands menu */
}
        /* Profile Dropdown (Keep all profile/notif styles as is) */
        .sub-menu-wrap{
            position: absolute; top:100%; right: 0; width: 280px; max-height:0px; overflow:hidden; transition: max-height 0.5s; z-index: 999;
        }    
        .sub-menu-wrap.open-menu{ max-height: 400px }
        .sub-menu{ background-color: #fff; padding: 20px; margin: 10px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .user-info{ display:flex; align-items: center; }
        .sub-menu-link{ display: flex; align-items:center; text-decoration: none; color: #525252; margin: 12px 0; border-radius: 6px; padding: 8px; transition: all 0.3s ease; }
        .sub-menu-link p{ width:100%; margin: 0; }
        .sub-menu-link i{ width:40px; color: #1e3a8a; }
        .sub-menu-link:hover { background-color: #f3f4f6; }
        .rounded-circle{ width:40px; height:40px; }
        
        /* Notification badge */
        .notif-badge {
            position: absolute; top: -5px; right: -5px; background: red; color: #fff; font-size: 0.7rem; font-weight: bold; padding: 2px 6px; border-radius: 50%;
        }
        .sidebar-title{ padding: 5px; text-align: center; }
        
        /* Notification dropdown */
        .notif-menu-wrap {
            position: absolute; top: 120%; right: 0; width: 300px; max-height: 0; overflow: hidden; transition: max-height 0.4s ease; z-index: 999;
        }
        .notif-menu-wrap.open-menu { max-height: 400px; }
        .notif-menu { background: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); padding: 15px; }
        .notif-item { display: block; padding: 10px 8px; text-decoration: none; color: #333; border-radius: 6px; margin-bottom: 5px; transition: all 0.2s ease; }
        .notif-item:hover { background: #f3f4f6; }
        .notif-item.unread { background: #e7f1ff; }
        
        /* Sidebar Logo Fix */
        .sidebar-logo img {
            width: 70px; height: 70px; border-radius: 50%; object-fit: cover; display: block; margin: 0 auto;
        }

        /* --- STYLES FOR FLOATING DROPDOWN SUB-MENU (Applies to Maximized & Minimized) --- */
        .sidebar-wrapper .sub-nav .nav-link {
            padding-left: 15px !important; 
            font-size: 0.95rem;
        }
        /* Ensure no icons are shown in the sub-menus for better text visibility */
        .sidebar-wrapper .sub-nav .nav-link i {
            display: none;
        }

        .sidebar-wrapper .nav-item.dropdown-hover {
            position: relative; /* Needed for positioning the dropdown */
        }
        
        /* Force Floating Position: This block applies to ALL states */
        .sidebar-wrapper .collapse-menu-wrap {
            /* Hide by default using max-height for smooth transition */
            max-height: 0; 
            overflow: hidden;
            transition: max-height 0.4s ease, opacity 0.4s ease;
            opacity: 0;
            pointer-events: none; /* Make links unclickable when hidden */

            /* Force Floating Position (relative to the sidebar item) */
            position: absolute;
            left: 100%; /* Position right next to the link container (Maximized state) */
            top: 0;      /* Align with the top of the link container */
            width: 250px; 
            padding: 0 10px;
            z-index: 1050;
            
            /* Ensure no visual artifacts from Bootstrap's .collapse class remain */
            display: block !important; 
            visibility: visible !important; 
        }

        /* State when menu is open/floating */
        .sidebar-wrapper .collapse-menu-wrap.open-dropdown {
            max-height: 400px;
            opacity: 1;
            pointer-events: auto;
        }

        /* Floating Menu Styling */
        .sidebar-wrapper .collapse-menu-wrap .sub-menu-content {
            background-color: #fff;
            padding: 10px 0;
            margin-top: 0; 
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            border: 1px solid #e5e7eb;
        }

        /* Specific adjustment for Minimized state to shift the floating menu left */
        .sidebar-wrapper.minimized .collapse-menu-wrap {
            left: 70px; /* Position next to the minimized sidebar */
            top: -20px; /* Adjust vertical position */
        }

        /* Ensure link text is always visible inside the floating menu */
        .sidebar-wrapper .collapse-menu-wrap .nav-link span {
            display: inline !important; 
            margin-left: 10px; 
        }
        .sidebar-wrapper .collapse-menu-wrap .sub-menu-content .nav-link {
            text-align: left !important;
            padding-left: 15px !important;
        }
        
        /* Notification Dot on Sidebar */
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
                @media (max-width: 991px) {
            
            .navbar .navbar-brand {
                            white-space: nowrap;
                font-size: 0.9rem;
                max-width: 120px;
            }
            .sidebar-wrapper {
                left: auto;
                right: -260px;
            }
            .sidebar-wrapper.mobile-open {
                left: 0;
            }
            .main-content.mobile-open {
                margin-left: 260px; /* push main content right when sidebar is open */
            }
            .navbar.mobile-open {
                left: 260px; /* push navbar right when sidebar is open */
            }
        }
        /* Mobile overrides: We need standard collapse on mobile to fit the screen */
        @media (max-width: 991px) {
            /* On mobile, revert to standard collapse behavior (force max-height: none for visual fit) */
            .sidebar-wrapper.mobile-open .collapse-menu-wrap,
            .sidebar-wrapper .collapse-menu-wrap {
                position: relative !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                max-height: none !important; /* Allow content to dictate height */
                overflow: visible !important;
                padding: 0 !important;
                opacity: 1 !important;
                pointer-events: auto !important;
            }
             /* On mobile, only show when Bootstrap 'show' or custom 'open-dropdown' is present */
             .sidebar-wrapper .collapse-menu-wrap:not(.show):not(.open-dropdown) {
                max-height: 0 !important;
                overflow: hidden !important;
             }
            .sidebar-wrapper.mobile-open .collapse-menu-wrap .sub-menu-content {
                background: none;
                padding: 0;
                margin: 0;
                box-shadow: none;
                border: none;
            }
        }
        @media (max-width: 575px) {
        .notif-menu-wrap {
            position: fixed !important;
            top: 70px !important;
            right: 0 !important;
            left: 0 !important;
            width: 92% !important;
            margin: 0 auto !important;
            z-index: 2000 !important;
        }
                @media (max-width: 575px) {
            .sidebar-logo img{
            width: 20px;
            height: 20px; 
            }
            .navbar .navbar-brand {
                font-size: 0.8rem;
                max-width: 100px;
            }
            .rounded-circle{
            width:35px;height:35px;
        }
        }
    </style>
</head>

<body>

<div class="sidebar-wrapper minimized">
    <div class="sidebar-header">
            <div class="sidebar-logo">
                <?php echo Logo(context: 'sidebar'); ?>
            </div>
            <h4 class="sidebar-title">BDIS</h4>
    </div>
    
    <ul class="nav flex-column ps-2 pe-2 mt-3 sidebar-nav">
        
        <li class="nav-item">
            <a href="/Project_A2/pages/secretary/dashboard.php" class="nav-link">
                <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
            </a>
        </li>

        <li class="nav-item">
            <a href="/Project_A2/pages/secretary/user_verification.php" class="nav-link">
                <i class="bi bi-check-circle"></i> <span>User Verification</span>
            </a>
        </li>

        <li class="nav-item">
            <a href="/Project_A2/pages/secretary/manage_schedule.php" class="nav-link">
                <i class="bi bi-calendar-check"></i> <span>Manage Schedule</span>
            </a>
        </li>

        <li class="nav-item dropdown-hover" id="doc-nav-item">
            <a class="nav-link" data-bs-toggle="collapse" href="#documentCollapse" role="button" aria-expanded="false" 
                onclick="event.preventDefault(); toggleDropdownMenu(this, 'documentCollapse', 'doc-nav-item')">
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
                            <a href="/Project_A2/pages/secretary/document_management.php?status=for-signing" class="nav-link">
                                <span>For Signing</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/Project_A2/pages/secretary/document_management.php?status=completed" class="nav-link">
                                <span>Complete</span>
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
            <a class="nav-link" data-bs-toggle="collapse" href="#systemCollapse" role="button" aria-expanded="false" 
                onclick="event.preventDefault(); toggleDropdownMenu(this, 'systemCollapse', 'system-nav-item')">
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
                            <a href="/Project_A2/pages/secretary/user_management.php" class="nav-link">
                                <span>User Management</span>
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
                <?php if ($unreadCount > 0): ?>
                <span class="notification-dot"></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>
</div>

<nav class="navbar expanded navbar-expand-lg">
    <i class="bi bi-list burger-btn" onclick="toggleSidebar()"></i>
    <span class="navbar-brand">Secretary - Portal</span>
    
    <div class="notification-dropdown ms-auto position-relative me-3" onclick="toggleNotifMenu(event)">
        <i class="bi bi-bell fs-4" style="cursor:pointer; position:relative;">
            <?php if($unreadCount > 0): ?>
                <span class="notif-badge"><?php echo $unreadCount; ?></span>
            <?php endif; ?>
        </i>
        <div class="notif-menu-wrap" id="notifMenu">
            <div class="notif-menu">
                <h6 class="mb-2">Notifications</h6>
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
                        // Add other types as needed
                    }
                ?>
                    <a href="<?php echo $notifUrl; ?>" class="notif-item <?php echo $notif['notif_is_read'] ? '' : 'unread'; ?>" onclick="markAsRead(<?php echo $notif['notif_id']; ?>)">
                        <div>
                            <strong><?php echo htmlspecialchars($notif['notif_topic']); ?></strong><br>
                            <small><?php echo date('M d, Y H:i', strtotime($notif['notif_created_at'])); ?></small>
                        </div>
                    </a>
                <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-center mb-0">No notifications</p>
                <?php endif; ?>
                <a href="#" class="btn btn-sm btn-outline-primary w-100 mt-2">View All Notifications</a>
            </div>
        </div>
    </div>

    <div class="profile-dropdown position-relative" onclick="toggleMenu(event)">
        <div class="d-flex align-items-center gap-2" style="cursor:pointer">
            <?php if (!empty($headerUser['profile_picture'])): ?>
                <img src="/Project_A2/uploads/<?php echo htmlspecialchars($headerUser['profile_picture']); ?>"
                    class="rounded-circle" style="object-fit:cover;">
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
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>



    // NEW FUNCTION TO ENSURE FLOATING MENUS ARE CLOSED ON LOAD/INCLUDE
    function closeAllFloatingMenus() {
        // Find all floating menu wrappers
        document.querySelectorAll('.collapse-menu-wrap').forEach(el => {
            el.classList.remove('open-dropdown');
            
            // For mobile, also hide Bootstrap's state
            if (window.innerWidth <= 991) {
                // Use Bootstrap's instance to hide if it's currently showing
                const instance = bootstrap.Collapse.getInstance(el);
                if (instance) {
                    instance.hide();
                } else {
                    // Fallback for elements not yet initialized by BS
                    el.classList.remove('show'); 
                }
            }
        });
    }

    // ⭐ CALL THE FUNCTION HERE TO GUARANTEE CLOSED STATE WHEN SCRIPT RUNS
    closeAllFloatingMenus();

    // --- Synchronization Logic (Handles closing the other menu) ---
    function syncMenuStates(currentId) {
        // This function ensures only one floating menu is open at a time
        const currentElement = document.getElementById(currentId);
        
        // Only proceed if the current menu is opening/open
        if (currentElement.classList.contains('open-dropdown')) {
            const targetToCloseId = currentId === 'documentCollapse' ? 'systemCollapse' : 'documentCollapse';
            const targetToCloseEl = document.getElementById(targetToCloseId);

            // Always remove custom class for floating dropdown
            targetToCloseEl.classList.remove('open-dropdown');
        }
        
    }

    // --- Core Toggle Function (Handles Mobile Collapse and Desktop Floating) ---
function toggleDropdownMenu(linkElement, collapseId, navItemId) {
    const collapseElement = document.getElementById(collapseId);
    const isMobile = window.innerWidth <= 991;

    event.preventDefault();
    event.stopPropagation();

    // Close the other dropdown if it's open
    const targetToCloseId = collapseId === 'documentCollapse' ? 'systemCollapse' : 'documentCollapse';
    const targetToCloseEl = document.getElementById(targetToCloseId);
    targetToCloseEl.classList.remove('open-dropdown'); // <-- ensures the other menu closes
    if (isMobile) {
        const otherInstance = bootstrap.Collapse.getInstance(targetToCloseEl) || bootstrap.Collapse.getOrCreateInstance(targetToCloseEl, { toggle: false });
        otherInstance.hide();
    }

    if (isMobile) {
        // Mobile: toggle via Bootstrap collapse
        const isCurrentlyOpen = collapseElement.classList.contains('show');
        const currentInstance = bootstrap.Collapse.getInstance(collapseElement) || bootstrap.Collapse.getOrCreateInstance(collapseElement, { toggle: false });
        if (isCurrentlyOpen) {
            currentInstance.hide();
            collapseElement.classList.remove('open-dropdown');
        } else {
            currentInstance.show();
            collapseElement.classList.add('open-dropdown');
        }
        return;
    }

    // Desktop: toggle floating dropdown
    collapseElement.classList.toggle('open-dropdown');
    targetToCloseEl.classList.remove('open-dropdown');    
}


    // Burger menu toggle (Remains the same)
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar-wrapper');
        const content = document.querySelector('.main-content');
        const navbar = document.querySelector('.navbar');

        // Close all custom floating dropdowns when toggling the sidebar
        closeAllFloatingMenus(); // Use the new function here

        // Ensure mobile collapses are hidden when switching state
        if (window.innerWidth <= 991) {
            // Mobile toggle logic
            sidebar.classList.toggle('mobile-open');
            content.classList.toggle('mobile-open');
            navbar.classList.toggle('mobile-open');
            return;
        }

        // Desktop toggle
        sidebar.classList.toggle('minimized');
        content.classList.toggle('expanded');
        navbar.classList.toggle('expanded');
    }

    // Profile and Notification Dropdowns (Kept as is)
    const subMenu = document.getElementById("subMenu");
    const notifMenu = document.getElementById("notifMenu");

 document.addEventListener('DOMContentLoaded', function() {
    const profileDropdown = document.querySelector('.profile-dropdown');
    const subMenu = document.getElementById('subMenu');
    const notifMenu = document.getElementById('notifMenu');

    profileDropdown.addEventListener('click', function(e) {
        e.stopPropagation(); // prevent document click from closing it
        subMenu.classList.toggle('open-menu');
        notifMenu.classList.remove('open-menu'); // close notifications if open
    });

    document.addEventListener('click', function(e) {
        if (!profileDropdown.contains(e.target)) {
            subMenu.classList.remove('open-menu');
        }
        if (!notifMenu.contains(e.target)) {
            notifMenu.classList.remove('open-menu');
        }
    });
});

    function toggleNotifMenu(event) {
        event.stopPropagation();
        subMenu.classList.remove("open-menu"); // Close profile menu
        notifMenu.classList.toggle("open-menu");
        closeAllFloatingMenus(); // Close sidebar floating menus
        if (window.innerWidth <= 575) {
            notifMenu.style.overflowY = "auto";
        }
    }

    // Close menus when clicking outside (Revised for the new floating menu logic)
    document.addEventListener("click", function (event) {
        const profileDropdown = document.querySelector(".profile-dropdown");
        const notifWrap = document.querySelector(".notification-dropdown");
        
        if (!profileDropdown.contains(event.target)) {
            subMenu.classList.remove("open-menu");
        }
        if (!notifWrap.contains(event.target)) {
            notifMenu.classList.remove("open-menu");
        }

        // Always close sidebar dropdown menus if clicking outside on desktop
        if (window.innerWidth > 991) {
            const docNavItem = document.getElementById('doc-nav-item');
            if (docNavItem && !docNavItem.contains(event.target)) {
                document.getElementById('documentCollapse').classList.remove('open-dropdown');
            }
            const systemNavItem = document.getElementById('system-nav-item');
            if (systemNavItem && !systemNavItem.contains(event.target)) {
                document.getElementById('systemCollapse').classList.remove('open-dropdown');
            }
        } else if (window.innerWidth <= 991) {
            // Mobile: Close profile/notif when clicking outside, but leave sidebar menus alone
        }
    });
    
    // Initialize sidebar state, handle active links, and fix the close-on-click issue
    document.addEventListener('DOMContentLoaded', function() {
        // Default desktop state: minimized
        if (window.innerWidth > 991) {
            const sidebar = document.querySelector('.sidebar-wrapper');
            const content = document.querySelector('.main-content');
            const navbar = document.querySelector('.navbar');
            
            // Apply minimized/expanded classes on load
            if (!sidebar.classList.contains('minimized')) sidebar.classList.add('minimized');
            if (!content.classList.contains('expanded')) content.classList.add('expanded');
            if (!navbar.classList.contains('expanded')) navbar.classList.add('expanded');
        }

        // Set active links and expand parent menu if active
        const currentPath = window.location.pathname + window.location.search;
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
            const linkHref = link.getAttribute('href');
            
            // 1. Handle Active State
            if (currentPath.startsWith(linkHref) && linkHref !== '#') {
                link.classList.add('active');

                const collapseDiv = link.closest('.collapse-menu-wrap');
                if (collapseDiv) {
                    // Always open the custom floating/mobile menu container
                    collapseDiv.classList.add('open-dropdown'); 

                    // On mobile, explicitly use Bootstrap show() to display the sub-menu container
                    if (window.innerWidth <= 991) {
                        const collapseInstance = bootstrap.Collapse.getOrCreateInstance(collapseDiv, { toggle: false });
                        collapseInstance.show();
                    }
                }
            }

            // ⭐ CRITICAL FIX: Explicitly close the floating menu when a sub-link is clicked.
            const parentCollapse = link.closest('.collapse-menu-wrap');
            if (parentCollapse) {
                // We override the click behavior on sub-links only
                link.addEventListener('click', (e) => {
                    // If the link points to a valid destination
                    if (link.href && link.href !== '#' && !link.href.includes('javascript:void(0)')) {
                        // Prevent the default navigation for a moment
                        e.preventDefault();

                        // 1. Hide the floating menu immediately
                        parentCollapse.classList.remove('open-dropdown');
                        
                        // 2. Hide Bootstrap's state on mobile
                         if (window.innerWidth <= 991) {
                             parentCollapse.classList.remove('show');
                         }

                        // 3. Add a tiny delay (10ms) to ensure the visual state updates
                        // before redirecting the window to the link's destination.
                        setTimeout(() => {
                            window.location.href = link.href;
                        }, 10); 
                    }
                });
            }
        });
    });

    // Mark notification as read (Kept as is)
    function markAsRead(notifId) {
        fetch('/Project_A2/includes/mark_notif_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notif_id: notifId })
        }).then(response => response.json())
        .then(data => {
            if(data.success){
                const badge = document.querySelector('.notif-badge');
                if(badge){
                    let count = parseInt(badge.innerText);
                    count = Math.max(count - 1, 0);
                    if(count === 0) badge.remove();
                    else badge.innerText = count;
                }
            }
        });
    }
document.addEventListener('DOMContentLoaded', function() {
    // 1. Close all floating menus on load
    document.querySelectorAll('.collapse-menu-wrap').forEach(el => {
        el.classList.remove('open-dropdown');
        if (window.innerWidth <= 991) {
            el.classList.remove('show');
        }
    });

    // 2. Set active links and expand parent menu if it matches current URL
    const currentPath = window.location.pathname + window.location.search;
    document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
        const linkHref = link.getAttribute('href');
        if (currentPath.startsWith(linkHref) && linkHref !== '#') {
            link.classList.add('active');
            const collapseDiv = link.closest('.collapse-menu-wrap');
            if (collapseDiv) {
                collapseDiv.classList.add('open-dropdown');
                if (window.innerWidth <= 991) {
                    const collapseInstance = bootstrap.Collapse.getOrCreateInstance(collapseDiv, { toggle: false });
                    collapseInstance.show();
                }
            }
        }
    });
});
// Function to close dropdown when a sub-link is clicked
function attachSubMenuCloseBehavior() {
document.querySelectorAll('.collapse-menu-wrap .nav-link').forEach(link => {
    // Skip parent links
    if (!link.closest('.dropdown-hover')) {
        const parentCollapse = link.closest('.collapse-menu-wrap');
        if (!parentCollapse) return;

        link.addEventListener('click', function(e) {
            e.stopPropagation(); // stop bubbling
            parentCollapse.classList.remove('open-dropdown');
            if (window.innerWidth <= 991) {
                const collapseInstance = bootstrap.Collapse.getInstance(parentCollapse) 
                    || bootstrap.Collapse.getOrCreateInstance(parentCollapse, { toggle: false });
                collapseInstance.hide();
            }
            if (link.href && link.href !== '#' && !link.href.includes('javascript:void(0)')) {
                e.preventDefault();
                setTimeout(() => { window.location.href = link.href; }, 10);
            }
        });
    }
});
}

// Call this function on DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    attachSubMenuCloseBehavior();
    e.stopPropagation();
});

</script>
</body>
</html>