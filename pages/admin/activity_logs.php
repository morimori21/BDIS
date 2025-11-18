<?php include 'header.php'; ?>
<head>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-history me-2"></i>Activity Logs</h2>
    </div>

    <!-- Activity Statistics -->
   <div class="row mb-4" id="activityStats">
    <?php
        $activity_stats = [
            'today' => [
                'label' => 'Today',
                'count' => $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(action_time) = CURDATE()")->fetchColumn(),
            ],
            'yesterday' => [
                'label' => 'Yesterday',
                'count' => $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(action_time) = CURDATE() - INTERVAL 1 DAY")->fetchColumn(),
            ],
            'week' => [
                'label' => 'This Week',
                'count' => $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
            ],
            'month' => [
                'label' => 'This Month',
                'count' => $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE MONTH(action_time) = MONTH(CURDATE()) AND YEAR(action_time) = YEAR(CURDATE())")->fetchColumn(),
            ],
        ];
    ?>

    <?php foreach ($activity_stats as $key => $stat): ?>
        <div class="col-md-3 mb-3">
            <div class="card stat-card border-primary text-center clickable-stat"
                 data-filter="<?= $key ?>">
                <div class="card-body">
                    <div class="display-6 mb-2">📝</div>
                    <h4 class="fw-bold mb-1"><?= number_format($stat['count']) ?></h4>
                    <p class="mb-0 text-muted"><?= htmlspecialchars($stat['label']) ?></p>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>


    <!-- Activity Logs Table -->
    <div class="card">
        <div id="logsHeader" class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Recent Activity</h5>
            <div class="d-flex align-items-center">
                <?php
                    $entriesOptions = [10,25,50,100];
                    $entriesPerPage = isset($_GET['entries']) && in_array((int)$_GET['entries'],$entriesOptions) ? (int)$_GET['entries'] : 10;
                    $search = $_GET['search'] ?? '';
                    $currentPage = isset($_GET['page']) && $_GET['page']>0 ? (int)$_GET['page'] : 1;
                    $date = $_GET['date'] ?? '';
                ?>
                <select id="entriesPerPage" class="form-select form-select-sm w-auto me-2" onchange="loadLogs(1)">
                    <?php foreach($entriesOptions as $opt): ?>
                        <option value="<?= $opt ?>" <?= $entriesPerPage==$opt?'selected':'' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="text" id="searchInput" class="form-control form-control-sm me-2"
                       placeholder="Search User, Action, Type..." value="<?= htmlspecialchars($search) ?>">

                <input type="text" id="calendarPicker" class="form-control form-control-sm me-2"
                       placeholder="Filter by Date" autocomplete="off" value="<?= htmlspecialchars($date) ?>">
            </div>
        </div>

        <div class="card-body" id="logsContainer">
            <div class="table-responsive">
                <table class="table table-borderless align-middle">
                    <thead class="table-light">
                        <tr>
                            <th width="10%" style="padding: 0.75rem; color: #dc3545;">Today</th>
                            <th width="20%" style="padding: 0.75rem; color: #dc3545;">User</th>
                            <th width="35%" style="padding: 0.75rem; color: #dc3545;">Action</th>
                            <th width="15%" class="text-center" style="padding: 0.75rem;">Role</th>
                            <th width="20%" class="text-center" style="padding: 0.75rem;">Summary</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                        // Build filter query
                        $searchQuery = '';
                        $params = [];
                        if ($search) {
                            $searchQuery = "WHERE (u.first_name LIKE :search OR u.surname LIKE :search OR al.action LIKE :search OR al.action_details LIKE :search)";
                            $params[':search'] = "%$search%";
                        }
                        if ($date) {
                            if ($date === 'WEEK') {
                                $searchQuery .= ($searchQuery ? ' AND ' : 'WHERE ') . 'al.action_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                            } elseif ($date === 'MONTH') {
                                $searchQuery .= ($searchQuery ? ' AND ' : 'WHERE ') . 'MONTH(al.action_time) = MONTH(CURDATE()) AND YEAR(al.action_time) = YEAR(CURDATE())';
                            } else {
                                $searchQuery .= ($searchQuery ? ' AND ' : 'WHERE ') . 'DATE(al.action_time) = :date';
                                $params[':date'] = $date;
                            }
                        }


                        // Count total
                        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON al.user_id=u.user_id $searchQuery");
                        $countStmt->execute($params);
                        $totalLogs = $countStmt->fetchColumn();
                        $totalPages = max(1, ceil($totalLogs / $entriesPerPage));
                        $offset = ($currentPage-1) * $entriesPerPage;

                        // Fetch logs
                        $stmt = $pdo->prepare("
                            SELECT al.*, u.first_name, u.surname, ur.role, u.profile_picture
                            FROM activity_logs al
                            LEFT JOIN users u ON al.user_id=u.user_id
                            LEFT JOIN user_roles ur ON u.user_id=ur.user_id
                            $searchQuery
                            ORDER BY al.action_time DESC
                            LIMIT :offset, :limit
                        ");
                        foreach($params as $k=>$v) $stmt->bindValue($k,$v,PDO::PARAM_STR);
                        $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
                        $stmt->bindValue(':limit',$entriesPerPage,PDO::PARAM_INT);
                        $stmt->execute();
                        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        if (!$logs): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-info-circle me-2"></i>No logs found.</td></tr>
                        <?php
                        else:
                            $currentDate = '';
                            $isFirstInGroup = true;
                            foreach($logs as $log):
                                $dateLabel = date('j F Y', strtotime($log['action_time']));
                                $time = date('g:i A', strtotime($log['action_time']));
                                $fullName = trim($log['first_name'] . ' ' . $log['surname']);
                                $username = htmlspecialchars($fullName ?: 'System');
                                $role = ucfirst($log['role'] ?? 'System');
                                $action = htmlspecialchars($log['action']);
                                $details = htmlspecialchars($log['action_details']);

                                //format
                                $formattedName = ucwords(strtolower($username));
                                
                                // Check if date changed
                                if ($dateLabel !== $currentDate):
                                    $currentDate = $dateLabel;
                                    $isFirstInGroup = true;
                                endif;
                        ?>
                        <tr style="background-color: <?= $isFirstInGroup ? '#f8f9fa' : '#ffffff' ?>;">
                            <td style="padding: 0.75rem;">
                                <?php if ($isFirstInGroup): 
                                    $header = ($dateLabel == date('j F Y')) ? 'Today' : $dateLabel;
                                    echo '<span class="text-muted">' . htmlspecialchars($header) . '</span>';
                                    $isFirstInGroup = false;
                                endif; ?>
                            </td>
                            <td style="padding: 0.75rem;">
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($log['profile_picture'])): ?>
                                        <?php $imgSrc = 'data:image/jpeg;base64,'.base64_encode($log['profile_picture']); ?>
                                        <img src="<?= $imgSrc ?>" class="rounded-circle me-2" width="32" height="32" style="object-fit:cover;">
                                    <?php else: ?>
                                        <div class="avatar-xs bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 12px;">
                                            <?= strtoupper(substr($username,0,2)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-semibold" style="font-size: 0.9rem;"><?= $formattedName ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding: 0.75rem;">
                                <div><?= $details ?></div>
                                <small class="text-muted"><?= $time ?></small>
                            </td>
                            <td class="text-center" style="padding: 0.75rem; vertical-align: middle;"><?= $role ?></td>
                            <td class="text-center" style="padding: 0.75rem; vertical-align: middle;"><?= $action ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <nav>
                <ul class="pagination justify-content-center">
                    <?php for($p=1;$p<=$totalPages;$p++): ?>
                        <li class="page-item <?= $currentPage==$p?'active':'' ?>">
                            <a class="page-link" href="#" data-page="<?= $p ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    </div>
</div>

<script>
let searchTimer;

function loadLogs(page = 1, selectedDate = '') {
    const search = encodeURIComponent(document.getElementById('searchInput').value);
    const entries = document.getElementById('entriesPerPage').value;
    const date = selectedDate || document.getElementById('calendarPicker').value;
    const params = `page=${page}&entries=${entries}&search=${search}&date=${encodeURIComponent(date)}`;

    fetch(window.location.pathname + '?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            document.getElementById('logsContainer').innerHTML = doc.getElementById('logsContainer').innerHTML;
            document.getElementById('logsHeader').scrollIntoView({ behavior: 'smooth' });

            // re-bind pagination
            document.querySelectorAll('#logsContainer .pagination a').forEach(link => {
                link.addEventListener('click', e => {
                    e.preventDefault();
                    loadLogs(link.dataset.page);
                });
            });
        });
}

