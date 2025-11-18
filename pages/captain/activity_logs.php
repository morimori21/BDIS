<?php 
include 'header.php';

// Get filter and pagination parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build WHERE clause based on filter
$where_clause = "1=1";
switch($filter) {
    case 'today':
        $where_clause = "DATE(al.action_time) = CURDATE()";
        break;
    case 'week':
        $where_clause = "al.action_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        break;
    case 'login':
        $where_clause = "(al.action LIKE '%logged in%' OR al.action LIKE '%Logged in%' OR al.action LIKE '%login%' OR al.action LIKE '%Login%')";
        break;
    case 'logout':
        $where_clause = "(al.action LIKE '%logged out%' OR al.action LIKE '%Logged out%' OR al.action LIKE '%logout%' OR al.action LIKE '%Logout%')";
        break;
    case 'document':
        $where_clause = "(al.action LIKE '%document%' OR al.action LIKE '%Document%')";
        break;
    case 'system':
        $where_clause = "(al.action LIKE '%system%' OR al.action LIKE '%System%' OR al.action LIKE '%settings%' OR al.action LIKE '%Settings%' OR al.action LIKE '%configuration%' OR al.action LIKE '%barangay%' OR al.action LIKE '%Barangay%' OR al.action LIKE '%Updated%' OR al.action LIKE '%updated%')";
        break;
    case 'password':
        $where_clause = "(al.action LIKE '%password%' OR al.action LIKE '%Password%')";
        break;
    case 'verification':
        $where_clause = "(al.action LIKE '%verify%' OR al.action LIKE '%Verify%' OR al.action LIKE '%reject%' OR al.action LIKE '%Reject%')";
        break;
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) FROM activity_logs al WHERE $where_clause";
$total_logs = $pdo->query($count_query)->fetchColumn();
$total_pages = ceil($total_logs / $limit);

// Get activity logs with user information
$logs_query = "
    SELECT al.*, 
           u.first_name, 
           u.surname, 
           COALESCE(ur.role, 'resident') as user_role
    FROM activity_logs al 
    LEFT JOIN users u ON al.user_id = u.user_id
    LEFT JOIN user_roles ur ON u.user_id = ur.user_id
    WHERE $where_clause
    ORDER BY al.action_time DESC 
    LIMIT $limit OFFSET $offset
";
$logs_stmt = $pdo->query($logs_query);
$logs = $logs_stmt->fetchAll();

// Get statistics
$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn(),
    'today' => $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(action_time) = CURDATE()")->fetchColumn(),
    'week' => $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
    'logins' => $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action LIKE '%login%' AND action_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn()
];
?>

