<?php
require_once 'header.php'; 
if(!isset($_SESSION['user_id'])){ 
    header('location: /Project_A2/login.php');
}

// Check if user is secretary
$role = getUserRole(); 
if(!in_array($role, ['secretary'])){
    header('location: /Project_A2/index.php');
    exit();
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

// Get ALL notifications sorted by date (newest first) - NOT limited to 10
$notifStmt = $pdo->prepare("
    SELECT notif_id, notif_type, notif_topic, notif_entity_id, notif_created_at, notif_is_read
    FROM notifications 
    WHERE user_id = ?
    ORDER BY notif_created_at DESC
");
$notifStmt->execute([$_SESSION['user_id']]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

// Deduplicate notifications by key (type+topic+entity+timestamp to minute)
$unique = [];
$deduped = [];
foreach ($notifications as $n) {
    $key = ($n['notif_type'] ?? '') . '|' . ($n['notif_topic'] ?? '') . '|' . ($n['notif_entity_id'] ?? '') . '|' . date('Y-m-d H:i', strtotime($n['notif_created_at']));
    if (!isset($unique[$key])) {
        $unique[$key] = true;
        $deduped[] = $n;
    }
}
$notifications = $deduped;

// Count unread notifications
$unreadCount = 0;
foreach ($notifications as $n) {
    if (!$n['notif_is_read']) $unreadCount++;
}

// Function to get notification URL for secretary
function getNotificationUrl($notif_type, $notif_entity_id, $role) {
    switch($notif_type) {
        case 'document_request':
        case 'document':
        case 'action':
            return "/Project_A2/pages/secretary/document_management.php?id=" . $notif_entity_id;
        case 'ticket_open':
        case 'chat':
            return "/Project_A2/pages/secretary/support_tickets.php?ticket_id=" . $notif_entity_id;
        default:
            return "#";
    }
}

// Dummy functions for Logo and Baranggay Name - replace with your actual implementation
if (!function_exists('Logo')) {
    function Logo($context) {
        // Placeholder path - replace with your actual path
        return '<img src="../../assets/logo.png" alt="Logo">';
    }
}
?>

<div class="main-content expanded">
        <div class="container-fluid py-4">
            <div class="row">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="mb-0">Notifications</h4>
                        <?php if($unreadCount > 0): ?>
                            <button class="btn btn-sm btn-outline-success" onclick="markAllAsRead()">
                                <i class="bi bi-check-lg"></i> Mark All as Read
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Simple Notifications List -->
                    <div class="card">
                        <div class="card-body p-0">
                            <?php if(count($notifications) > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach($notifications as $notif): 
                                        $notifUrl = getNotificationUrl($notif['notif_type'], $notif['notif_entity_id'], $role);
                                    ?>
                                        <div class="list-group-item px-0 py-2 border-0">
                                            <div class="notif-item <?php echo $notif['notif_is_read'] ? '' : 'unread'; ?>" 
                                                 onclick="handleNotificationClick(<?php echo $notif['notif_id']; ?>, '<?php echo $notifUrl; ?>')">
                                                <div class="d-flex align-items-start justify-content-between">
                                                    <div class="flex-grow-1">
                                                        <div class="d-flex align-items-center mb-1">
                                                            <strong class="me-2"><?php echo htmlspecialchars($notif['notif_topic']); ?></strong>
                                                            <?php if(!$notif['notif_is_read']): ?>
                                                                <span class="badge bg-primary">New</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <small class="text-muted">
                                                            <i class="bi bi-clock"></i> 
                                                            <?php echo date('M d, Y \a\t h:i A', strtotime($notif['notif_created_at'])); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-bell-slash fs-1 text-muted"></i>
                                    <p class="text-muted mt-2 mb-0">No notifications found</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
        function handleNotificationClick(notifId, notifUrl) {
            fetch('/Project_A2/includes/mark_notif_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notif_id: notifId })
            })
            .then(res => res.json())
            .then(data => {
                if(data.success){
                    const notifItem = document.querySelector(`[onclick*="handleNotificationClick(${notifId},"]`);
                    if(notifItem) {
                        notifItem.classList.remove('unread');
                        const badge = notifItem.querySelector('.badge');
                        if(badge) badge.remove();
                    }
                    
                    if (notifUrl && notifUrl !== '#') {
                        window.location.href = notifUrl;
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (notifUrl && notifUrl !== '#') {
                    window.location.href = notifUrl;
                }
            });
        }

        function markAllAsRead() {
            // Get all unread notification IDs
            const unreadNotifIds = [];
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                const onclickAttr = item.getAttribute('onclick');
                if (onclickAttr) {
                    const match = onclickAttr.match(/handleNotificationClick\((\d+),/);
                    if (match) {
                        unreadNotifIds.push(parseInt(match[1]));
                    }
                }
            });

            if (unreadNotifIds.length === 0) {
                return;
            }

            // Send request to mark all as read
            fetch('/Project_A2/includes/mark_all_notif_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    notif_ids: unreadNotifIds, 
                    user_id: <?php echo $_SESSION['user_id']; ?> 
                })
            })
            .then(res => res.json())
            .then(data => {
                if(data.success){
                    // Remove unread styling from all notifications
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                        const badge = item.querySelector('.notification-badge');
                        if(badge) badge.remove();
                    });
                    
                    // Hide the mark all button
                    document.querySelector('.btn-outline-success').style.display = 'none';
                    
                    // Show success message
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'All notifications marked as read',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 2000,
                        timerProgressBar: true
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to mark notifications as read',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Network error occurred',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true
                });
            });
        }
    </script>