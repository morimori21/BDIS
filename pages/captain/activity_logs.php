<?php
include 'header.php';
require_once '../../includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /Project_A2/login.php');
    exit;
}

$currentUserId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'user';
$isAdmin = ($userRole === 'admin'); 
// Module classification 
function classifyModule($action, $details = '') {
    $text = strtolower($action . ' ' . $details);

    $usersKeywords = ['approve', 'reject', 'register', 'change role', 'role', 'verification', 'verify', 'approve user', 'reject user', 'changed role'];
    foreach ($usersKeywords as $kw) if (str_contains($text, $kw)) return 'Users';

    $requestsKeywords = ['request', 'document', 'printed', 'signed', 'pickup', 'date_requested', 'request_purpose'];
    foreach ($requestsKeywords as $kw) if (str_contains($text, $kw)) return 'Requests';

    $ticketKeywords = ['ticket', 'support', 'chat', 'message', 'support ticket'];
    foreach ($ticketKeywords as $kw) if (str_contains($text, $kw)) return 'Tickets';

    $accountKeywords = ['login', 'logged in', 'logout', 'logged out', 'password', 'email', 'passkey', 'forgot password'];
    foreach ($accountKeywords as $kw) if (str_contains($text, $kw)) return 'Account';

    return 'System';
}

// Desired module display order
$module_order = ['Users', 'Requests', 'Tickets', 'Account', 'System'];

// --- Read filters (from query string / AJAX) ---
$entriesOptions = [10,25,50,100];
$entriesPerPage = isset($_GET['entries']) && in_array((int)$_GET['entries'],$entriesOptions) ? (int)$_GET['entries'] : 10;
$search = $_GET['search'] ?? '';
$currentPage = isset($_GET['page']) && $_GET['page']>0 ? (int)$_GET['page'] : 1;
$date = $_GET['date'] ?? '';

// Build SQL WHERE for search/date 
$wherePieces = [];
$params = [];

if ($search) {
    // Add logic to search user's first name, surname, action, and details
    $wherePieces[] = "(u.first_name LIKE :search OR u.surname LIKE :search OR al.action LIKE :search OR al.action_details LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($date) {
    if ($date === 'WEEK') {
        $wherePieces[] = "al.action_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($date === 'MONTH') {
        $wherePieces[] = "MONTH(al.action_time) = MONTH(CURDATE()) AND YEAR(al.action_time) = YEAR(CURDATE())";
    } else {
        $wherePieces[] = "DATE(al.action_time) = :date";
        $params[':date'] = $date;
    }
}

// Apply user filter (unless admin)
if (!$isAdmin) {
    $wherePieces[] = "al.user_id = :uid";
    $params[':uid'] = $currentUserId;
}

$whereSQL = '';
if (!empty($wherePieces)) {
    $whereSQL = 'WHERE ' . implode(' AND ', $wherePieces);
}

// --- Fetch all matching logs 

$fetchSql = "
    SELECT al.*, u.first_name, u.surname, ur.role, u.profile_picture
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    LEFT JOIN user_roles ur ON u.user_id = ur.user_id
    $whereSQL
    ORDER BY al.action_time DESC
";

$fetchStmt = $pdo->prepare($fetchSql);
foreach ($params as $k=>$v) {
    // use INT for uid, strings otherwise
    if ($k === ':uid') $fetchStmt->bindValue($k, $v, PDO::PARAM_INT);
    else $fetchStmt->bindValue($k, $v, PDO::PARAM_STR);
}
$fetchStmt->execute();
$allLogs = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

// Group logs into modules -> dates
$grouped = [];
foreach ($allLogs as $log) {
    $module = classifyModule($log['action'] ?? '', $log['action_details'] ?? '');
    $dateLabel = date('j F Y', strtotime($log['action_time']));
    if ($dateLabel == date('j F Y')) $dateLabel = 'Today';

    if (!isset($grouped[$module])) $grouped[$module] = [];
    if (!isset($grouped[$module][$dateLabel])) $grouped[$module][$dateLabel] = [];
    $grouped[$module][$dateLabel][] = $log;
}

// Module counts (total items per module)
$module_counts = [];
foreach ($module_order as $m) {
    $module_counts[$m] = isset($grouped[$m]) ? array_sum(array_map('count', $grouped[$m])) : 0;
}
?>

<head>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"> 
</head>

