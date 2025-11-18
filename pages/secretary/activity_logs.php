<?php include 'header.php'; ?>
<head>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>

<div class="container">
    <!-- Title Section ->
    <div class="mb-2">
        <h4>
            <i class="fas fa-folder-open me-2"></i>Activity Logs - 
            <span class="badge bg-secondary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">All Logs</span>
        </h4>
    </div>

    <!-- Activity Statistics -->



    <!-- Activity Logs Table -->
    <div class="card shadow-sm" style="border: none;">
        <div id="logsHeader" class="card-header text-white d-flex justify-content-between align-items-center" style="background-color: #343a40; padding: 0.6rem 1rem;">
            <h6 class="mb-0"><i class="fas fa-clock me-2"></i>Recent Activity</h6>
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

        <div class="card-body p-0" id="logsContainer">
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm" style="margin-bottom: 0;">
                    <thead style="background-color: #343a40; color: white;">
                        <tr>
                            <th width="25%" class="py-1" style="font-size: 0.85rem; color: #dc3545;">User</th>
                            <th width="40%" class="py-1" style="font-size: 0.85rem; color: #dc3545;">Action</th>
                            <th width="15%" class="text-center py-1" style="font-size: 0.85rem;">Role</th>
                            <th width="20%" class="text-center py-1" style="font-size: 0.85rem;">Summary</th>
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
                            <tr><td colspan="4" class="text-center text-muted py-4"><i class="fas fa-info-circle me-2"></i>No logs found.</td></tr>
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
                            <td class="py-1">
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($log['profile_picture'])): ?>
                                        <?php $imgSrc = 'data:image/jpeg;base64,'.base64_encode($log['profile_picture']); ?>
                                        <img src="<?= $imgSrc ?>" class="rounded-circle me-2" width="28" height="28" style="object-fit:cover;">
                                    <?php else: ?>
                                        <div class="avatar-xs bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 28px; height: 28px; font-size: 11px;">
                                            <?= strtoupper(substr($username,0,2)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-semibold" style="font-size: 0.8rem;"><?= $formattedName ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-1">
                                <div style="font-size: 0.8rem;"><?= $details ?></div>
                                <small class="text-muted" style="font-size: 0.7rem;"><?= $time ?></small>
                            </td>
                            <td class="text-center py-1" style="font-size: 0.8rem; vertical-align: middle;"><?= $role ?></td>
                            <td class="text-center py-1" style="font-size: 0.8rem; vertical-align: middle;"><?= $action ?></td>
                        </tr>
                        <?php 
                                $isFirstInGroup = false;
                            endforeach; 
                        endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Section -->
            <div class="d-flex justify-content-between align-items-center mt-1 px-3 pb-2">
                <div class="text-muted" style="font-size: 0.8rem;">
                    Showing <?= min($offset + 1, $totalLogs) ?> to <?= min($offset + $entriesPerPage, $totalLogs) ?> of <?= $totalLogs ?> entries
                </div>
                <nav>
                    <ul class="pagination mb-0">
                        <!-- Previous Button -->
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="#" data-page="<?= $currentPage - 1 ?>" style="color: #6c757d;">Previous</a>
                        </li>
                        
                        <!-- Page Numbers -->
                        <?php 
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $currentPage + 2);
                        
                        for($p = $startPage; $p <= $endPage; $p++): 
                        ?>
                            <li class="page-item <?= $currentPage == $p ? 'active' : '' ?>">
                                <a class="page-link" href="#" data-page="<?= $p ?>" 
                                   style="<?= $currentPage == $p ? 'background-color: #007bff; border-color: #007bff; color: white;' : 'color: #6c757d;' ?>">
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <!-- Next Button -->
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="#" data-page="<?= $currentPage + 1 ?>" style="color: #6c757d;">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
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
    width: 28px; 
    height: 28px; 
    font-size: 11px; 
    font-weight: bold;
}

/* Table styling with alternating rows */
.table tbody tr {
    border-bottom: 1px solid #dee2e6;
}

.table tbody tr:nth-child(even) {
    background-color: #f8f9fa;
}

.table tbody tr:nth-child(odd) {
    background-color: #ffffff;
}

.table tbody tr:hover {
    background-color: #e9ecef !important;
}

.table-secondary td {
    background: #f8f9fa; 
    font-weight: 600;
    padding: 0.25rem !important;
    font-size: 0.75rem;
}

/* Compact table */
.table-sm td, .table-sm th {
    padding: 0.25rem 0.5rem;
    vertical-align: middle;
}

.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
    border: none;
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

/* Pagination styling */
.pagination .page-link {
    border: 1px solid #dee2e6;
    margin: 0 1px;
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
}

.pagination .page-item.active .page-link {
    background-color: #007bff;
    border-color: #007bff;
    color: white;
}

.pagination .page-item.disabled .page-link {
    color: #6c757d;
    background-color: #fff;
    border-color: #dee2e6;
}
</style>

<?php include 'footer.php'; ?>
