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

// Build SQL WHERE for search/date (user filter applied later)
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





<!-- MODAL -->
<button class="btn btn-dark shadow-lg analytics-btn" data-bs-toggle="modal" data-bs-target="#analyticsModal" title="Analytics Report">
    <i class="fa fa-bar-chart"></i>
</button>

<div class="modal fade" id="analyticsModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white py-2">
        <h6 class="modal-title mb-0"><i class="fa fa-dashboard me-2"></i>Activity Dashboard</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-3">
        <!-- Compact Filter Controls -->
        <div class="card shadow-sm border-0 mb-3">
          <div class="card-body p-3">
            <div class="row g-2 align-items-center">
              <div class="col-md-3">
                <label class="form-label small fw-bold mb-1 text-muted">ROLE</label>
                <select class="form-select form-select-sm" id="roleFilter">
                  <option value="all">All Roles</option>
                  <option value="RESIDENT">Resident</option>
                  <option value="CAPTAIN">Captain</option>
                  <option value="SECRETARY">Secretary</option>
                  <option value="ADMIN">Admin</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-bold mb-1 text-muted">DATE</label>
                <input type="date" class="form-control form-control-sm" id="dateFilter" value="<?php echo date('Y-m-d'); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-bold mb-1 text-muted">USER SEARCH</label>
                <div class="input-group input-group-sm">
                  <input type="text" class="form-control" id="userSearch" placeholder="Search user..." style="display: none;">
                  <button class="btn btn-outline-secondary" type="button" id="clearSearch" style="display: none;">
                    <i class="fa fa-times"></i>
                  </button>
                </div>
                <div class="mt-1" id="searchResults" style="max-height: 120px; overflow-y: auto; display: none;"></div>
              </div>
              <div class="col-md-2 text-end">
                <button class="btn btn-success btn-sm w-100" id="exportExcel">
                  <i class="fa fa-download me-1"></i>Export
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Compact Stats Grid -->
        <div class="row g-2 mb-3">
          <!-- Total Activities -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-primary bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-primary mb-1">
                  <i class="fa fa-history fa-sm"></i>
                </div>
                <h5 class="text-primary mb-0" id="totalActivities">0</h5>
                <small class="text-muted">Total</small>
              </div>
            </div>
          </div>

          <!-- Login Count -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-success bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-success mb-1">
                  <i class="fa fa-sign-in fa-sm"></i>
                </div>
                <h5 class="text-success mb-0" id="loginCount">0</h5>
                <small class="text-muted">Logins</small>
              </div>
            </div>
          </div>

          <!-- Request Count -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-info bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-info mb-1">
                  <i class="fa fa-file-text fa-sm"></i>
                </div>
                <h5 class="text-info mb-0" id="requestCount">0</h5>
                <small class="text-muted">Requests</small>
              </div>
            </div>
          </div>

          <!-- Role Changes -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-warning bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-warning mb-1">
                  <i class="fa fa-user-cog fa-sm"></i>
                </div>
                <h5 class="text-warning mb-0" id="roleChangeCount">0</h5>
                <small class="text-muted">Roles</small>
              </div>
            </div>
          </div>

          <!-- Ticket Appeals -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-danger bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-danger mb-1">
                  <i class="fa fa-ticket fa-sm"></i>
                </div>
                <h5 class="text-danger mb-0" id="ticketAppealCount">0</h5>
                <small class="text-muted">Tickets</small>
              </div>
            </div>
          </div>

          <!-- System Changes -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-dark bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-dark mb-1">
                  <i class="fa fa-cog fa-sm"></i>
                </div>
                <h5 class="text-dark mb-0" id="systemChangeCount">0</h5>
                <small class="text-muted">System</small>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Action Filters -->
        <div class="card shadow-sm border-0 mb-3">
          <div class="card-body p-2">
            <label class="form-label small fw-bold mb-2 text-muted">QUICK FILTERS</label>
            <div class="d-flex flex-wrap gap-1">
              <button class="btn btn-outline-primary btn-sm active action-filter" data-action="all">
                <i class="fa fa-th me-1"></i>All
              </button>
              <button class="btn btn-outline-success btn-sm action-filter" data-action="login">
                <i class="fa fa-sign-in me-1"></i>Logins
              </button>
              <button class="btn btn-outline-info btn-sm action-filter" data-action="request">
                <i class="fa fa-file-text me-1"></i>Requests
              </button>
              <button class="btn btn-outline-warning btn-sm action-filter" data-action="role">
                <i class="fa fa-user-cog me-1"></i>Roles
              </button>
              <button class="btn btn-outline-danger btn-sm action-filter" data-action="ticket">
                <i class="fa fa-ticket me-1"></i>Tickets
              </button>
              <button class="btn btn-outline-dark btn-sm action-filter" data-action="system">
                <i class="fa fa-cog me-1"></i>System
              </button>
            </div>
          </div>
        </div>

        <!-- Mini Activity Chart -->
        <div class="card shadow-sm border-0">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <label class="form-label small fw-bold mb-0 text-muted">ACTIVITY OVERVIEW</label>
              <span class="badge bg-light text-dark small" id="lastUpdate">Just now</span>
            </div>
            <div class="activity-bars d-flex align-items-end gap-1" style="height: 60px;" id="activityChart">
              <!-- Activity bars will be generated here -->
              <div class="text-center small text-muted">
                <div class="bg-primary bg-opacity-25 rounded-top mx-auto" style="width: 12px; height: 20px;"></div>
                <small>Loading...</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer py-2">
        <small class="text-muted me-auto" id="dataSummary">0 activities today</small>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
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



    
//modal
document.addEventListener('DOMContentLoaded', function() {
    let currentActionFilter = 'all';
    let selectedUserId = null;
    let activityData = [];

    // Search Users
    function searchUsers(query, role) {
        if (query.length < 2) {
            document.getElementById('searchResults').style.display = 'none';
            return;
        }

        fetch(`/Project_A2/assets/api/search_users.php?q=${encodeURIComponent(query)}&role=${role}`)
            .then(response => response.json())
            .then(users => {
                const resultsContainer = document.getElementById('searchResults');
                resultsContainer.innerHTML = '';
                
                if (users.length === 0) {
                    resultsContainer.innerHTML = '<div class="text-center text-muted p-1 small">No users found</div>';
                    resultsContainer.style.display = 'block';
                    return;
                }

                users.forEach(user => {
                    const userItem = document.createElement('div');
                    userItem.className = 'user-item p-1 border-bottom cursor-pointer small';
                    userItem.style.cursor = 'pointer';
                    userItem.innerHTML = `
                        <div class="fw-semibold">${user.name}</div>
                        <small class="text-muted">${user.role}</small>
                    `;
                    
                    userItem.addEventListener('click', function() {
                        selectedUserId = user.user_id;
                        document.getElementById('userSearch').value = user.name;
                        resultsContainer.style.display = 'none';
                        loadActivityData();
                    });
                    
                    resultsContainer.appendChild(userItem);
                });
                
                resultsContainer.style.display = 'block';
            })
            .catch(error => {
                console.error('Error searching users:', error);
            });
    }

    // Load Activity Data
    function loadActivityData() {
        const role = document.getElementById('roleFilter').value;
        const date = document.getElementById('dateFilter').value;

        // Show loading state
        document.querySelectorAll('[id$="Count"]').forEach(el => {
            el.textContent = '...';
        });

        const params = new URLSearchParams({
            role: role,
            user_id: selectedUserId || 'all',
            date: date,
            action_type: currentActionFilter
        });

        fetch(`/Project_A2/assets/api/get_activity_data.php?${params}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    activityData = data.activities || [];
                    
                    // Update stats
                    document.getElementById('totalActivities').textContent = data.stats.totalActivities;
                    document.getElementById('loginCount').textContent = data.stats.loginCount;
                    document.getElementById('requestCount').textContent = data.stats.requestCount;
                    document.getElementById('roleChangeCount').textContent = data.stats.roleChangeCount;
                    document.getElementById('ticketAppealCount').textContent = data.stats.ticketAppealCount;
                    document.getElementById('systemChangeCount').textContent = data.stats.systemChangeCount;
                    
                    // Update summary
                    document.getElementById('dataSummary').textContent = 
                        `${data.stats.totalActivities} activities • ${date}`;
                    document.getElementById('lastUpdate').textContent = 'Just now';
                    
                    // Update chart
                    updateActivityChart(data.stats);
                } else {
                    throw new Error(data.error);
                }
            })
            .catch(error => {
                console.error('Error loading activity data:', error);
                // Set default values on error
                document.querySelectorAll('[id$="Count"]').forEach(el => {
                    el.textContent = '0';
                });
            });
    }

    // Update Activity Chart
    function updateActivityChart(stats) {
        const chartContainer = document.getElementById('activityChart');
        const total = stats.totalActivities || 1;
        
        const chartData = [
            { label: 'Logins', value: stats.loginCount, color: 'success' },
            { label: 'Requests', value: stats.requestCount, color: 'info' },
            { label: 'Roles', value: stats.roleChangeCount, color: 'warning' },
            { label: 'Tickets', value: stats.ticketAppealCount, color: 'danger' },
            { label: 'System', value: stats.systemChangeCount, color: 'dark' }
        ];

        chartContainer.innerHTML = '';
        
        chartData.forEach(item => {
            const height = total > 0 ? Math.max((item.value / total) * 50, 5) : 5;
            const bar = document.createElement('div');
            bar.className = 'bar text-center';
            bar.innerHTML = `
                <div class="bg-${item.color} bg-opacity-75 rounded-top mx-auto" 
                     style="width: 10px; height: ${height}px;" 
                     title="${item.label}: ${item.value}">
                </div>
                <small class="text-muted d-block mt-1">${item.value}</small>
            `;
            chartContainer.appendChild(bar);
        });
    }

    // Action Filter Button Handler
    function setupActionFilters() {
        const filterButtons = document.querySelectorAll('.action-filter');
        
        filterButtons.forEach(button => {
            button.addEventListener('click', function() {
                // Remove active class from all buttons
                filterButtons.forEach(btn => btn.classList.remove('active', 'btn-primary'));
                filterButtons.forEach(btn => btn.classList.add('btn-outline-primary'));
                
                // Add active class to clicked button
                this.classList.remove('btn-outline-primary');
                this.classList.add('active', 'btn-primary');
                
                // Update current filter
                currentActionFilter = this.getAttribute('data-action');
                
                // Reload data with new filter
                loadActivityData();
            });
        });
    }

    // Toggle search based on role
    function toggleSearch() {
        const role = document.getElementById('roleFilter').value;
        const searchInput = document.getElementById('userSearch');
        const clearBtn = document.getElementById('clearSearch');
        
        if (role !== 'all') {
            searchInput.style.display = 'block';
            clearBtn.style.display = 'block';
        } else {
            searchInput.style.display = 'none';
            clearBtn.style.display = 'none';
            selectedUserId = null;
            searchInput.value = '';
            document.getElementById('searchResults').style.display = 'none';
        }
    }

    // Initialize modal when opened
    document.getElementById('analyticsModal').addEventListener('show.bs.modal', function () {
        setupActionFilters();
        toggleSearch();
        loadActivityData();
    });

    // Event listeners for filters
    document.getElementById('roleFilter').addEventListener('change', function() {
        toggleSearch();
        loadActivityData();
    });

    document.getElementById('dateFilter').addEventListener('change', loadActivityData);

    // User search functionality
    document.getElementById('userSearch').addEventListener('input', function() {
        const role = document.getElementById('roleFilter').value;
        searchUsers(this.value, role);
    });

    document.getElementById('clearSearch').addEventListener('click', function() {
        document.getElementById('userSearch').value = '';
        document.getElementById('searchResults').style.display = 'none';
        selectedUserId = null;
        loadActivityData();
    });

    // Export functionality
    document.getElementById('exportExcel').addEventListener('click', function() {
        const role = document.getElementById('roleFilter').value;
        const date = document.getElementById('dateFilter').value;

        const originalText = this.innerHTML;
        this.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Exporting...';
        this.disabled = true;
        
        const exportUrl = `/Project_A2/assets/api/export_activity_excel.php?role=${role}&user_id=${selectedUserId || 'all'}&date=${date}&action_type=${currentActionFilter}`;
        
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
        }, 2000);
    });
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

.analytics-btn {
  position: fixed;
  bottom: 20px;
  right: 20px;
  width: 50px;
  height: 50px;
  border-radius: 50%;
  z-index: 1000;
}

.card {
  transition: transform 0.2s ease;
}

.card:hover {
  transform: translateY(-2px);
}

.activity-bars .bar {
  transition: all 0.3s ease;
  cursor: pointer;
  flex: 1;
}

.activity-bars .bar:hover {
  opacity: 0.8;
  transform: scale(1.05);
}

.activity-bars {
  height: 80px !important; 
  gap: 8px !important;
}

.activity-bars .bar .graph-bar {
  width: 20px !important; 
  min-height: 5px; 
  border-radius: 4px 4px 0 0;
  margin: 0 auto;
  transition: height 0.5s ease;
}

.activity-bars .bar small {
  font-size: 0.65rem;
  margin-top: 4px;
  display: block;
}

.btn-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
}

.form-select-sm, .form-control-sm {
  font-size: 0.75rem;
}

.small {
  font-size: 0.7rem;
}
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
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.modal-content {
  animation: fadeIn 0.3s ease-in-out;
}
</style>

<?php include 'footer.php'; ?>