<div class="container">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0"><i class="bi bi-clock-history me-2"></i>Activity Logs</h2>
    </div>

    <!-- Statistics Cards -->
    

    <!-- Filter Buttons -->
    <div class="card mb-4">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap gap-1">
                <a href="?filter=all" class="btn btn-sm <?php echo $filter == 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                    <i class="bi bi-list-ul me-1"></i>All
                </a>
                <a href="?filter=login" class="btn btn-sm <?php echo $filter == 'login' ? 'btn-success' : 'btn-outline-success'; ?>">
                    <i class="bi bi-box-arrow-in-right me-1"></i>User Login
                </a>
                <a href="?filter=logout" class="btn btn-sm <?php echo $filter == 'logout' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                    <i class="bi bi-box-arrow-left me-1"></i>User Logout
                </a>
                <a href="?filter=document" class="btn btn-sm <?php echo $filter == 'document' ? 'btn-info' : 'btn-outline-info'; ?>">
                    <i class="bi bi-file-earmark me-1"></i>Document Changes
                </a>
                <a href="?filter=system" class="btn btn-sm <?php echo $filter == 'system' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                    <i class="bi bi-gear me-1"></i>System Changes
                </a>
                <a href="?filter=password" class="btn btn-sm <?php echo $filter == 'password' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">
                    <i class="bi bi-key me-1"></i>Password Changes
                </a>
                <a href="?filter=verification" class="btn btn-sm <?php echo $filter == 'verification' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                    <i class="bi bi-check-circle me-1"></i>Verifications
                </a>
            </div>
        </div>
    </div>

    <!-- Activity Logs Table -->
    <div class="card">
        <div class="card-header bg-primary text-white py-2">
            <h6 class="mb-0">
                <i class="bi bi-clock-history me-2"></i>
                Activity Logs 
                <?php if ($filter != 'all'): ?>
                    - <?php echo ucfirst($filter); ?>
                <?php endif; ?>
                (<?php echo number_format($total_logs); ?> entries)
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>Date & Time</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <i class="bi bi-inbox" style="font-size: 2rem;"></i><br>
                                No activity logs found for this filter.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($logs as $log): 
                            // Build full name
                            $fullName = trim(($log['first_name'] ?? '') . ' ' . ($log['surname'] ?? ''));
                            if (empty($fullName)) {
                                $fullName = 'System';
                            }
                            
                            // Build initials
                            $initials = '';
                            if (!empty($log['first_name'])) {
                                $initials = strtoupper(substr($log['first_name'], 0, 1));
                                if (!empty($log['surname'])) {
                                    $initials .= strtoupper(substr($log['surname'], 0, 1));
                                }
                            } else {
                                $initials = 'SY';
                            }
                            
                            // Determine action type, icon, and badge color
                            $action = $log['action'];
                            $actionType = 'General';
                            $actionIcon = 'bi-circle-fill';
                            $badgeClass = 'secondary';
                            
                            // Check for logout first (since "logged out" contains "log")
                            if (stripos($action, 'logged out') !== false || stripos($action, 'logout') !== false) {
                                $actionType = 'Logout';
                                $actionIcon = 'bi-box-arrow-left';
                                $badgeClass = 'warning';
                            } elseif (stripos($action, 'logged in') !== false || stripos($action, 'login') !== false) {
                                $actionType = 'Login';
                                $actionIcon = 'bi-box-arrow-in-right';
                                $badgeClass = 'success';
                            } elseif (stripos($action, 'document') !== false || stripos($action, 'request') !== false) {
                                $actionType = 'Document';
                                $actionIcon = 'bi-file-earmark';
                                $badgeClass = 'info';
                            } elseif (stripos($action, 'system') !== false || stripos($action, 'settings') !== false || stripos($action, 'configuration') !== false || stripos($action, 'barangay') !== false || stripos($action, 'updated') !== false) {
                                $actionType = 'System';
                                $actionIcon = 'bi-gear';
                                $badgeClass = 'danger';
                            } elseif (stripos($action, 'password') !== false) {
                                $actionType = 'Password';
                                $actionIcon = 'bi-key';
                                $badgeClass = 'dark';
                            } elseif (stripos($action, 'verify') !== false || stripos($action, 'approve') !== false) {
                                $actionType = 'Verified';
                                $actionIcon = 'bi-check-circle';
                                $badgeClass = 'success';
                            } elseif (stripos($action, 'reject') !== false) {
                                $actionType = 'Rejected';
                                $actionIcon = 'bi-x-circle';
                                $badgeClass = 'danger';
                            }
                            
                            // Role colors
                            $roleColors = [
                                'admin' => 'danger',
                                'secretary' => 'info',
                                'captain' => 'warning',
                                'resident' => 'secondary'
                            ];
                            $roleColor = $roleColors[$log['user_role']] ?? 'secondary';
                            
                            // Role icons
                            $roleIcons = [
                                'admin' => 'bi-shield-fill-check',
                                'secretary' => 'bi-pencil-square',
                                'captain' => 'bi-person-badge-fill',
                                'resident' => 'bi-house-fill'
                            ];
                            $roleIcon = $roleIcons[$log['user_role']] ?? 'bi-person-fill';
                        ?>
                        <tr>
                            <td>
                                <div class='d-flex align-items-center'>
                                    <div class='avatar-sm bg-<?php echo $roleColor; ?> text-white d-flex align-items-center justify-content-center me-2' style='border-radius: 8px; width: 35px; height: 35px; font-size: 12px; font-weight: bold;'>
                                        <?php echo htmlspecialchars($initials); ?>
                                    </div>
                                    <div>
                                        <div><strong><?php echo htmlspecialchars($fullName); ?></strong></div>
                                        <small class='text-muted'>
                                            <i class='<?php echo $roleIcon; ?> me-1'></i><?php echo ucfirst($log['user_role']); ?>
                                        </small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class='d-flex align-items-center'>
                                    <i class='<?php echo $actionIcon; ?> me-2 text-<?php echo $badgeClass; ?>'></i>
                                    <span><?php echo htmlspecialchars($action); ?></span>
                                </div>
                            </td>
                            <td>
                                <small class='text-muted'>
                                    <?php 
                                    $details = $log['action_details'];
                                    if (!empty($details)) {
                                        echo htmlspecialchars(strlen($details) > 50 ? substr($details, 0, 50) . '...' : $details);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </small>
                            </td>
                            <td>
                                <div>
                                    <strong><?php echo date('M j, Y', strtotime($log['action_time'])); ?></strong><br>
                                    <small class='text-muted'><?php echo date('g:i A', strtotime($log['action_time'])); ?></small>
                                </div>
                            </td>
                            <td>
                                <span class='badge bg-<?php echo $badgeClass; ?>'>
                                    <i class='<?php echo $actionIcon; ?> me-1'></i><?php echo $actionType; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Activity logs pagination" class="mt-3">
        <ul class="pagination justify-content-center">
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="?filter=<?php echo $filter; ?>&page=<?php echo $page - 1; ?>">Previous</a>
            </li>
            <?php 
            // Show limited page numbers (max 10 pages visible)
            $max_visible = 10;
            $start_page = max(1, min($page - floor($max_visible / 2), $total_pages - $max_visible + 1));
            $end_page = min($total_pages, $start_page + $max_visible - 1);
            
            for ($i = $start_page; $i <= $end_page; $i++): 
            ?>
            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                <a class="page-link" href="?filter=<?php echo $filter; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                <a class="page-link" href="?filter=<?php echo $filter; ?>&page=<?php echo $page + 1; ?>">Next</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<style>
    /* Compact table styling - no scrolling */
    .container {
        max-height: calc(100vh - 80px);
        overflow: hidden !important;
    }
    
    .table {
        font-size: 0.7rem !important;
        margin-bottom: 0 !important;
    }
    
    .table td, .table th {
        padding: 0.3rem !important;
        white-space: nowrap;
    }
    
    .table thead th {
        font-size: 0.7rem;
        padding: 0.4rem !important;
    }
    
    .table .badge {
        font-size: 0.65rem;
        padding: 0.2rem 0.4rem;
    }
    
    .table .avatar-sm {
        width: 28px !important;
        height: 28px !important;
        font-size: 0.65rem !important;
    }
    
    .table small {
        font-size: 0.65rem;
    }
    
    .table strong {
        font-size: 0.7rem;
    }
    
    .card-body {
        padding: 0.5rem !important;
    }
    
    .stat-card {
        border: none;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border-radius: 8px;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    }
    
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
    }
    
    .stat-icon i {
        font-size: 1.5rem;
    }
    
    .stat-number {
        font-weight: 600;
        font-size: 1.5rem;
        color: #212529;
        margin-bottom: 0.5rem;
    }
    
    .stat-label {
        font-weight: 500;
        font-size: 0.85rem;
        color: #495057;
        margin: 0;
    }
    
    .stat-card-link {
        text-decoration: none;
        color: inherit;
        display: block;
        transition: all 0.3s ease;
    }
    
    .stat-card-link:hover {
        text-decoration: none;
        color: inherit;
    }
    
    .card {
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        border: none;
        border-radius: 8px;
    }
    
    .card-header {
        border-radius: 8px 8px 0 0 !important;
        padding: 0.5rem 1rem !important;
    }
    
    .btn-sm {
        font-size: 0.7rem;
        padding: 0.2rem 0.5rem;
    }
</style>

<?php include 'footer.php'; ?>