document.getElementById('searchInput').addEventListener('keyup', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadLogs(1), 400);
});

flatpickr("#calendarPicker", {
    dateFormat: "Y-m-d",
    allowInput: true,
    onChange: (dates, dateStr) => loadLogs(1, dateStr)
});

document.querySelectorAll('#logsContainer .pagination a').forEach(link => {
    link.addEventListener('click', e => {
        e.preventDefault();
        loadLogs(link.dataset.page);
    });
});




document.querySelectorAll('.clickable-stat').forEach(card => {
    card.addEventListener('click', () => {
        const filter = card.dataset.filter;
        let dateFilter = '';

        if (filter === 'today') {
    // Today's date
    dateFilter = new Date().toISOString().split('T')[0];
    document.getElementById('calendarPicker').value = dateFilter;
    loadLogs(1, dateFilter);
    } 
    else if (filter === 'yesterday') {
        // Yesterday's date
        const d = new Date();
        d.setDate(d.getDate() - 1);
        const yesterday = d.toISOString().split('T')[0];
        document.getElementById('calendarPicker').value = yesterday;
        loadLogs(1, yesterday);
    }
    else if (filter === 'week') {
        // Last 7 days
        document.getElementById('calendarPicker').value = '';
        loadLogs(1, 'WEEK');
    } 
    else if (filter === 'month') {
        // Current month
        document.getElementById('calendarPicker').value = '';
        loadLogs(1, 'MONTH');
    }


        // Add small animation to indicate it's active
        document.querySelectorAll('.clickable-stat').forEach(c => c.classList.remove('active-stat'));
        card.classList.add('active-stat');
    });
});

