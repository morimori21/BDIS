<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotResident();

    // // Get user info
    // $stmt = $pdo->prepare("SELECT r.*, u.username FROM residents r JOIN users u ON r.user_id = u.user_id WHERE r.user_id = ?");
    // $stmt->execute([$_SESSION['user_id']]);
    // $user = $stmt->fetch();

// Fetch user info and profile picture for header

$stmt = $pdo->prepare("
    SELECT first_name, middle_name, surname, profile_picture
    FROM users
    WHERE user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get email from email table using correct relationship
if ($user) {
    try {
        $emailStmt = $pdo->prepare("
            SELECT e.email 
            FROM email e 
            JOIN account a ON e.email_id = a.email_id 
            WHERE a.user_id = ?
        ");
        $emailStmt->execute([$_SESSION['user_id']]);
        $emailResult = $emailStmt->fetch();
        $user['email'] = $emailResult ? $emailResult['email'] : '';
    } catch (PDOException $e) {
        $user['email'] = '';
    }
}

// Fetch recent notifications for initial render
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
    <link rel="stylesheet" href="/Project_A2/assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/Project_A2/assets/css/old_style.css">
    <script src="../../assets/js/global.js?v=<?php echo time(); ?>"></script>
    <style>
        /* Disable scrolling globally */
        html, body {
            overflow: hidden !important;
            height: 100% !important;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        
        body::-webkit-scrollbar,
        html::-webkit-scrollbar {
            display: none;
        }
        
        /* Fix for header overlap - global fix for all pages */
        .container {
            padding-top: 80px !important;
            margin-top: 0 !important;
        }
        
        /* Modern Pagination Styling */
        .pagination {
            gap: 5px;
        }
        
        .pagination .page-item .page-link {
            border-radius: 6px;
            border: 1px solid #dee2e6;
            color: #495057;
            padding: 0.375rem 0.75rem;
            font-weight: 500;
            transition: all 0.2s ease;
            margin: 0 2px;
        }
        
        .pagination .page-item.active .page-link {
            background-color: #0d6efd;
            border-color: #0d6efd;
            color: white;
            box-shadow: 0 2px 4px rgba(13, 110, 253, 0.3);
        }
        
        .pagination .page-item:not(.active):not(.disabled) .page-link:hover {
            background-color: #e9ecef;
            border-color: #dee2e6;
            color: #0d6efd;
        }
        
        .pagination .page-item.disabled .page-link {
            background-color: #f8f9fa;
            border-color: #dee2e6;
            color: #6c757d;
            opacity: 0.5;
        }
        
        .pagination .page-item:first-child .page-link,
        .pagination .page-item:last-child .page-link {
            border-radius: 6px;
        }
        
        /* Notification dropdown */
        .dropdown-menu {
            z-index: 10000 !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border: 1px solid #dee2e6;
            border-radius: 8px;
            right: 0 !important;
            left: auto !important;
            transform: none !important;
        }
        
        .dropdown-menu.dropdown-menu-end {
            right: 0;
            left: auto;
        }
        
        .notif-item {
            cursor: pointer;
            transition: all 0.2s ease;
            padding: 12px 16px !important;
            border-bottom: 1px solid #f1f1f1;
        }
        
        .notif-item:hover {
            background-color: #f8f9fa;
        }
        
        .notif-item:last-child {
            border-bottom: none;
        }
        
        .notif-item.unread {
            background-color: #e7f3ff;
        }
        
        .notif-item.unread:hover {
            background-color: #d0e8ff;
        }
        
        .notif-icon {
            flex-shrink: 0;
            width: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .notif-topic {
            font-size: 0.9rem;
            font-weight: 500;
            color: #1f2937;
            margin-bottom: 4px;
            line-height: 1.3;
        }
        
        .notif-item.unread .notif-topic {
            font-weight: 600;
        }
        
        .notif-time {
            font-size: 0.75rem;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .unread-dot {
            width: 8px;
            height: 8px;
            background-color: #3b82f6;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 4px;
        }
        
        #notifList {
            max-height: 400px;
            overflow-y: auto;
        }
        
        #notifList::-webkit-scrollbar {
            width: 6px;
        }
        
        #notifList::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        #notifList::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        
        #notifList::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        /* profile dropdown */
        .sub-menu-wrap{
            position: absolute;
            top:100%;
            right: 0;
            width: 280px;
            max-height:0px;
            overflow:hidden;
            transition: max-height 0.5s;
            z-index: 9999;
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
            padding-top: 90px; /* Increased space for fixed navbar */
            margin-left: 280px; /* Align with sidebar width */
        }

        .navbar {
            position: fixed;
            top: 0;
            left: 280px; /* Align with sidebar width */
            right: 0;
            margin-bottom: 0;
            margin-top: 0;
            padding: 0.75rem 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
            z-index: 999; /* Below sidebar z-index which is 1000 */
            background-color: #f8f9fa !important;
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
                        <a href="/Project_A2/pages/resident/dashboard.php" class="nav-link">
                            <i class="bi bi-speedometer2"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="/Project_A2/pages/resident/request_document.php" class="nav-link">
                            <i class="bi bi-file-earmark-text"></i>
                            <span>Request Document</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="/Project_A2/pages/resident/request_history.php" class="nav-link">
                            <i class="bi bi-clock-history"></i>
                            <span>Request History</span>
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="sidebar-footer">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a href="/Project_A2/pages/resident/support_tickets.php" class="nav-link">
                            <i class="bi bi-ticket-perforated"></i>
                            <span>Support Tickets</span>
                            <?php if ($unreadCount > 0): ?>
                                <span class="notification-dot"></span>
                            <?php endif; ?>
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
                    <div class="navbar-brand d-flex align-items-center">
                        <?php 
                        $barangay = getBarangayDetails();
                        $barangayName = $barangay['name'] ?? 'BDIS';
                        $municipality = $barangay['municipality'] ?? '';
                        $province = $barangay['province'] ?? '';
                        $locationParts = array_filter([$barangayName, $municipality, $province]);
                        $locationText = implode(', ', $locationParts);
                        ?>
                        <div class="fw-bold"><?php echo $locationText; ?></div>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <!-- Notification bell -->
                        <div class="dropdown position-relative">
                            <button class="btn btn-light position-relative" id="notifDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-bell" style="font-size: 1.2rem;"></i>
                                <?php if ($unreadCount > 0): ?>
                                    <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?php echo $unreadCount; ?></span>
                                <?php else: ?>
                                    <span id="notifBadge" class="visually-hidden"></span>
                                <?php endif; ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end p-2" aria-labelledby="notifDropdown" style="min-width:320px; max-width: 400px;">
                                <li class="dropdown-header">Notifications</li>
                                <li><hr class="dropdown-divider"></li>
                                <div id="notifList">
                                    <?php if (count($notifications) === 0): ?>
                                        <div class="text-muted px-3">No notifications</div>
                                    <?php else: ?>
                                        <?php foreach ($notifications as $n): ?>
                                            <div class="px-2 py-1 notif-item <?php echo $n['notif_is_read'] ? 'text-muted' : ''; ?>" data-id="<?php echo $n['notif_id']; ?>">
                                                <small class="d-block text-truncate"><?php echo htmlspecialchars($n['notif_topic']); ?></small>
                                                <small class="text-muted"><?php echo date('M j, H:i', strtotime($n['notif_created_at'])); ?></small>
                                                <hr class="my-1">
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <li><hr class="dropdown-divider"></li>
                                <li class="px-2"><button id="markAllRead" class="btn btn-sm btn-outline-primary w-100">Mark all as read</button></li>
                            </ul>
                        </div>

                        <!-- Profile dropdown -->
                        <div class="profile-dropdown position-relative" onclick="toggleMenu(event)" style="cursor: pointer; user-select: none;">
                            <div class="d-flex align-items-center gap-2">
                                <?php if (!empty($user['profile_picture'])): ?>
                                    <img src="/Project_A2/uploads/<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture"
                                        class="rounded-circle" style="width: 40px; height: 40px; object-fit: cover; border: 2px solid #f1f1f1;">
                                <?php else: ?>
                                    <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center"
                                        style="width: 40px; height: 40px;">
                                        <i class="bi bi-person-fill text-white"></i>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex flex-column align-items-start">
                                    <span class="fw-semibold"><?php echo ucwords(htmlspecialchars($user['first_name'] ?? 'User')); ?></span>
                                    <span class="badge bg-primary">Resident</span>
                                </div>
                            </div>

                            <div class="sub-menu-wrap" id="subMenu">
                                <div class="sub-menu">
                                    <div class="user-info">
                                        <?php if (!empty($user['profile_picture'])): ?>
                                            <img src="/Project_A2/uploads/<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile"
                                                class="rounded-circle me-2" style="width: 50px; height: 50px; object-fit: cover;">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center me-2"
                                                style="width: 50px; height: 50px;">
                                                <i class="bi bi-person-fill text-white"></i>
                                            </div>
                                        <?php endif; ?>
                                        <h6 class="ms-2 mb-0"><?php echo htmlspecialchars($user['first_name'] ?? 'User'); ?></h6>
                                    </div>
                                    <hr>
                                    <a href="/Project_A2/pages/resident/profile.php" class="sub-menu-link">
                                        <i class="bi bi-person-circle"></i>
                                        <p>Profile</p><span>›</span>
                                    </a>
                                    <a href="/Project_A2/pages/resident/settings.php" class="sub-menu-link">
                                        <i class="bi bi-gear-fill"></i>
                                        <p>Settings</p><span>›</span>
                                    </a>
                                    <hr>
                                    <a href="/Project_A2/logout.php" class="sub-menu-link" style="color:red;">
                                        <i class="bi bi-box-arrow-right"></i>
                                        <p>Logout</p><span>›</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </nav>

      <script>
        document.addEventListener('DOMContentLoaded', function(){
            console.log('DOM loaded');
            
            const fetchUrl = '/Project_A2/pages/resident/fetch_notifications.php';
            const markUrl = '/Project_A2/pages/resident/mark_notification.php';
            const notifBadge = document.getElementById('notifBadge');
            const notifList = document.getElementById('notifList');
            const markAllBtn = document.getElementById('markAllRead');
            const dropdownToggle = document.getElementById('notifDropdown');

            console.log('Notification button:', dropdownToggle);

            const subMenu = document.getElementById("subMenu");
            const profileDropdown = document.querySelector(".profile-dropdown");

            async function fetchNotifs(){
                try{
                    const res = await fetch(fetchUrl, { credentials: 'same-origin' });
                    if(!res.ok) return;
                    const data = await res.json();
                    
                    if(data.unread && data.unread > 0){
                        notifBadge.classList.remove('visually-hidden');
                        notifBadge.textContent = data.unread;
                        notifBadge.classList.add('position-absolute','top-0','start-100','translate-middle','badge','rounded-pill','bg-danger');
                    } else {
                        notifBadge.classList.add('visually-hidden');
                        notifBadge.textContent = '';
                    }
                    
                    if(!data.notifications || data.notifications.length === 0){
                        notifList.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-bell-slash fs-3 d-block mb-2"></i><small>No notifications</small></div>';
                        return;
                    }
                    notifList.innerHTML = '';
                    data.notifications.forEach(n => {
                        const div = document.createElement('div');
                        div.className = 'notif-item ' + (!n.notif_is_read ? 'unread' : '');
                        div.dataset.id = n.notif_id;
                        
                        // Get icon based on notification type
                        let icon = 'bi-bell-fill';
                        let iconColor = '#1e3a8a';
                        if (n.notif_type === 'document') {
                            icon = 'bi-file-earmark-text-fill';
                            iconColor = '#059669';
                        } else if (n.notif_type === 'ticket') {
                            icon = 'bi-ticket-perforated-fill';
                            iconColor = '#dc2626';
                        }
                        
                        // Format time ago
                        const timeAgo = formatTimeAgo(n.notif_created_at);
                        
                        div.innerHTML = `
                            <div class="d-flex align-items-start gap-2">
                                <div class="notif-icon" style="color: ${iconColor}">
                                    <i class="${icon} fs-5"></i>
                                </div>
                                <div class="flex-grow-1" style="min-width: 0;">
                                    <div class="notif-topic">${escapeHtml(n.notif_topic)}</div>
                                    <div class="notif-time">
                                        <i class="bi bi-clock"></i> ${timeAgo}
                                    </div>
                                </div>
                                ${!n.notif_is_read ? '<div class="unread-dot"></div>' : ''}
                            </div>
                        `;
                        div.addEventListener('click', () => markAsRead(n.notif_id));
                        notifList.appendChild(div);
                    });
                }catch(e){
                    console.error('fetch notifications', e);
                }
            }

            async function markAsRead(id){
                try{
                    const form = new FormData();
                    if(id) form.append('notif_id', id);
                    const res = await fetch(markUrl, { method: 'POST', body: form, credentials: 'same-origin' });
                    if(res.ok) fetchNotifs();
                }catch(e){
                    console.error('mark as read', e);
                }
            }

            markAllBtn.addEventListener('click', function(e){
                e.preventDefault();
                markAsRead(null);
            });

            if (dropdownToggle) {
                console.log('Setting up notification dropdown');
                
                // Remove Bootstrap's data-bs-toggle and use manual control
                dropdownToggle.removeAttribute('data-bs-toggle');
                
                // Manual click handler
                dropdownToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Notification button clicked');
                    
                    // Close profile menu if open
                    if (subMenu) {
                        subMenu.classList.remove("open-menu");
                    }
                    
                    // Toggle dropdown
                    const dropdownMenu = dropdownToggle.nextElementSibling;
                    if (dropdownMenu && dropdownMenu.classList.contains('dropdown-menu')) {
                        const isShowing = dropdownMenu.classList.contains('show');
                        
                        // Close all other dropdowns
                        document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                            menu.classList.remove('show');
                        });
                        
                        if (!isShowing) {
                            dropdownMenu.classList.add('show');
                            dropdownToggle.setAttribute('aria-expanded', 'true');
                            fetchNotifs(); // Fetch notifications when opening
                        } else {
                            dropdownMenu.classList.remove('show');
                            dropdownToggle.setAttribute('aria-expanded', 'false');
                        }
                    }
                });
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                if (dropdownToggle && !dropdownToggle.closest('.dropdown').contains(event.target)) {
                    const dropdownMenu = dropdownToggle.nextElementSibling;
                    if (dropdownMenu && dropdownMenu.classList.contains('show')) {
                        dropdownMenu.classList.remove('show');
                        dropdownToggle.setAttribute('aria-expanded', 'false');
                    }
                }
                
                // Also close profile dropdown
                if (profileDropdown && !profileDropdown.contains(event.target)) {
                    subMenu.classList.remove("open-menu");
                }
            });

            window.toggleMenu = function(event) {
                event.stopPropagation();
                // Close notification dropdown if open
                const notifDropdownEl = document.querySelector('#notifDropdown');
                if (notifDropdownEl) {
                    const dropdownMenu = notifDropdownEl.nextElementSibling;
                    if (dropdownMenu && dropdownMenu.classList.contains('show')) {
                        dropdownMenu.classList.remove('show');
                        notifDropdownEl.setAttribute('aria-expanded', 'false');
                    }
                }
                subMenu.classList.toggle("open-menu");
            }

            document.addEventListener("click", function(event) {
                if (!profileDropdown.contains(event.target)) {
                    subMenu.classList.remove("open-menu");
                }
            });
            
            // Highlight active nav link
            const currentPath = window.location.pathname;
            document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
                if (link.getAttribute('href') === currentPath || 
                    currentPath.includes(link.getAttribute('href'))) {
                    link.classList.add('active');
                }
            });

            fetchNotifs();
            setInterval(fetchNotifs, 10000);

            function escapeHtml(unsafe) {
                return (unsafe || '').toString()
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }
            
            function formatTimeAgo(dateStr) {
                const date = new Date(dateStr);
                const now = new Date();
                const seconds = Math.floor((now - date) / 1000);
                
                if (seconds < 60) return 'Just now';
                const minutes = Math.floor(seconds / 60);
                if (minutes < 60) return `${minutes}m ago`;
                const hours = Math.floor(minutes / 60);
                if (hours < 24) return `${hours}h ago`;
                const days = Math.floor(hours / 24);
                if (days < 7) return `${days}d ago`;
                const weeks = Math.floor(days / 7);
                if (weeks < 4) return `${weeks}w ago`;
                return date.toLocaleDateString();
            }
        });
        </script>
