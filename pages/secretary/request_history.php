<?php include 'header.php'; ?>

<div class="container">
    <h2>📋 Document Request History</h2>
    
    <!-- Filter and Search -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Status Filter</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo ($_GET['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo ($_GET['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="signed" <?php echo ($_GET['status'] ?? '') === 'signed' ? 'selected' : ''; ?>>Signed</option>
                                <option value="printed" <?php echo ($_GET['status'] ?? '') === 'printed' ? 'selected' : ''; ?>>Printed</option>
                                <option value="completed" <?php echo ($_GET['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="rejected" <?php echo ($_GET['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Document Type</label>
                            <select name="doc_type" class="form-select">
                                <option value="">All Types</option>
                                <?php
                                $stmt = $pdo->query("SELECT DISTINCT dt.doc_type_id, dt.doc_name FROM document_types dt JOIN document_requests dr ON dt.doc_type_id = dr.doc_type_id WHERE dr.resident_id = {$_SESSION['user_id']} ORDER BY dt.doc_name");
                                while ($type = $stmt->fetch()) {
                                    $selected = ($_GET['doc_type'] ?? '') == $type['doc_type_id'] ? 'selected' : '';
                                    echo "<option value='{$type['doc_type_id']}' {$selected}>{$type['doc_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date Range</label>
                            <select name="date_range" class="form-select">
                                <option value="">All Time</option>
                                <option value="today" <?php echo ($_GET['date_range'] ?? '') === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="week" <?php echo ($_GET['date_range'] ?? '') === 'week' ? 'selected' : ''; ?>>This Week</option>
                                <option value="month" <?php echo ($_GET['date_range'] ?? '') === 'month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="quarter" <?php echo ($_GET['date_range'] ?? '') === 'quarter' ? 'selected' : ''; ?>>This Quarter</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">🔍 Filter</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Summary Cards -->
    <div class="row mb-4">
        <?php
        $baseQuery = "FROM document_requests dr JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id WHERE dr.resident_id = ?";
        $params = [$_SESSION['user_id']];
        
        // Apply filters
        $whereConditions = [];
        if (!empty($_GET['status'])) {
            $whereConditions[] = "dr.request_status = ?";
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['doc_type'])) {
            $whereConditions[] = "dr.doc_type_id = ?";
            $params[] = $_GET['doc_type'];
        }
        if (!empty($_GET['date_range'])) {
            switch ($_GET['date_range']) {
                case 'today':
                    $whereConditions[] = "DATE(dr.date_requested) = CURDATE()";
                    break;
                case 'week':
                    $whereConditions[] = "WEEK(dr.date_requested) = WEEK(CURDATE()) AND YEAR(dr.date_requested) = YEAR(CURDATE())";
                    break;
                case 'month':
                    $whereConditions[] = "MONTH(dr.date_requested) = MONTH(CURDATE()) AND YEAR(dr.date_requested) = YEAR(CURDATE())";
                    break;
                case 'quarter':
                    $whereConditions[] = "QUARTER(dr.date_requested) = QUARTER(CURDATE()) AND YEAR(dr.date_requested) = YEAR(CURDATE())";
                    break;
            }
        }
        
        if (!empty($whereConditions)) {
            $baseQuery .= " AND " . implode(" AND ", $whereConditions);
        }
        
        // Get summary statistics
        $totalQuery = "SELECT COUNT(*) $baseQuery";
        $pendingQuery = "SELECT COUNT(*) $baseQuery AND dr.request_status = 'pending'";
        $completedQuery = "SELECT COUNT(*) $baseQuery AND dr.request_status = 'completed'";
        $costQuery = "SELECT COALESCE(SUM(dt.doc_price), 0) $baseQuery";
        
        // Execute statistics queries
        $stmt = $pdo->prepare($totalQuery);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare($pendingQuery);
        $stmt->execute($params);
        $pending = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare($completedQuery);
        $stmt->execute($params);
        $completed = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare($costQuery);
        $stmt->execute($params);
        $total_cost = $stmt->fetchColumn();
        ?>
        
    
    <!-- Requests Table -->
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Your Document Requests</h5>
            <a href="request_document.php" class="btn btn-light btn-sm">
                📤 New Request
            </a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Document Type</th>
                            <th>Purpose</th>
                            <th>Status</th>
                            <th>Cost</th>
                            <th>Date Requested</th>
                            <th>Pickup Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $query = "SELECT dr.*, dt.doc_name, dt.doc_price $baseQuery ORDER BY dr.date_requested DESC";
                        $stmt = $pdo->prepare($query);
                        $stmt->execute($params);
                        
                        if ($stmt->rowCount() === 0) {
                            echo "<tr><td colspan='8' class='text-center text-muted'>No requests found matching your criteria.</td></tr>";
                        }
                        
                        while ($request = $stmt->fetch()) {
                            // Prepare pickup display: show pickup representative or schedule info  
                            $pickupDisplay = htmlspecialchars($request['pickup_representative'] ?? 'Not set');
                            if (!empty($request['schedule_id'])) {
                                $sstmt = $pdo->prepare("SELECT schedule_date FROM schedule WHERE schedule_id = ?");
                                $sstmt->execute([$request['schedule_id']]);
                                $sch = $sstmt->fetch();
                                if ($sch) {
                                    $pickupDisplay = date('M j, Y', strtotime($sch['schedule_date']));
                                }
                            }
                            
                            // Status badge styling
                            $statusBadge = '';
                            switch ($request['request_status']) {
                                case 'completed':
                                    $statusBadge = 'success';
                                    break;
                                case 'rejected':
                                    $statusBadge = 'danger';
                                    break;
                                case 'for-signing':
                                    $statusBadge = 'info';
                                    break;
                                case 'signed':
                                    $statusBadge = 'info';
                                    break;
                                case 'printed':
                                    $statusBadge = 'primary';
                                    break;
                                case 'approved':
                                    $statusBadge = 'success';
                                    break;
                                default:
                                    $statusBadge = 'warning';
                            }
                            
                            echo "<tr>";
                            echo "<td><strong>#{$request['request_id']}</strong></td>";
                            echo "<td>" . htmlspecialchars($request['doc_name']) . "</td>";
                            echo "<td>" . htmlspecialchars(substr($request['request_purpose'] ?? '', 0, 50)) . (strlen($request['request_purpose'] ?? '') > 50 ? '...' : '') . "</td>";
                            echo "<td><span class='badge bg-{$statusBadge}'>" . htmlspecialchars(ucfirst($request['request_status'])) . "</span></td>";
                            echo "<td class='text-success'>₱" . number_format($request['doc_price'], 2) . "</td>";
                            echo "<td>" . date('M j, Y', strtotime($request['date_requested'])) . "<br><small class='text-muted'>" . date('g:i A', strtotime($request['date_requested'])) . "</small></td>";
                            echo "<td>" . $pickupDisplay . "</td>";
                            echo "<td>";
                            echo "<button class='btn btn-sm btn-outline-primary' onclick=\"showPreview({$request['request_id']})\">👁️ View</button>";
                            echo "</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="previewContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function showPreview(requestId) {
    console.log('showPreview called:', requestId);
    // Load request details via AJAX
    fetch(`get_request_details.php?id=${requestId}`)
        .then(response => response.text())
        .then(data => {
            document.getElementById('previewContent').innerHTML = data;
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Loading Error',
                text: 'Error loading preview'
            });
        });
}

// Auto-submit form when filters change
document.querySelectorAll('select[name="status"], select[name="doc_type"], select[name="date_range"]').forEach(select => {
    select.addEventListener('change', function() {
        this.form.submit();
    });
});
</script>

<?php include 'footer.php'; ?>
