<?php include 'header.php';?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"> 
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<div class="container">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
        <h2 class="mb-0">Document Request History</h2>
        <button class="btn btn-primary mt-2 mt-md-0" type="button" data-bs-toggle="collapse" data-bs-target="#filterPanel" aria-expanded="false" aria-controls="filterPanel">
            <i class="fas fa-filter me-1"></i> Filters
        </button>
    </div>
    
    <div class="collapse mb-4" id="filterPanel">
        <div class="card card-body shadow-sm">
            <form action="" method="GET" class="row g-3">
                
                <div class="col-12 col-md-4">
                    <label for="search" class="form-label">Search Request ID / Purpose</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="search" name="search" placeholder="ID or Keyword..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                        <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search"></i></button>
                    </div>
                </div>

                <div class="col-12 col-md-4">
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
                
                <div class="col-12 col-md-4">
                    <label for="datePicker" class="form-label">Filter by Specific Date</label>
                    <div class="input-group">
                        <input type="text" class="form-control flatpickr" id="datePicker" placeholder="Select a specific date..." name="date_input" value="<?= htmlspecialchars($_GET['date_input'] ?? '') ?>">
                        <button class="btn btn-outline-danger" type="button" onclick="clearSpecificDateFilter()"><i class="fas fa-times"></i></button>
                    </div>
                    <small class="form-text text-muted">Specific date takes precedence over date ranges.</small>
                </div>

                <div class="col-12 d-flex justify-content-end">
                    <a href="request_history.php" class="btn btn-outline-secondary me-2"><i class="fas fa-undo me-1"></i> Reset All Filters</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i> Apply Filters</button>
                </div>
            </form>
            
            <hr class="mt-4 mb-2">

            <div class="mb-3">
                <p class="fw-semibold mb-2">Status Quick Filters:</p>
                <div class="d-flex flex-wrap justify-content-start align-items-center">
                    <?php
                    $status_filters = [
                        'pending' => 'Pending',
                        'in-progress' => 'In-Progress',
                        'signed' => 'Signed',
                        'printed' => 'Printed',
                        'completed' => 'Completed',
                        'rejected' => 'Rejected',
                        'cancelled' => 'Cancelled',
                    ];
                    $current_status = $_GET['status'] ?? '';
                    foreach ($status_filters as $key => $label):
                        $isActive = ($current_status === $key);
                    ?>
                        <button type="button" 
                                class="btn btn-sm btn-outline-warning me-2 mb-2 quick-filter-btn <?= $isActive ? 'active' : '' ?>" 
                                data-filter-type="status" 
                                data-filter-value="<?= $key ?>">
                            <?= htmlspecialchars($label) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div>
                <p class="fw-semibold mb-2">Date Range Quick Filters:</p>
                <div class="d-flex flex-wrap justify-content-start align-items-center">
                    <?php
                    $date_range_filters = [
                        'today' => 'Today',
                        'week' => 'This Week',
                        'month' => 'This Month',
                        'quarter' => 'This Quarter',
                    ];
                    $current_range = $_GET['date_range'] ?? '';
                    foreach ($date_range_filters as $key => $label):
                        $isActive = ($current_range === $key);
                    ?>
                        <button type="button" 
                                class="btn btn-sm btn-outline-info me-2 mb-2 quick-filter-btn <?= $isActive ? 'active' : '' ?>" 
                                data-filter-type="date_range" 
                                data-filter-value="<?= $key ?>">
                            <?= htmlspecialchars($label) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    $baseQuery = "FROM document_requests dr JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id WHERE dr.resident_id = ?";
    $params = [$_SESSION['user_id']];
    
    // Apply filters
    $whereConditions = [];
    $status = $_GET['status'] ?? '';
    $doc_type = $_GET['doc_type'] ?? '';
    $date_range = $_GET['date_range'] ?? '';
    $date_input = $_GET['date_input'] ?? '';
    $search = $_GET['search'] ?? '';

    // Search Filter (Request ID or Purpose)
    if (!empty($search)) {
        if (is_numeric($search) && $search > 0) {
             $whereConditions[] = "dr.request_id = ?";
             $params[] = $search;
        } else {
             $whereConditions[] = "dr.request_purpose LIKE ?";
             $params[] = "%$search%";
        }
    }
    
    // Status Filter (from quick filters)
    if (!empty($status)) {
        $whereConditions[] = "dr.request_status = ?";
        $params[] = $status;
    }
    
    // Document Type Filter
    if (!empty($doc_type)) {
        $whereConditions[] = "dr.doc_type_id = ?";
        $params[] = $doc_type;
    }
    
    // Date Input Filter (from flatpickr) - Highest priority
    if (!empty($date_input)) {
        $whereConditions[] = "DATE(dr.date_requested) = ?";
        $params[] = $date_input;
    }
    
    // Date Range Filter (from quick filters) - Only if date_input is empty
    if (empty($date_input) && !empty($date_range)) { 
        switch ($date_range) {
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
    // END: FILTER LOGIC
    ?>
    
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
                            <th>Pickup Representative</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Pagination setup
                        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                        $records_per_page = 10;
                        $offset = ($page - 1) * $records_per_page;
                        
                        // Get total count for pagination
                        $countQuery = "SELECT COUNT(*) $baseQuery";
                        $countStmt = $pdo->prepare($countQuery);
                        $countStmt->execute($params);
                        $total_records = $countStmt->fetchColumn();
                        $total_pages = ceil($total_records / $records_per_page);
                        
                        // Fetch paginated results
                        $query = "SELECT dr.*, dt.doc_name, dt.doc_price $baseQuery ORDER BY dr.date_requested DESC LIMIT $records_per_page OFFSET $offset";
                        $stmt = $pdo->prepare($query);
                        $stmt->execute($params);
                        
                        if ($stmt->rowCount() === 0) {
                            echo "<tr><td colspan='8' class='text-center text-muted'>No requests found matching your criteria.</td></tr>";
                        }
                        
                        while ($request = $stmt->fetch()) {
                            // Prepare pickup display: show pickup representative only (schedule removed)
                            $pickupDisplay = htmlspecialchars($request['pickup_representative'] ?? 'Not set');
                            
                            // Status badge styling
                            $statusBadge = '';
                            switch ($request['request_status']) {
                                case 'completed':
                                    $statusBadge = 'success';
                                    break;
                                case 'cancelled':
                                case 'canceled':
                                    $statusBadge = 'secondary';
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
                            
                            echo "<tr data-request-id='" . htmlspecialchars($request['request_id']) . "'>";
                            echo "<td><strong>#{$request['request_id']}</strong></td>";
                            echo "<td>" . htmlspecialchars($request['doc_name']) . "</td>";
                            echo "<td>" . htmlspecialchars(substr($request['request_purpose'] ?? '', 0, 50)) . (strlen($request['request_purpose'] ?? '') > 50 ? '...' : '') . "</td>";
                            echo "<td><span class='badge bg-{$statusBadge}'>" . htmlspecialchars(ucfirst(str_replace('-', ' ', $request['request_status']))) . "</span></td>";
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
            
            <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center mt-3 px-2">
                <small class="text-muted">
                    Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?> entries
                </small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php 
                                $params_array = $_GET;
                                $params_array['page'] = $page - 1;
                                echo http_build_query($params_array); 
                            ?>">Previous</a>
                        </li>
                        
                        <?php
                        // Show page numbers
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1) {
                            echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a></li>';
                            if ($start_page > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php 
                                    $params_array = $_GET;
                                    $params_array['page'] = $i;
                                    echo http_build_query($params_array); 
                                ?>"><?php echo $i; ?></a>
                            </li>
                        <?php 
                        endfor;
                        
                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $total_pages])) . '">' . $total_pages . '</a></li>';
                        }
                        ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php 
                                $params_array = $_GET;
                                $params_array['page'] = $page + 1;
                                echo http_build_query($params_array); 
                            ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="previewContent">
                </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" id="modalCancelBtn" style="display:none;">Cancel Request</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentPreviewRequestId = null;
function showPreview(requestId) {
    console.log('showPreview called:', requestId);
    // Load request details via AJAX
    fetch(`get_request_details.php?id=${requestId}`)
        .then(response => response.json())
        .then(data => {
            const html = data && typeof data === 'object' ? (data.html || '') : '';
            const status = data && typeof data === 'object' ? (data.status || '') : '';
            currentPreviewRequestId = requestId;
            document.getElementById('previewContent').innerHTML = html;

            // Toggle Cancel button visibility
            const btn = document.getElementById('modalCancelBtn');
            if (status === 'pending' || status === 'in-progress') {
                btn.style.display = '';
                btn.dataset.requestId = String(requestId);
            } else {
                btn.style.display = 'none';
                delete btn.dataset.requestId;
            }

            // Use Bootstrap 5 modal object
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        })
        .catch(error => {
            console.error('Error:', error);
            // Assuming you have a Swal (SweetAlert) library
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Loading Error',
                    text: 'Error loading preview'
                });
            } else {
                alert('Error loading preview');
            }
        });
}

