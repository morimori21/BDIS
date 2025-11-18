<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('captain');

// // Get user info
// $stmt = $pdo->prepare("SELECT u.username FROM users u WHERE u.user_id = ?");
// $stmt->execute([$_SESSION['user_id']]);
// $user = $stmt->fetch();


// fetch user info and profile img for header drop menu OOOOOLLLDDDD
// $stmt = $pdo->prepare("
//     SELECT u.username, r.profile_picture
//     FROM users u
//     LEFT JOIN residents r ON u.user_id = r.user_id
//     WHERE u.user_id = ?
// ");
// $stmt->execute([$_SESSION['user_id']]);
// $user = $stmt->fetch(PDO::FETCH_ASSOC);



$stmt = $pdo->prepare("
    SELECT u.first_name, u.middle_name, u.surname, u.profile_picture, e.email as email
    FROM users u
    LEFT JOIN account a ON u.user_id = a.user_id
    LEFT JOIN email e ON a.email_id = e.email_id
    WHERE u.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BDIS - Captain Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/Project_A2/assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/Project_A2/assets/css/old_style.css">
    <style>
        /* profile dropdown */
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
            margin-right: 20px;
        }
        
        .main-content {
            padding-top: 0;
        }

        .navbar {
            margin-bottom: 0;
            margin-top: 0;
            padding: 0.75rem 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
            z-index: 1000;
        }

        .navbar-brand {
            font-size: 1.1rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar-wrapper d-flex flex-column">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <?php echo Logo('sidebar'); ?>
            </div>
            <h4 class="sidebar-title">BDIS</h4>
        </div>
            
            <div class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a href="/Project_A2/pages/captain/dashboard.php" class="nav-link">
                            <i class="bi bi-speedometer2"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="/Project_A2/pages/captain/documents_for_signing.php" class="nav-link">
                            <i class="bi bi-pen"></i>
                            <span>Documents for Signing</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="/Project_A2/pages/captain/manage_schedule.php" class="nav-link">
                            <i class="bi bi-calendar-check"></i>
                            <span>Manage Schedule</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <nav class="navbar navbar-expand-lg navbar-light bg-light">
            <div class="container-fluid">
                <span class="navbar-brand"><?php echo getNavbarBrand('captain'); ?></span>
                
                    <!-- Profile Dropdown -->
                    <div class="profile-dropdown position-relative" onclick="toggleMenu(event)" style="cursor: pointer; user-select: none;">
                        <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($user['profile_picture'])): ?>
                                <img 
                                    src="/Project_A2/uploads/<?php echo htmlspecialchars($user['profile_picture']); ?>" 
                                    alt="Profile Picture"
                                    class="rounded-circle"
                                    style="width: 40px; height: 40px; object-fit: cover; border: 2px solid #f1f1f1;"
                                >
                            <?php else: ?>
                                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center"
                                    style="width: 40px; height: 40px;">
                                    <i class="bi bi-person-fill text-white"></i>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex flex-column align-items-start">
                                <span class="fw-semibold">
                                    <?php echo ucwords(htmlspecialchars($user['first_name'] ?? 'User')); ?>
                                </span>
                                <span class="badge bg-info">
                                    Captain
                                </span>
                            </div>
                        </div>
                        
                        <div class="sub-menu-wrap" id="subMenu">
                            <div class="sub-menu">
                                <div class="user-info">
                                    <?php if (!empty($user['profile_picture'])): ?>
                                        <img src="/Project_A2/uploads/<?php echo htmlspecialchars($user['profile_picture']); ?>"
                                            alt="Profile"
                                            class="rounded-circle me-2"
                                            style="width: 50px; height: 50px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center me-2"
                                            style="width: 50px; height: 50px;">
                                            <i class="bi bi-person-fill text-white"></i>
                                        </div>
                                    <?php endif; ?>
                                    <h6 class="ms-2 mb-0"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['surname'] ?? 'User'); ?></h6>
                                </div>

                                <hr>
                                <a href="/Project_A2/pages/captain/profile.php" class="sub-menu-link">
                                    <i class="bi bi-person-circle"></i>
                                    <p>Profile</p>
                                    <span>›</span>
                                </a>
                                <!-- Settings removed -->
                                <hr>
                                <a href="/Project_A2/logout.php" class="sub-menu-link" style="color:red;">
                                    <i class="bi bi-box-arrow-right"></i>
                                    <p>Logout</p>
                                    <span>›</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </nav>

<script>
    const subMenu = document.getElementById("subMenu");

    function toggleMenu(event) {
        event.stopPropagation(); 
        subMenu.classList.toggle("open-menu");
    }

    document.addEventListener("click", function(event) {
        const profileDropdown = document.querySelector(".profile-dropdown");
        if (!profileDropdown.contains(event.target)) {
            subMenu.classList.remove("open-menu");
        }
    });
    
    // Highlight active nav link
    document.addEventListener('DOMContentLoaded', function() {
        const currentPath = window.location.pathname;
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
            if (link.getAttribute('href') === currentPath || 
                currentPath.includes(link.getAttribute('href'))) {
                link.classList.add('active');
            }
        });
    });

    // Auto-hide any success alerts after 1 second (captain pages)
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            document.querySelectorAll('.alert.alert-success').forEach(function(el){
                if (!el) return;
                try {
                    if (window.bootstrap && bootstrap.Alert) {
                        var alert = bootstrap.Alert.getOrCreateInstance(el);
                        alert.close();
                    } else {
                        el.classList.remove('show');
                        el.parentNode && el.parentNode.removeChild(el);
                    }
                } catch (e) {
                    el.classList.remove('show');
                    el.parentNode && el.parentNode.removeChild(el);
                }
            });
        }, 1000);
    });
</script>