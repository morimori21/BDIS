<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('captain');

// Handle signing via POST to prevent duplicate actions 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sign_request_id'])) {
    $request_id = (int)$_POST['sign_request_id'];
    // Update status to completed (signing finalizes the document)
    $stmt = $pdo->prepare("UPDATE document_requests SET request_status = 'completed' WHERE request_id = ?");
    $stmt->execute([$request_id]);

    // Get the requesting resident to notify
    $stmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch();
    if ($req && $req['resident_id']) {
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
        $notif->execute([$req['resident_id'], 'document', 'Your document has been completed and is ready for pickup', $request_id]);
    }

    logActivity($_SESSION['user_id'], "Signed document: $request_id");
    header('Location: documents_for_signing.php');
    exit;
}

include 'header.php'; 
?>

<div class="container-fluid mt-4 px-3 px-md-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
                <div class="flex-grow-1">
                    <h2 class="mb-1 h4 h3-lg">Documents for Signing</h2>
                    <p class="text-muted mb-2 mb-lg-0">Review and sign documents that are in progress and awaiting your signature</p>
                </div>
                <div class="d-flex align-items-center w-100 w-lg-auto">
                    <div class="input-group me-2 flex-grow-1 flex-lg-grow-0" style="max-width: 300px; min-width: 200px;">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                        <input type="text" id="searchInput" class="form-control border-start-0" placeholder="Search documents...">
                    </div>
                    <button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#filterOffcanvas">
                        <i class="bi bi-funnel"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <!-- Desktop Table -->
                    <div class="d-none d-lg-block">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="documentsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Resident</th>
                                        <th>Document Type</th>
                                        <th>Reason</th>
                                        <th>Request Date</th>
                                        <th>Pickup Representative</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->query("
                                        SELECT dr.*, dt.doc_name as doc_type_name, u.first_name, u.surname, 
                                               dr.pickup_representative
                                        FROM document_requests dr
                                        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                                        JOIN users u ON dr.resident_id = u.user_id
                                        WHERE dr.request_status = 'completed'
                                        ORDER BY dr.date_requested DESC
                                    ");
                                    $documents_count = 0;
                                    while ($request = $stmt->fetch()) {
                                        $documents_count++;
                                        $rid = (int)$request['request_id'];
                                        $fname = htmlspecialchars($request['first_name']);
                                        $lname = htmlspecialchars($request['surname']);
                                        $dname = htmlspecialchars($request['doc_type_name']);
                                        $reason = htmlspecialchars($request['request_purpose'] ?? 'N/A');
                                        $request_date = $request['date_requested'] ? date('M j, Y', strtotime($request['date_requested'])) : 'Not specified';
                                            $pickupRep = htmlspecialchars($request['pickup_representative'] ?? 'Not set');
                                        
                                        echo "<tr data-request-id='{$rid}'>";
                                        echo "<td class='ps-4'>
                                                <div class='d-flex align-items-center'>
                                                    <div class='avatar-sm bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-3'>
                                                        <i class='bi bi-person-fill text-primary'></i>
                                                    </div>
                                                    <div>
                                                        <div class='fw-semibold'>{$fname} {$lname}</div>
                                                        <small class='text-muted'>ID: {$rid}</small>
                                                    </div>
                                                </div>
                                              </td>";
                                        echo "<td>
                                                <div class='d-flex align-items-center'>
                                                    <div class='avatar-sm bg-info bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-3'>
                                                        <i class='bi bi-file-earmark-text text-info'></i>
                                                    </div>
                                                    <div>{$dname}</div>
                                                </div>
                                              </td>";
                                        echo "<td><span class='text-truncate d-inline-block' style='max-width: 200px;' title='{$reason}'>{$reason}</span></td>";
                                        echo "<td>{$request_date}</td>";
                                        echo "<td>
                                                <div class='d-flex align-items-center'>
                                                    <i class='bi bi-person-badge me-2 text-muted'></i>
                                                        <span>{$pickupRep}</span>
                                                </div>
                                              </td>";
                                        echo "<td class='text-end pe-4'>
                                                <div class='btn-group'>
                                                    <button class='btn btn-sm btn-outline-primary' type='button' onclick='viewDocument({$rid})'>
                                                        <i class='bi bi-eye me-1'></i> Preview
                                                    </button>
                                                </div>
                                              </td>";
                                        echo "</tr>";
                                    }
                                    
                                    if ($documents_count === 0) {
                                        echo "<tr><td colspan='6' class='text-center py-5 text-muted'>
                                                <div class='mb-3'>
                                                    <i class='bi bi-check-circle display-4 text-success'></i>
                                                </div>
                                                <h5>No documents awaiting signature</h5>
                                                <p>All documents have been signed. Check back later for new requests.</p>
                                              </td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Mobile Cards -->
                    <div class="d-lg-none">
                        <div class="row g-3 p-3" id="mobileDocumentsList">
                            <?php

                                $stmt = $pdo->query("\n                                SELECT dr.*, dt.doc_name as doc_type_name, u.first_name, u.surname, dr.pickup_representative\n                                FROM document_requests dr\n                                JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id\n                                JOIN users u ON dr.resident_id = u.user_id\n                                WHERE dr.request_status = 'in_progress'\n                                ORDER BY dr.date_requested DESC\n                            ");
                            $documents_count = 0;
                            while ($request = $stmt->fetch()) {
                                $documents_count++;
                                $rid = (int)$request['request_id'];
                                $fname = htmlspecialchars($request['first_name']);
                                $lname = htmlspecialchars($request['surname']);
                                $dname = htmlspecialchars($request['doc_type_name']);
                                $reason = htmlspecialchars($request['request_purpose'] ?? 'N/A');
                                $request_date = $request['date_requested'] ? date('M j, Y', strtotime($request['date_requested'])) : 'Not specified';
                                $pickupRep = htmlspecialchars($request['pickup_representative'] ?? 'Not set');
                                ?>
                                <div class="col-12" data-request-id="<?php echo $rid; ?>">
                                    <div class="card border shadow-sm">
                                        <div class="card-body">
                                            <div class="row g-2">
                                                <div class="col-12">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar-sm bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-3">
                                                                <i class="bi bi-person-fill text-primary"></i>
                                                            </div>
                                                            <div>
                                                                <h6 class="mb-0 fw-semibold"><?php echo $fname . ' ' . $lname; ?></h6>
                                                                <small class="text-muted">ID: <?php echo $rid; ?></small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-12">
                                                    <div class="d-flex align-items-center mb-2">
                                                        <div class="avatar-sm bg-info bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-3">
                                                            <i class="bi bi-file-earmark-text text-info"></i>
                                                        </div>
                                                        <span class="fw-medium"><?php echo $dname; ?></span>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-12">
                                                    <div class="mb-2">
                                                        <small class="text-muted d-block">Reason:</small>
                                                        <span class="text-truncate-2-lines"><?php echo $reason; ?></span>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Request Date:</small>
                                                    <span><?php echo $request_date; ?></span>
                                                </div>
                                                
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Pickup Representative:</small>
                                                    <div class="d-flex align-items-center">
                                                        <i class="bi bi-person-badge me-1 text-muted small"></i>
                                                        <span><?php echo $pickupRep; ?></span>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-12 mt-2">
                                                    <div class="d-grid gap-2">
                                                        <button class="btn btn-outline-primary btn-sm" type="button" onclick="viewDocument(<?php echo $rid; ?>)">
                                                            <i class="bi bi-eye me-1"></i> Preview Document
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            }
                            
                            if ($documents_count === 0) {
                                ?>
                                <div class="col-12">
                                    <div class="card border-0 text-center py-5">
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <i class="bi bi-check-circle display-4 text-success"></i>
                                            </div>
                                            <h5>No documents awaiting signature</h5>
                                            <p class="text-muted">All documents have been signed. Check back later for new requests.</p>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php if ($documents_count > 0): ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted">
                    Showing <span id="visibleCount"><?php echo $documents_count; ?></span> of <?php echo $documents_count; ?> documents
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Filter Offcanvas for Mobile -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="filterOffcanvas">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title">Filter Documents</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <div class="mb-3">
            <label class="form-label">Filter by Date</label>
            <div class="list-group list-group-flush">
                <a href="#" class="list-group-item list-group-item-action filter-option" data-filter="all">
                    All Documents
                </a>
                <a href="#" class="list-group-item list-group-item-action filter-option" data-filter="today">
                    Today's Documents
                </a>
                <a href="#" class="list-group-item list-group-item-action filter-option" data-filter="week">
                    This Week
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Document Preview Modal - Compact Mobile Version -->
<div class="modal fade" id="documentPreviewModal" tabindex="-1">
    <!-- Desktop Modal -->
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable d-none d-lg-flex">
        <div class="modal-content">
            <div class="modal-header bg-light py-3">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-text me-2"></i> Document Preview
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="d-flex justify-content-center align-items-center bg-light py-2 border-bottom">
                </div>
                <iframe id="documentPreviewFrame" style="width: 100%; height: 70vh; border: none;"></iframe>
            </div>
            <div class="modal-footer py-3">
                <button type="button" class="btn btn-success" id="modalSignBtn" onclick="signDocumentFromModal()">
                    <i class="bi bi-pen-fill me-1"></i> Sign Document
                </button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>

    <!-- Mobile Modal - Compact Version -->
    <div class="modal-dialog modal-fullscreen modal-dialog-centered d-lg-none">
        <div class="modal-content h-100">
            <!-- Compact Header -->
            <div class="modal-header bg-light py-2 px-3">
                <div class="d-flex align-items-center w-100">
                    <div class="flex-grow-1">
                        <h6 class="modal-title mb-0 text-truncate">
                            <i class="bi bi-file-earmark-text me-2"></i> Document Preview
                        </h6>
                    </div>
                    <div class="d-flex align-items-center">
                         <button type="button" class="btn btn-success" id="modalSignBtn" onclick="signDocumentFromModal()">
                        <i class="bi bi-pen-fill me-1"></i> Sign Document
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>

            <!-- Document Content -->
            <div class="modal-body p-0 flex-grow-1">
                <iframe id="documentPreviewFrameMobile" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>

            <!-- Compact Footer -->
            <div class="modal-footer py-2 px-3 bg-light">
                <div class="d-flex w-100 gap-2">
                    <button type="button" class="btn btn-outline-secondary flex-grow-1" data-bs-dismiss="modal">
                        Close
                    </button>
                    <button type="button" class="btn btn-success flex-grow-1" id="modalSignBtnMobile" onclick="signDocumentFromModal()">
                        <i class="bi bi-pen-fill me-1"></i> Sign
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sign Confirmation Modal -->
<div class="modal fade" id="signConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h5 class="modal-title">Confirm Signature</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-3">
                <div class="text-center mb-3">
                    <div class="avatar-lg bg-success bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3">
                        <i class="bi bi-pen-fill text-success" style="font-size: 2rem;"></i>
                    </div>
                    <h5 class="mb-2">Sign this document?</h5>
                    <p class="text-muted mb-0">Once signed, the document status will change to "completed" and the resident will be notified that their document is ready for pickup.</p>
                </div>
            </div>
            <div class="modal-footer py-3 justify-content-center">
                <div class="d-flex gap-2 w-100">
                    <button type="button" class="btn btn-outline-secondary flex-grow-1" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success flex-grow-1" id="confirmSignBtn">
                        <i class="bi bi-pen-fill me-1"></i> Sign Document
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentRequestId = null;

function viewDocument(requestId) {
    console.log('viewDocument called:', requestId);
    // Store the current request ID for signing
    currentRequestId = requestId;
    
    // Set iframe source for both desktop and mobile
    const iframeDesktop = document.getElementById('documentPreviewFrame');
    const iframeMobile = document.getElementById('documentPreviewFrameMobile');
    const src = `../secretary/view_document.php?request_id=${requestId}`;
    
    if (iframeDesktop) iframeDesktop.src = src;
    if (iframeMobile) iframeMobile.src = src;
    
    const modal = new bootstrap.Modal(document.getElementById('documentPreviewModal'));
    modal.show();
}

function signDocument(requestId) {
    currentRequestId = requestId;
    const modal = new bootstrap.Modal(document.getElementById('signConfirmModal'));
    modal.show();
}

function signDocumentFromModal() {
    if (currentRequestId) {
        // Create form and submit to sign the document
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="sign_request_id" value="${currentRequestId}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

// Set up event listeners when the page loads
document.addEventListener('DOMContentLoaded', function() {
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchText = this.value.toLowerCase();
            
            // Search in desktop table
            const desktopRows = document.querySelectorAll('#documentsTable tbody tr');
            let desktopVisibleCount = 0;
            
            desktopRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchText)) {
                    row.style.display = '';
                    desktopVisibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Search in mobile cards
            const mobileCards = document.querySelectorAll('#mobileDocumentsList .col-12');
            let mobileVisibleCount = 0;
            
            mobileCards.forEach(card => {
                const text = card.textContent.toLowerCase();
                if (text.includes(searchText)) {
                    card.style.display = '';
                    mobileVisibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Update visible count
            const visibleCountElement = document.getElementById('visibleCount');
            if (visibleCountElement) {
                const totalVisible = Math.max(desktopVisibleCount, mobileVisibleCount);
                visibleCountElement.textContent = totalVisible;
            }
        });
    }
    
    // Filter functionality
    const filterOptions = document.querySelectorAll('.filter-option');
    filterOptions.forEach(option => {
        option.addEventListener('click', function(e) {
            e.preventDefault();
            const filter = this.getAttribute('data-filter');
            console.log('Filter selected:', filter);
            
            // Close offcanvas on mobile
            const offcanvas = bootstrap.Offcanvas.getInstance(document.getElementById('filterOffcanvas'));
            if (offcanvas) {
                offcanvas.hide();
            }
        });
    });
    
    // Confirm sign button
    const confirmSignBtn = document.getElementById('confirmSignBtn');
    if (confirmSignBtn) {
        confirmSignBtn.addEventListener('click', function() {
            if (currentRequestId) {
                // Create form and submit to sign the document
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="sign_request_id" value="${currentRequestId}">`;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
});
</script>

<style>
body {
    margin: 0;
    padding: 0;
    overflow-x: hidden;
}

.avatar-sm {
    width: 36px;
    height: 36px;
}

.avatar-lg {
    width: 80px;
    height: 80px;
}

.table td {
    vertical-align: middle;
}

.card {
    border-radius: 12px;
}

.badge {
    font-weight: 500;
    padding: 0.5em 0.75em;
}
.btn {
    border-radius: 6px;
}

.input-group-text {
    border-radius: 6px 0 0 6px;
}

.form-control {
    border-radius: 0 6px 6px 0;
}

.modal-header {
    border-bottom: 1px solid #e9ecef;
}

.modal-footer {
    border-top: 1px solid #e9ecef;
}

.btn-group .btn {
    margin: 0 2px;
}

@media (max-width: 991.98px) {
    .container-fluid {
        padding-left: 15px;
        padding-right: 15px;
    }
    
    .text-truncate-2-lines {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .modal-fullscreen {
        max-width: 100vw;
    }
    
    .modal-header {
        padding: 0.75rem 1rem;
    }
    
    .modal-footer {
        padding: 0.75rem 1rem;
    }
    
    .modal-title {
        font-size: 0.9rem;
    }
    
    .btn-sm {
        padding: 0.375rem 0.5rem;
        font-size: 0.8rem;
    }
}

@media (min-width: 992px) {
    body {
        margin-left: 80px;
        margin-right: 80px;
    }
    
    .container-fluid {
        padding-left: 20px;
        padding-right: 20px;
    }
}

@media (max-width: 575.98px) {
    .avatar-sm {
        width: 32px;
        height: 32px;
    }
    
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }

    .modal-header {
        padding: 0.5rem 0.75rem;
    }
    
    .modal-footer {
        padding: 0.5rem 0.75rem;
    }
    
    .modal-title {
        font-size: 0.85rem;
    }
}

.card, .btn, .modal-content {
    transition: all 0.2s ease-in-out;
}

.btn:focus, .form-control:focus {
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.modal-content .h-100 {
    display: flex;
    flex-direction: column;
}

.modal-body.flex-grow-1 {
    min-height: 0; 
}
</style>

<?php include 'footer.php'; ?>