<div class="container">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
        <h2>Activity Logs</h2>
        <button class="btn btn-primary mt-2 mt-md-0" type="button" data-bs-toggle="collapse" data-bs-target="#filterPanel" aria-expanded="false" aria-controls="filterPanel">
            <i class="fas fa-filter me-1"></i> Filters
        </button>
    </div>

    <div class="collapse mb-4" id="filterPanel">
        <div class="card card-body shadow-sm">
            <form action="" method="GET" class="row g-3">
                
                <div class="col-12 col-md-6 col-lg-5">
                    <label for="search" class="form-label">Search Keywords</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="search" name="search" placeholder="User, Action, Details..." value="<?= htmlspecialchars($search) ?>">
                        <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search"></i></button>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-5">
                    <label for="datePicker" class="form-label">Filter by Date</label>
                    <div class="input-group">
                        <input type="text" class="form-control flatpickr" id="datePicker" placeholder="Select a specific date..." name="date_input" value="<?= $date && $date !== 'WEEK' && $date !== 'MONTH' ? htmlspecialchars($date) : '' ?>">
                        <button class="btn btn-outline-danger" type="button" onclick="clearDateFilter()"><i class="fas fa-times"></i></button>
                    </div>
                    <small class="form-text text-muted">Use the quick filters below if needed.</small>
                </div>

                <div class="col-12 col-md-12 col-lg-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 me-2"><i class="fas fa-check me-1"></i> Apply</button>
                    <a href="activity_logs.php" class="btn btn-outline-secondary w-100"><i class="fas fa-undo me-1"></i> Reset</a>
                </div>
            </form>
            
            <hr class="mt-4 mb-2">
            
            <div class="d-flex flex-wrap justify-content-start align-items-center pt-2">
                <span class="me-3 mb-2 fw-semibold">Quick Filters:</span>
                <?php
                // Defining quick filters manually since the stat computation is removed
                $quick_filters = [
                    'today' => 'Today',
                    'yesterday' => 'Yesterday',
                    'WEEK' => 'Last 7 Days',
                    'MONTH' => 'This Month',
                ];
                ?>
                <?php foreach ($quick_filters as $key => $label): ?>
                    <button type="button" class="btn btn-sm btn-outline-info me-2 mb-2 clickable-filter <?= ($date === $key) ? 'active' : '' ?>" data-filter="<?= $key ?>">
                        <?= htmlspecialchars($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-body p-0">
            <?php foreach ($module_order as $moduleName): 
                $count = $module_counts[$moduleName] ?? 0;
            ?>
                <div class="module-block mb-0 border-bottom" data-module="<?= htmlspecialchars($moduleName) ?>">
                    <div class="module-header d-flex justify-content-between align-items-center p-3"
                          role="button" aria-expanded="true">
                        <div class="d-flex align-items-center">
                            <span class="module-arrow me-2">▼</span>
                            <strong class="me-2"><?= htmlspecialchars($moduleName) ?></strong>
                            <span class="badge bg-primary rounded-pill"><?= $count ?></span>
                        </div>
                        <div class="text-muted small d-none d-md-block">Click to collapse/expand</div>
                    </div>

                    <div class="module-body" style="display:block;">
                        <?php
                        if (empty($grouped[$moduleName])) {
                            echo '<div class="p-4 text-muted border-top">No activity found in this area.</div>';
                        } else {
                            // For each date group inside module
                            foreach ($grouped[$moduleName] as $dateLabel => $items) {
                                echo '<div class="date-group mb-0">';
                                echo '<div class="date-header fw-semibold p-3 border-top" style="background:#f8f9fa;">' . htmlspecialchars($dateLabel) . '</div>';
                                echo '<div class="list-group list-group-flush">';
                                foreach ($items as $log) {
                                    $time = date('g:i A', strtotime($log['action_time']));
                                    $fullName = trim($log['first_name'] . ' ' . $log['surname']);
                                    $username = $fullName ?: 'System';
                                    $formattedName = ucwords(strtolower(htmlspecialchars($username)));
                                    $role = ucfirst($log['role'] ?? 'System');
                                    $action = htmlspecialchars($log['action']);
                                    $details = htmlspecialchars($log['action_details'] ?? '');

                                    // avatar
                                    $imgHtml = '';
                                    if (!empty($log['profile_picture'])) {
                                        $imgSrc = 'data:image/jpeg;base64,' . base64_encode($log['profile_picture']);
                                        $imgHtml = '<img src="'. $imgSrc .'" class="rounded-circle me-3" width="40" height="40" style="object-fit:cover;">';
                                    } else {
                                        $initials = strtoupper(substr($username, 0, 2));
                                        $imgHtml = '<div class="avatar-xs bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width:40px;height:40px;font-size:13px;">' . htmlspecialchars($initials) . '</div>';
                                    }

                                    echo '<div class="list-item d-flex align-items-start p-3 border-bottom">';
                                    // left: avatar
                                    echo '<div class="me-2">' . $imgHtml . '</div>';

                                    // middle: content
                                    echo '<div class="flex-grow-1">';
                                    echo '<div class="d-flex justify-content-between flex-column flex-sm-row">'; // Added flex-column for mobile
                                    echo '<div><span class="fw-semibold">' . $formattedName . '</span> <small class="text-muted d-block d-sm-inline">· ' . htmlspecialchars($role) . '</small></div>'; // d-block on mobile
                                    echo '<div><small class="text-muted">' . htmlspecialchars($time) . '</small></div>';
                                    echo '</div>'; // end header
                                    echo '<div class="mt-1"><strong>' . $action . '</strong></div>';
                                    if ($details) echo '<div class="text-muted small mt-1">' . $details . '</div>';
                                    echo '</div>'; // end middle

                                    echo '</div>'; // end list-item
                                }
                                echo '</div>'; // end list-group
                                echo '</div>'; // end date-group
                            }
                        }
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<script>
    // Helper function to clear the current filter and reload
    function clearDateFilter() {
        const url = new URL(window.location);
        url.searchParams.delete('date');
        url.searchParams.delete('date_input');
        window.location.href = url.toString();
    }

    document.addEventListener('DOMContentLoaded', () => {
        // --- Module Collapse/Expand Logic ---
        document.querySelectorAll('.module-block').forEach(block => {
            const header = block.querySelector('.module-header');
            const body = block.querySelector('.module-body');
            const arrow = block.querySelector('.module-arrow');

            // ensure expanded initial state
            body.style.display = 'block';
            arrow.textContent = '▼';
            header.setAttribute('aria-expanded', 'true');

            header.addEventListener('click', () => {
                const isVisible = body.style.display !== 'none';
                if (isVisible) {
                    // collapse
                    body.style.display = 'none';
                    arrow.textContent = '▶';
                    header.setAttribute('aria-expanded', 'false');
                } else {
                    // expand
                    body.style.display = 'block';
                    arrow.textContent = '▼';
                    header.setAttribute('aria-expanded', 'true');
                }
            });
        });

        // --- Quick Filter Button Clicks: Filter by Date ---
        document.querySelectorAll('.clickable-filter').forEach(button => {
            button.addEventListener('click', () => {
                const filter = button.dataset.filter;
                const currentUrl = new URL(window.location);
                
                // Clear existing date/date_input first
                currentUrl.searchParams.delete('date');
                currentUrl.searchParams.delete('date_input');
                
                let dateParam = filter;

                if (filter === 'today') {
                    // Convert 'today' filter to a specific date for consistent handling on PHP side
                    dateParam = new Date().toISOString().split('T')[0];
                } else if (filter === 'yesterday') {
                    const d = new Date();
                    d.setDate(d.getDate() - 1);
                    dateParam = d.toISOString().split('T')[0];
                }
                
                currentUrl.searchParams.set('date', dateParam);
                window.location.href = currentUrl.toString();
            });
        });

        // --- Flatpickr Initialization ---
        const datePickerElement = document.getElementById("datePicker");
        if (typeof flatpickr !== 'undefined' && datePickerElement) {
            flatpickr(datePickerElement, {
                dateFormat: "Y-m-d",
                allowInput: true,
                // The form submission handles the filtering
            });
            
            // If a specific date is set in the URL, ensure the form input reflects it
            const url = new URL(window.location);
            const dateInput = url.searchParams.get('date');
            if(dateInput && dateInput !== 'WEEK' && dateInput !== 'MONTH') {
                 datePickerElement._flatpickr.setDate(dateInput, true); 
            }
        }
        
        // --- Ensure search results keep the filter panel open ---
        const url = new URL(window.location);
        if (url.searchParams.has('search') || url.searchParams.has('date') || url.searchParams.has('entries') || url.searchParams.has('date_input')) {
            const filterPanel = document.getElementById('filterPanel');
            if (filterPanel) {
                filterPanel.classList.add('show');
                filterPanel.setAttribute('aria-expanded', 'true');
            }
        }
    });
</script>

<style>
    .module-header {
        background: #ffffff;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 10px;
        cursor: pointer;
    }
    .module-header:hover { 
        background:#f8f9fa; 
    }
    .module-arrow { 
        display:inline-block; 
        width:18px; 
        text-align:center; 
    }
    .list-item { 
        background: #fff; 
    }
    .avatar-xs { display:inline-flex; 
        align-items:center; 
        justify-content:center; 
        border-radius:50%; 
    }
        .clickable-filter.active {
            background-color: var(--bs-info);
            color: white;
        }
        .module-block:last-child {
            border-bottom: none !important;
        }
        .module-header {
            border: none !important;
            border-radius: 0 !important;
        }
    @media (max-width: 767.98px) { 
        .container {
            padding-left: 10px;
            padding-right: 10px;
        }
        .module-header {
            padding: 10px 15px;
        }
        .list-item {
            padding: 10px 15px !important;
        }
        .date-header {
            padding: 8px 15px !important;
            font-size: 0.9rem;
        }
        .list-item .flex-grow-1 .d-flex {
            align-items: flex-start !important; 
        }
    }
    @media (min-width: 992px) { 
        .container {
            max-width: 1000px !important;
            margin-left: auto !important;
            margin-right: auto !important;
        }
    }
    @media (max-width: 767.98px) { 
        .container {
            padding-left: 10px;
            padding-right: 10px;
        }
    }
</style>

<?php include 'footer.php'; ?>