// Enhance the loadLogs() to handle WEEK/MONTH filters
const originalLoadLogs = loadLogs;
loadLogs = function(page = 1, selectedDate = '') {
    const search = encodeURIComponent(document.getElementById('searchInput').value);
    const entries = document.getElementById('entriesPerPage').value;
    const dateParam = selectedDate || document.getElementById('calendarPicker').value;

    const params = new URLSearchParams({
        page,
        entries,
        search,
        date: dateParam
    });

    fetch(window.location.pathname + '?' + params.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.text())
    .then(html => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        document.getElementById('logsContainer').innerHTML = doc.getElementById('logsContainer').innerHTML;
        document.getElementById('logsHeader').scrollIntoView({ behavior: 'smooth' });

        // Re-bind pagination
        document.querySelectorAll('#logsContainer .pagination a').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                loadLogs(link.dataset.page);
            });
        });
    });
};



</script>

<style>
.avatar-sm {
    width:40px; height:40px; font-size:14px; font-weight:bold;
}
.table-borderless tbody tr td {
    border-bottom: 1px solid #f0f0f0;
}
.table-secondary td {
    background:#f8f9fa; font-weight:600;
}
.card {
    box-shadow:0 2px 4px rgba(0,0,0,0.1); border:none;
}

.stat-card {
    border-radius: 12px;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    cursor: pointer;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.active-stat {
    background-color: #0d6efd !important;
    color: #fff !important;
    border-color: #0d6efd !important;
}
</style>

<?php include 'footer.php'; ?>