// Cancel button handler with SweetAlert reason dropdown (+ robust fallback)
document.addEventListener('click', async (e) => {
    const cancelBtn = e.target && typeof e.target.closest === 'function'
        ? e.target.closest('#modalCancelBtn')
        : null;
    if (!cancelBtn) return;

    const requestId = cancelBtn.dataset.requestId || currentPreviewRequestId;
    if (!requestId) return;

    // Fallback if SweetAlert2 isn't loaded
    if (!(window.Swal && typeof Swal.fire === 'function')) {
        const proceed = window.confirm('Cancel this request?');
        if (!proceed) return;
        let reason = window.prompt('Please enter a reason for cancellation (required):', '');
        if (!reason || !reason.trim()) {
            window.alert('Cancellation reason is required.');
            return;
        }
        await submitCancellation(requestId, reason.trim());
        return;
    }

    const reasonOptions = [
        'Duplicate Request',
        'Incorrect Information Provided',
        'Incomplete Requirements',
        'User No Longer Needs the Document',
        'Payment Issue',
        'Document Not Applicable',
        'User Requested Cancellation',
        'Admin Cancelled Due to Verification Failure',
        'System Error / Technical Issue',
        'User Did Not Respond',
        'other (specify)'
    ];

    const { value: formValues, isConfirmed } = await Swal.fire({
        title: 'Cancel Request',
        html: `
            <div class="text-start">
                <label class="form-label">Select a reason</label>
                <select id="swal-cancel-reason" class="form-select">
                    ${reasonOptions.map(r => `<option value="${r}">${r}</option>`).join('')}
                </select>
                <div id="swal-other-wrap" class="mt-2" style="display:none;">
                    <label class="form-label">Please specify</label>
                    <textarea id="swal-cancel-other" class="form-control" placeholder="Enter details..."></textarea>
                </div>
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Cancel Request',
        preConfirm: () => {
            const sel = document.getElementById('swal-cancel-reason');
            const other = document.getElementById('swal-cancel-other');
            let chosen = sel ? sel.value : '';
            if (!chosen) {
                Swal.showValidationMessage('Please select a reason');
                return false;
            }
            if (chosen === 'other (specify)') {
                const txt = (other?.value || '').trim();
                if (!txt) {
                    Swal.showValidationMessage('Please specify the reason');
                    return false;
                }
                chosen = `Other: ${txt}`;
            }
            return { reason: chosen };
        },
        didOpen: () => {
            const sel = document.getElementById('swal-cancel-reason');
            const wrap = document.getElementById('swal-other-wrap');
            sel?.addEventListener('change', () => {
                wrap.style.display = sel.value === 'other (specify)' ? '' : 'none';
            });
        }
    });

    if (!isConfirmed || !formValues) return;
    await submitCancellation(requestId, formValues.reason);
});

async function submitCancellation(requestId, reason) {
    try {
        const res = await fetch('cancel_request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: String(requestId), reason: reason || '' })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to cancel');

        // Update UI: hide cancel button, update table row badge, and refresh modal content
        const btn = document.getElementById('modalCancelBtn');
        if (btn) { btn.style.display = 'none'; }

        // Update table row badge
        const row = document.querySelector(`tr[data-request-id="${requestId}"]`);
        if (row) {
            const statusCell = row.querySelector('td:nth-child(4) span.badge');
            if (statusCell) {
                statusCell.className = 'badge bg-secondary';
                statusCell.textContent = 'Cancelled';
            }
        }

        // Optionally refresh the modal content to show updated status & remarks
        try {
            const details = await fetch(`get_request_details.php?id=${requestId}`).then(r => r.json());
            const html = details && typeof details === 'object' ? (details.html || '') : '';
            document.getElementById('previewContent').innerHTML = html;
        } catch {}

        await Swal.fire({ icon: 'success', title: 'Cancelled', text: data.message, timer: 1500, showConfirmButton: false });
    } catch (err) {
        console.error(err);
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'error', title: 'Error', text: err.message || 'Unable to cancel request' });
        } else {
            alert('Unable to cancel request');
        }
    }
}

// Function to clear the specific date filter input field
function clearSpecificDateFilter() {
    const datePickerElement = document.getElementById("datePicker");
    if (datePickerElement && datePickerElement._flatpickr) {
        // Clear the flatpickr instance value
        datePickerElement._flatpickr.clear();
        // Since the form uses 'Apply Filters', no auto-submit needed here
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // 1. Flatpickr Initialization
    const datePickerElement = document.getElementById("datePicker");
    if (typeof flatpickr !== 'undefined' && datePickerElement) {
        flatpickr(datePickerElement, {
            dateFormat: "Y-m-d",
            allowInput: true,
        });
        
        // Ensure the input field reflects the URL parameter on page load
        const url = new URL(window.location);
        const dateInput = url.searchParams.get('date_input');
        if(dateInput) {
            // Check if flatpickr instance exists before calling setDate
            if (datePickerElement._flatpickr) {
                datePickerElement._flatpickr.setDate(dateInput, true); 
            }
        }
    }

    // 2. Quick Filter Button Logic: Appends/Toggles filter parameters in URL and navigates
    document.querySelectorAll('.quick-filter-btn').forEach(button => {
        button.addEventListener('click', () => {
            const filterType = button.dataset.filterType;
            const filterValue = button.dataset.filterValue;
            const currentUrl = new URL(window.location.href.split('?')[0]); 

            const currentParams = new URLSearchParams(window.location.search);
            
            // Copy all existing parameters except 'page', and the filter being modified
            const paramsToIgnore = ['page', filterType]; 
            currentParams.forEach((value, key) => {
                if (!paramsToIgnore.includes(key)) {
                    currentUrl.searchParams.set(key, value);
                }
            });
            
            // Check if this filter value is already active for this type
            const isCurrentlyActive = currentParams.get(filterType) === filterValue;
            
            // If active, do nothing (it's already been cleared above); otherwise, set it.
            if (!isCurrentlyActive) {
                currentUrl.searchParams.set(filterType, filterValue);
                
                // When a range filter is set, clear the specific date input as range takes precedence
                if(filterType === 'date_range') {
                    currentUrl.searchParams.delete('date_input');
                }
            }
            
            // Navigate to the new URL
            window.location.href = currentUrl.toString();
        });
    });

    // 3. Ensure filter panel opens if filters are active (using the most recent list of params)
    const filterPanel = document.getElementById('filterPanel');
    const url = new URL(window.location);
    const filterKeys = ['search', 'doc_type', 'date_input', 'status', 'date_range'];

    const hasFilters = filterKeys.some(key => 
        url.searchParams.get(key)
    );

    if (hasFilters) {
        if (filterPanel) {
            filterPanel.classList.add('show');
            filterPanel.setAttribute('aria-expanded', 'true');
        }
    }
    
    // 4. Auto-submit form when Document Type changes
    document.querySelector('select[name="doc_type"]').addEventListener('change', function() {
        this.form.submit();
    });
});
</script>

<style>
/* Style to ensure active quick filter button retains the "active" look */
.quick-filter-btn.active {
    background-color: var(--bs-warning); 
    color: white;
    border-color: var(--bs-warning);
}
.btn-outline-info.quick-filter-btn.active {
    background-color: var(--bs-info); 
    color: white;
    border-color: var(--bs-info);
}

/* Ensure flatpickr icon is visible and aligned */
.input-group > .flatpickr-mobile {
    flex: 1 1 auto;
    width: 1%;
}
</style>

<?php include 'footer.php'; ?>