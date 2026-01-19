<?php
require_once '../../includes/config.php';
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
$isAdmin = ($role === 'admin');
if(!$isAdmin){
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BDIS - Admin Dashboard</title>
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
        @media (max-width: 991px) {
            .sidebar-wrapper {
                left: -260px;
            }
            .sidebar-wrapper.mobile-open {
                left: 0;
            }
        }
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
        .burger-btn {
            font-size: 1.8rem;
            cursor: pointer;
            margin-right: 15px;
            z-index: 1100;           
            position: relative;      
        }
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
        }
        .navbar .navbar-brand {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 1rem;
        }
        .rounded-circle{
            width:40px;
            height:40px;
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
                margin-left: 260px; 
            }
            .navbar.mobile-open {
                left: 260px; 
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
        .sidebar-logo img {
            width: 70px;        
            height: 70px;        
            border-radius: 50%;   
            object-fit: cover;    
            display: block;
            margin: 0 auto;      
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
                <?php if (!empty($logoImage)): ?>
                <div class="sidebar-logo">
                    <img src = "<?php echo $logoImage; ?>" alt = "Barangay Logo">
                </div>
                <?php endif; ?>
                <h4 class="sidebar-title">BDIS</h4>
        </div>
        <ul class="nav flex-column ps-2 pe-2 mt-3 sidebar-nav">
            <li class="nav-item">
                <a href="/Project_A2/pages/admin/dashboard.php" class="nav-link">
                    <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
                </a>
            </li>

            <li class="nav-item">
                    <a href="/Project_A2/pages/admin/user_management.php" class="nav-link">
                    <i class="bi bi-people"></i><span>User Management</span>
                </a>
            </li>


            <li class="nav-item">
                <a href="/Project_A2/pages/admin/system_configuration.php" class="nav-link">
                    <i class="bi bi-gear"></i><span>System Configuration</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- NAVBAR -->
    <nav class="navbar expanded navbar-expand-lg">
        <i class="bi bi-list burger-btn" onclick="toggleSidebar()"></i>
        <span class="navbar-brand"><?php echo getbrgyName('admin'); ?></span>
        
    <!-- Notifications -->
    <div class="ms-auto"></div>

        <!-- Profile -->
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
                    <span class="badge bg-danger">Admin</span>
                </div>
            </div>

            <div class="sub-menu-wrap" id="subMenu">
                <div class="sub-menu">
                    <a href="/Project_A2/pages/admin/profile.php" class="sub-menu-link">
                        <i class="bi bi-person-circle me-2"></i> <p>Profile</p>
                    </a>
                    <hr>
                    <a href="/Project_A2/pages/admin/activity_logs.php" class="sub-menu-link">
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
        content.classList.toggle('mobile-open');
        navbar.classList.toggle('mobile-open');
        return;
    }

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

// Profile dropdown only
const subMenu = document.getElementById("subMenu");

function toggleMenu(event) {
    event.stopPropagation();
    subMenu.classList.toggle("open-menu");
}

// Close profile menu when clicking outside
document.addEventListener("click", function(event) {
    const profileWrap = document.querySelector(".profile-dropdown");
    if (!profileWrap.contains(event.target)) {
        subMenu.classList.remove("open-menu");
    }
});

// Auto minimize sidebar on desktop
document.addEventListener('DOMContentLoaded', function() {
    if (window.innerWidth > 991) {
        document.querySelector('.sidebar-wrapper').classList.add('minimized');
        document.querySelector('.main-content').classList.add('expanded');
        document.querySelector('.navbar').classList.add('expanded');
    }
});

// Sidebar active link highlight
document.addEventListener('DOMContentLoaded', function() {
    const currentPath = window.location.pathname;
    document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
        if (link.getAttribute('href') === currentPath 
            || currentPath.includes(link.getAttribute('href'))) {
            link.classList.add('active');
        }
    });
});
</script>

</body>
</html>
