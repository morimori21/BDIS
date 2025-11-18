


<?php
require_once '../../includes/config.php';
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BDIS - Resident Dashboard</title>
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
            over
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
        .sidebar-wrapper.minimized .nav-link span,
        .sidebar-wrapper.minimized .sidebar-title {
            display: none !important;
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
            z-index: 1100;           /* higher than sidebar (1000) and navbar (900) */
            position: relative;       /* needed for z-index to work */
        }

        /* Profile Dropdown */
        .sub-menu-wrap{
            position: absolute;
            top:100%;
            right: 0;
            width: 280px;
            max-height:0px;
            overflow:hidden;
            transition: max-height 0.5s;
            z-index: 999;
        }    
        .sub-menu-wrap.open-menu{
            max-height: 400px
        }
        .sub-menu{
            background-color: #fff;
            padding: 20px;
            margin: 10px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .user-info{
            display:flex;
            align-items: center;
        }
        .sub-menu-link{
            display: flex;
            align-items:center;
            text-decoration: none;
            color: #525252;
            margin: 12px 0;
            border-radius: 6px;
            padding: 8px;
            transition: all 0.3s ease;
        }
        .sub-menu-link p{
            width:100%;
            margin: 0;
        }
        .sub-menu-link i{
            width:40px;
            color: #1e3a8a;
        }
        .sub-menu-link span{
            font-size: 22px;
            transition: transform 0.5s;
        }
        .sub-menu-link:hover {
            background-color: #f3f4f6;
        }
        .sub-menu-link:hover span{
            transform: translateX(5px);
        }
        .sub-menu-link:hover p{
            font-weight:600
        }

        .profile-dropdown {
            position: relative;
            display: inline-block;
            /* margin-right: 20px; */
        }
        /* Navbar brand text */
        .navbar .navbar-brand {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 1rem; /* default font size */
        }
        .rounded-circle{
            width:40px;
            height:40px;
        }
        /* Reduce font size on smaller screens */
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
        /* Notification badge */
        .notif-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: red;
            color: #fff;
            font-size: 0.7rem;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 50%;
        }
        .sidebar-title{
            padding: 5px;
            text-align: center;
        }
        /* Notification dropdown */
        .notif-menu-wrap {
            position: absolute;
            top: 120%;
            right: 0;
            width: 300px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease;
            z-index: 999;
        }

        .notif-menu-wrap.open-menu {
            max-height: 400px;
        }

        .notif-menu {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            padding: 15px;
        }

        .notif-item {
            display: block;
            padding: 10px 8px;
            text-decoration: none;
            color: #333;
            border-radius: 6px;
            margin-bottom: 5px;
            transition: all 0.2s ease;
        }

        .notif-item:hover {
            background: #f3f4f6;
        }

        .notif-item.unread {
            background: #e7f1ff;
        }
        /* Sidebar Logo Fix */
        .sidebar-logo img {
            width: 70px;          /* Adjust size here */
            height: 70px;         /* Make height same as width */
            border-radius: 50%;   /* Makes it a circle */
            object-fit: cover;    /* Ensures good cropping */
            display: block;
            margin: 0 auto;       /* Center the logo */
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
}

    </style>
</head>

<body>

<!-- SIDEBAR -->
<div class="sidebar-wrapper minimized">
    <div class="sidebar-header">
            <div class="sidebar-logo">
                <?php echo Logo(context: 'sidebar'); ?>
            </div>
            <h4 class="sidebar-title">BDIS</h4>
    </div>
    <ul class="nav flex-column ps-2 pe-2 mt-3 sidebar-nav">
        <li class="nav-item">
            <a href="/Project_A2/pages/resident/dashboard.php" class="nav-link">
                <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
            </a>
        </li>

        <li class="nav-item">
            <a href="/Project_A2/pages/resident/request_document.php" class="nav-link">
                <i class="bi bi-file-earmark-text"></i> <span>Request Document</span>
            </a>
        </li>

        <li class="nav-item">
            <a href="/Project_A2/pages/resident/request_history.php" class="nav-link">
                <i class="bi bi-clock-history"></i> <span>Request History</span>
            </a>
        </li>

        <li class="nav-item">
            <a href="/Project_A2/pages/resident/support_tickets.php" class="nav-link">
                <i class="bi bi-ticket-perforated"></i> <span>Support Tickets</span>
            </a>
        </li>
    </ul>
</div>

    <!-- NAVBAR -->
    <!-- NAVBAR -->
<nav class="navbar expanded navbar-expand-lg">
    <i class="bi bi-list burger-btn" onclick="toggleSidebar()"></i>
    <span class="navbar-brand"><?php echo getbrgyName('resident'); ?></span>
    
    <!-- Notifications -->
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
                    // Determine the target URL based on notification type
                    $notifUrl = '#'; // default
                    switch($notif['notif_type']) {
                        case 'document':
                            $notifUrl = "/Project_A2/pages/resident/request_history.php?id=" . $notif['notif_entity_id'];
                            break;
                        case 'action':
                            $notifUrl = "/Project_A2/pages/resident/request_history.php?id=" . $notif['notif_entity_id'];
                            break;
                        case 'chat':
                            $notifUrl = "/Project_A2/pages/resident/view_ticket.php?ticket_id=" . $notif['notif_entity_id'];
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
            <h6 class="mb-2">Mark as Read</h6>
            </div>
        </div>
    </div>

    <!-- Profile -->
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
                <span class="badge bg-primary">Resident</span>
            </div>
        </div>

        <div class="sub-menu-wrap" id="subMenu">
            <div class="sub-menu">
                <a href="/Project_A2/pages/resident/profile.php" class="sub-menu-link">
                    <i class="bi bi-person-circle me-2"></i> <p>Profile</p>
                </a>
                <hr>
                <a href="/Project_A2/pages/resident/activity_logs.php" class="sub-menu-link">
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


<!-- MAIN CONTENT -->
<div class="main-content">

</div>

<script>
    // Burger menu toggle
    
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar-wrapper');
    const content = document.querySelector('.main-content');
    const navbar = document.querySelector('.navbar');

    if (window.innerWidth <= 991) {
        sidebar.classList.toggle('mobile-open');
        content.classList.toggle('mobile-open'); // optional if you want overlay effect
        navbar.classList.toggle('mobile-open');  // optional
        return;
    }

    // Desktop toggle
    if (sidebar.classList.contains('minimized')) {
        sidebar.classList.remove('minimized');
        content.classList.remove('expanded');
        navbar.classList.remove('expanded');
    } else {
        sidebar.classList.add('minimized');
        content.classList.add('expanded');
        navbar.classList.add('expanded');
    }
}

document.querySelector('.main-content').addEventListener('click', function() {
    if (window.innerWidth <= 991) {
        const sidebar = document.querySelector('.sidebar-wrapper');
        sidebar.classList.remove('mobile-open');
    }
});
    // Profile dropdown
    const subMenu = document.getElementById("subMenu");
    function toggleMenu(event) {
        event.stopPropagation();

        // Close notification menu
        notifMenu.classList.remove("open-menu");

        // Toggle the profile submenu
        subMenu.classList.toggle("open-menu");
    }

        document.addEventListener("click", function(event) {
        const profileDropdown = document.querySelector(".profile-dropdown");
        if (!profileDropdown.contains(event.target)) {
            subMenu.classList.remove("open-menu");
        }
    });
    document.addEventListener('DOMContentLoaded', function() {
        if (window.innerWidth > 991) {
            document.querySelector('.sidebar-wrapper').classList.add('minimized');
            document.querySelector('.main-content').classList.add('expanded');
            document.querySelector('.navbar').classList.add('expanded');
        }
    });
    document.addEventListener('DOMContentLoaded', function() {
        const currentPath = window.location.pathname;
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
            if (link.getAttribute('href') === currentPath || 
                currentPath.includes(link.getAttribute('href'))) {
                link.classList.add('active');
            }
        });
    });
    // Notifications dropdown
    const notifMenu = document.getElementById("notifMenu");
    function toggleNotifMenu(event) {
    event.stopPropagation();

    // Close the profile submenu
    subMenu.classList.remove("open-menu");

    // Toggle the notification menu
    notifMenu.classList.toggle("open-menu");

        if (window.innerWidth <= 575) {
            notifMenu.style.overflowY = "auto";
        }
    }   

    document.addEventListener("click", function (event) {
        const notifWrap = document.querySelector(".notification-dropdown");
        const profileWrap = document.querySelector(".profile-dropdown");

        if (!notifWrap.contains(event.target)) {
            notifMenu.classList.remove("open-menu");
        }
        if (!profileWrap.contains(event.target)) {
            subMenu.classList.remove("open-menu");
        }
    });
    function markAsRead(notifId) {
        fetch('/Project_A2/includes/mark_notif_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ notif_id: notifId })
        }).then(response => response.json())
        .then(data => {
            if(data.success){
                // Optionally remove "unread" class or update badge
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

</script>

</body>
</html>
