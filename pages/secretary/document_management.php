<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();

$isAjaxRequest = ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['ajax'] ?? '') === '1' || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')));

// Handle status update (supports AJAX without reload)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_request'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action'];
    $isAjax = (isset($_POST['ajax']) && $_POST['ajax'] === '1') ||
              (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    try {
        $clientStatus = null; // allows us to show a different badge than DB value when needed
        if ($action === 'accept') {
            $status_to_save = 'in-progress';
            $message = "Request accepted. You can now print the document.";
            $alertClass = 'success';
            $remarks_to_save = 'Your Requested Document has been approved and waiting to be printed';

            $emailStmt = $pdo->prepare("\n                    SELECT u.first_name, u.surname, e.email, dt.doc_name\n                    FROM document_requests dr\n                    JOIN users u ON dr.resident_id = u.user_id\n                    LEFT JOIN account a ON u.user_id = a.user_id\n                    LEFT JOIN email e ON a.email_id = e.email_id\n                    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id\n                    WHERE dr.request_id = ?\n                ");
            $emailStmt->execute([$request_id]);
            $residentData = $emailStmt->fetch();

            $uStmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
            $uStmt->execute([$request_id]);
            $reqRow = $uStmt->fetch();
            if ($reqRow && $reqRow['resident_id']) {
                $notifResident = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
                $notifResident->execute([$reqRow['resident_id'], 'document', 'Your document request has been accepted', $request_id]);
            }

            if ($residentData && $residentData['email']) {
                require_once '../../includes/email_notif.php';
                $residentName = ucwords(strtolower($residentData['first_name'])) . ' ' . ucwords(strtolower($residentData['surname']));
                $documentType = $residentData['doc_name'];
                sendDocumentApprovalEmail($residentData['email'], $residentName, $documentType, $request_id);
            }

        } elseif ($action === 'for-signing' || $action === 'print') {
            // Updated flow: directly complete after printing
            $status_to_save = 'completed';
            $message = 'Document printed and signed; ready for pickup.';
            $alertClass = 'success';
            $remarks_to_save = 'Printed and signed; ready for pickup.';

            // Notify resident and email
            $emailStmt = $pdo->prepare("
                    SELECT u.first_name, u.surname, e.email, dt.doc_name
                    FROM document_requests dr
                    JOIN users u ON dr.resident_id = u.user_id
                    LEFT JOIN account a ON u.user_id = a.user_id
                    LEFT JOIN email e ON a.email_id = e.email_id
                    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                    WHERE dr.request_id = ?
                ");
            $emailStmt->execute([$request_id]);
            $residentData = $emailStmt->fetch();

            $uStmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
            $uStmt->execute([$request_id]);
            $reqRow = $uStmt->fetch();
            if ($reqRow && $reqRow['resident_id']) {
                $notifResident = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
                $notifResident->execute([$reqRow['resident_id'], 'document', 'Your document is ready for pickup', $request_id]);
            }

            if ($residentData && $residentData['email']) {
                require_once '../../includes/email_notif.php';
                $residentName = ucwords(strtolower($residentData['first_name'])) . ' ' . ucwords(strtolower($residentData['surname']));
                $documentType = $residentData['doc_name'];
                sendDocumentReadyEmail($residentData['email'], $residentName, $documentType, $request_id);
            }

        } elseif ($action === 'reject') {
            $status_to_save = 'rejected';
            $rejection_reason = isset($_POST['rejection_reason']) ? $_POST['rejection_reason'] : 'No reason provided';
            $message = 'Request has been rejected.';
            $alertClass = 'danger';
            $remarks_to_save = 'Reason of Rejection: ' . $rejection_reason;

            $emailStmt = $pdo->prepare("\n                    SELECT u.first_name, u.surname, e.email, dt.doc_name\n                    FROM document_requests dr\n                    JOIN users u ON dr.resident_id = u.user_id\n                    LEFT JOIN account a ON u.user_id = a.user_id\n                    LEFT JOIN email e ON a.email_id = e.email_id\n                    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id\n                    WHERE dr.request_id = ?\n                ");
            $emailStmt->execute([$request_id]);
            $residentData = $emailStmt->fetch();

            $uStmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
            $uStmt->execute([$request_id]);
            $reqRow = $uStmt->fetch();
            if ($reqRow && $reqRow['resident_id']) {
                $notifResident = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
                $notifResident->execute([$reqRow['resident_id'], 'document', 'Your document request has been rejected', $request_id]);
            }

            if ($residentData && $residentData['email']) {
                require_once '../../includes/email_notif.php';
                $residentName = ucwords(strtolower($residentData['first_name'])) . ' ' . ucwords(strtolower($residentData['surname']));
                $documentType = $residentData['doc_name'];
                sendDocumentRejectionEmail($residentData['email'], $residentName, $documentType, $request_id, $rejection_reason);
            }

        } elseif ($action === 'complete') {
            $status_to_save = 'completed';
            $message = 'Document marked as completed - resident has received the document.';
            $alertClass = 'success';
            $remarks_to_save = 'The Document is already Received';

            $emailStmt = $pdo->prepare("\n                    SELECT u.first_name, u.surname, e.email, dt.doc_name\n                    FROM document_requests dr\n                    JOIN users u ON dr.resident_id = u.user_id\n                    LEFT JOIN account a ON u.user_id = a.user_id\n                    LEFT JOIN email e ON a.email_id = e.email_id\n                    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id\n                    WHERE dr.request_id = ?\n                ");
            $emailStmt->execute([$request_id]);
            $residentData = $emailStmt->fetch();

            $uStmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
            $uStmt->execute([$request_id]);
            $reqRow = $uStmt->fetch();
            if ($reqRow && $reqRow['resident_id']) {
                $notifResident = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
                $notifResident->execute([$reqRow['resident_id'], 'document', 'Your document is ready for pickup', $request_id]);
            }

            if ($residentData && $residentData['email']) {
                require_once '../../includes/email_notif.php';
                $residentName = ucwords(strtolower($residentData['first_name'])) . ' ' . ucwords(strtolower($residentData['surname']));
                $documentType = $residentData['doc_name'];
                sendDocumentReadyEmail($residentData['email'], $residentName, $documentType, $request_id);
            }

        } elseif ($action === 'picked-up') {
            // Final step: mark as picked up
            $status_to_save = 'picked-up';
            $message = 'Document marked as picked up.';
            $alertClass = 'success';
            $remarks_to_save = 'Document has been picked up by the resident.';

        } elseif ($action === 'sign-as-secretary') {
            $status_to_save = 'signed';
            $message = 'Document signed by Secretary.';
            $alertClass = 'success';
            $remarks_to_save = 'Your document is signed and ready for pickup';

            $uStmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
            $uStmt->execute([$request_id]);
            $reqRow = $uStmt->fetch();
            if ($reqRow && $reqRow['resident_id']) {
                $notifResident = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
                $notifResident->execute([$reqRow['resident_id'], 'document', 'Your document has been signed and is ready for pickup', $request_id]);
            }

        } else {
            throw new Exception('Invalid action');
        }

        // Determine default remarks if not set (e.g., pending)
        if (!isset($remarks_to_save)) {
            if ($status_to_save === 'pending') {
                $remarks_to_save = 'Your Have Requested A Document, awaiting for approval';
            } else {
                $remarks_to_save = '';
            }
        }

        $stmt = $pdo->prepare("UPDATE document_requests SET request_status = ?, request_remarks = ? WHERE request_id = ?");
        $stmt->execute([$status_to_save, $remarks_to_save, $request_id]);

        if (function_exists('logActivity')) {
            logActivity($_SESSION['user_id'], "Updated request status: $request_id to $status_to_save");
        }

        // Build status badge HTML for the client (use clientStatus if provided)
        $effectiveStatus = $clientStatus ?: $status_to_save;
        $statusBadge = '';
        switch ($effectiveStatus) {
            case 'completed': $statusBadge = "<span class='badge bg-success'>Completed</span>"; break;
            case 'signed': $statusBadge = "<span class='badge bg-info'>Signed</span>"; break;
            case 'ready': $statusBadge = "<span class='badge bg-success'>Ready for Pickup</span>"; break;
            case 'rejected': $statusBadge = "<span class='badge bg-danger'>Rejected</span>"; break;
            case 'printed': $statusBadge = "<span class='badge bg-primary'>Printed</span>"; break;
            case 'for-signing': $statusBadge = "<span class='badge bg-info'>For Signing</span>"; break;
            case 'in-progress': $statusBadge = "<span class='badge bg-primary'>In Progress</span>"; break;
            case 'picked-up': $statusBadge = "<span class='badge bg-success'>Picked Up</span>"; break;
            case 'pending': $statusBadge = "<span class='badge bg-warning'>Pending</span>"; break;
            default: $statusBadge = "<span class='badge bg-secondary'>" . htmlspecialchars(ucfirst($effectiveStatus)) . "</span>";
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => $message,
                'status' => $effectiveStatus,
                'statusBadge' => $statusBadge,
                'alertClass' => $alertClass,
            ]);
            exit;
        }
    } catch (Exception $e) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
}

include 'header.php';
require_once '../../includes/document_generator.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<style>

body {
    overflow-x: hidden; 
}

.table-responsive {
    overflow: visible !important;
}

.table td {
    overflow: visible !important;
}

.dropdown {
    position: relative !important;
}

.dropdown-menu {
    position: absolute !important;
    z-index: 1050 !important;
    will-change: transform;
}

.btn-sm {
    min-width: 80px;
}

.form-check {
    margin-left: 10px;
}

.form-check-input {
    cursor: pointer;
}

.form-check-label {
    cursor: pointer;
    font-size: 0.875rem;
    color: #6c757d;
}

.form-check-input:checked ~ .form-check-label {
    color: #198754;
    font-weight: 500;
}

.table th:last-child,
.table td:last-child {
    min-width: 120px;
    text-align: right;
}

.btn-sm {
    min-width: 70px;
}

.btn-group .btn-sm {
    min-width: auto;
}

.table td, .table th {
    padding: 0.75rem 0.75rem;
    font-size: 0.95rem;
    vertical-align: middle;
}

.table thead th {
    font-size: 0.95rem;
    padding: 0.75rem 0.75rem;
}


.badge {
    font-size: 0.85rem;
    padding: 0.45rem 0.6rem;
}
.btn-sm {
    padding: 0.35rem 0.65rem;
    font-size: 0.9rem;
    min-width: 80px;
}

.btn-group .btn-sm {
    min-width: auto;
    padding: 0.35rem 0.5rem;
}

.table th:last-child,
.table td:last-child {
    min-width: 120px;
    text-align: right;
}

.form-check {
    margin-left: 8px;
}

.form-check-input {
    cursor: pointer;
    transform: scale(0.9);
}

.form-check-label {
    cursor: pointer;
    font-size: 0.8rem;
    color: #6c757d;
}

.quick-filter-btn.active {
    background-color: var(--bs-warning);
    color: #fff;
    border-color: var(--bs-warning);
}
.btn-outline-info.quick-filter-btn.active {
    background-color: var(--bs-info);
    color: #fff;
    border-color: var(--bs-info);
}

.table th {
    background-color: #343a40;
    color: white;
    border-color: #454d55;
}

.btn-group .dropdown-toggle::after {
    margin-left: 0.5em;
}

.table-responsive {
    border-radius: 0.375rem;
    overflow: hidden;
}

.table {
    margin-bottom: 0;
}
</style>

<div class="container-fluid">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
        <h2 class="mb-0"><i class="bi bi-folder2-open me-2"></i>Requests
        <?php
        // Get status filter from URL
        $statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
        
        // Display filter badge
        $filterLabels = [
            'all' => 'All Requests',
            'pending' => 'Pending',
            'in-progress' => 'In Progress',
            'printed' => 'Printed',
            'for-signing' => 'For Signing',
            'signed' => 'Signed',
            'completed' => 'Completed',
            'picked-up' => 'Picked Up',
            'ready' => 'Ready for Pickup',
            'rejected' => 'Rejected'
        ];
        $filterLabel = isset($filterLabels[$statusFilter]) ? $filterLabels[$statusFilter] : ucfirst($statusFilter);
        
        // Badge color based on status
        $badgeColor = 'bg-primary';
        if ($statusFilter === 'all') {
            $badgeColor = 'bg-secondary';
        } elseif ($statusFilter === 'pending') {
            $badgeColor = 'bg-warning';
        } elseif ($statusFilter === 'in-progress') {
            $badgeColor = 'bg-success';
        } elseif ($statusFilter === 'printed') {
            $badgeColor = 'bg-primary';
        } elseif ($statusFilter === 'rejected') {
            $badgeColor = 'bg-danger';
        } elseif ($statusFilter === 'signed') {
            $badgeColor = 'bg-info';
        } elseif ($statusFilter === 'completed') {
            $badgeColor = 'bg-success';
        } elseif ($statusFilter === 'picked-up') {
            $badgeColor = 'bg-success';
        } elseif ($statusFilter === 'ready') {
            $badgeColor = 'bg-success';
        } elseif ($statusFilter === 'for-signing') {
            $badgeColor = 'bg-info';
        }
        
        echo " - <span class='badge $badgeColor'>$filterLabel</span>";
        ?>
        </h2>
        <button id="toggleFiltersBtn" class="btn btn-primary mt-2 mt-md-0" type="button" data-bs-toggle="collapse" data-bs-target="#filterPanel" aria-expanded="false" aria-controls="filterPanel">
            <i class="bi bi-funnel-fill me-1"></i> Filters
        </button>
    </div>

    <div class="collapse mb-4" id="filterPanel">
        <div class="card card-body shadow-sm">
            <form action="" method="GET" class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Document Type</label>
                    <select name="doc_type" class="form-select">
                        <option value="">All Types</option>
                        <?php
                        // Populate from all document types for secretary view
                        $dtStmt = $pdo->query("SELECT doc_type_id, doc_name FROM document_types ORDER BY doc_name");
                        $currentDocType = $_GET['doc_type'] ?? '';
                        while ($type = $dtStmt->fetch()) {
                            $selected = ($currentDocType == $type['doc_type_id']) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($type['doc_type_id']) . "' $selected>" . htmlspecialchars($type['doc_name']) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <label for="datePicker" class="form-label">Filter by Specific Date</label>
                    <div class="input-group">
                        <input type="text" class="form-control flatpickr" id="datePicker" name="date_input" placeholder="Select a specific date..." value="<?= htmlspecialchars($_GET['date_input'] ?? '') ?>">
                        <button class="btn btn-outline-danger" type="button" onclick="clearSpecificDateFilter()"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <small class="form-text text-muted">Specific date takes precedence over date ranges.</small>
                </div>

                <div class="col-12 d-flex justify-content-end">
                    <a href="document_management.php" class="btn btn-outline-secondary me-2"><i class="fas fa-undo me-1"></i> Reset All Filters</a>
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
                        'printed' => 'Printed',
                        'for-signing' => 'For Signing',
                        'signed' => 'Signed',
                        'completed' => 'Completed',
                        'picked-up' => 'Picked Up',
                        'rejected' => 'Rejected',
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
    // Display success message if redirected with success parameter
    if (isset($_GET['success'])) {
        echo "<div class='alert alert-success alert-dismissible fade show' role='alert'>";
        echo htmlspecialchars($_GET['success']);
        echo "<button type='button' class='btn-close' data-bs-dismiss='alert'></button>";
        echo "</div>";
    }
    ?>
    
    <?php
    // Handle status update (supports AJAX without reload)
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_request'])) {
        $request_id = $_POST['request_id'];
        $action = $_POST['action'];
        $isAjax = (isset($_POST['ajax']) && $_POST['ajax'] === '1') ||
                  (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        
        try {
            if ($action === 'accept') {
                // Accept the document request (document will be generated when printed)
                $status_to_save = 'in-progress';
                $message = "Request accepted. You can now print the document.";
                $alertClass = 'success';
            $remarks_to_save = 'Your Requested Document has been approved and waiting to be printed';
                
                // Get resident details for email notification
                $emailStmt = $pdo->prepare("
                    SELECT u.first_name, u.surname, e.email, dt.doc_name
                    FROM document_requests dr
                    JOIN users u ON dr.resident_id = u.user_id
                    LEFT JOIN account a ON u.user_id = a.user_id
                    LEFT JOIN email e ON a.email_id = e.email_id
                    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                    WHERE dr.request_id = ?
                ");
                $emailStmt->execute([$request_id]);
                $residentData = $emailStmt->fetch();
                
                // Notify the requesting resident
                $uStmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
                $uStmt->execute([$request_id]);
                $reqRow = $uStmt->fetch();
                if ($reqRow && $reqRow['resident_id']) {
                    $notifResident = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
                    $notifResident->execute([$reqRow['resident_id'], 'document', 'Your document request has been accepted', $request_id]);
                }
                
                // Send email notification
                if ($residentData && $residentData['email']) {
                    require_once '../../includes/email_notif.php';
                    $residentName = ucwords(strtolower($residentData['first_name'])) . ' ' . ucwords(strtolower($residentData['surname']));
                    $documentType = $residentData['doc_name'];
                    
                    sendDocumentApprovalEmail(
                        $residentData['email'],
                        $residentName,
                        $documentType,
                        $request_id
                    );
                }
                
            } elseif ($action === 'for-signing') {
                // New requirement: In-Progress -> Mark as Printed => Completed
                $status_to_save = 'completed';
                $message = "Document printed and signed; ready for pickup.";
                $alertClass = 'success';
                $remarks_to_save = 'Printed and signed; ready for pickup.';
                
                // Notify resident and email (same as 'complete')
                $emailStmt = $pdo->prepare("
                    SELECT u.first_name, u.surname, e.email, dt.doc_name
                    FROM document_requests dr
                    JOIN users u ON dr.resident_id = u.user_id
                    LEFT JOIN account a ON u.user_id = a.user_id
                    LEFT JOIN email e ON a.email_id = e.email_id
                    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                    WHERE dr.request_id = ?
                ");
                $emailStmt->execute([$request_id]);
                $residentData = $emailStmt->fetch();
                
                $uStmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
                $uStmt->execute([$request_id]);
                $reqRow = $uStmt->fetch();
                if ($reqRow && $reqRow['resident_id']) {
                    $notifResident = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
                    $notifResident->execute([$reqRow['resident_id'], 'document', 'Your document is ready for pickup', $request_id]);
                }
                
                if ($residentData && $residentData['email']) {
                    require_once '../../includes/email_notif.php';
                    $residentName = ucwords(strtolower($residentData['first_name'])) . ' ' . ucwords(strtolower($residentData['surname']));
                    $documentType = $residentData['doc_name'];
                    sendDocumentReadyEmail($residentData['email'], $residentName, $documentType, $request_id);
                }
                
                // Note: Now considered completed immediately per new flow
                
            } elseif ($action === 'reject') {
                $status_to_save = 'rejected';
                $rejection_reason = isset($_POST['rejection_reason']) ? $_POST['rejection_reason'] : 'No reason provided';
                $message = "Request has been rejected.";
                $alertClass = 'danger';
                $remarks_to_save = 'Reason of Rejection: ' . $rejection_reason;
                
                // Get resident details for email notification
                $emailStmt = $pdo->prepare("
                    SELECT u.first_name, u.surname, e.email, dt.doc_name
                    FROM document_requests dr
                    JOIN users u ON dr.resident_id = u.user_id
                    LEFT JOIN account a ON u.user_id = a.user_id
                    LEFT JOIN email e ON a.email_id = e.email_id
                    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                    WHERE dr.request_id = ?
                ");
                $emailStmt->execute([$request_id]);
                $residentData = $emailStmt->fetch();
                
                // Notify the requesting resident
                $uStmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
                $uStmt->execute([$request_id]);
                $reqRow = $uStmt->fetch();
                if ($reqRow && $reqRow['resident_id']) {
                    $notifResident = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
                    $notifResident->execute([$reqRow['resident_id'], 'document', 'Your document request has been rejected', $request_id]);
                }
                
                // Send email notification with rejection reason
                if ($residentData && $residentData['email']) {
                    require_once '../../includes/email_notif.php';
                    $residentName = ucwords(strtolower($residentData['first_name'])) . ' ' . ucwords(strtolower($residentData['surname']));
                    $documentType = $residentData['doc_name'];
                    
                    sendDocumentRejectionEmail(
                        $residentData['email'],
                        $residentName,
                        $documentType,
                        $request_id,
                        $rejection_reason
                    );
                }
                
            } elseif ($action === 'print') {
                // Treat direct print as completed per new requirement
                $status_to_save = 'completed';
                $message = "Document printed and signed; ready for pickup.";
                $alertClass = 'success';
                $remarks_to_save = 'Printed and signed; ready for pickup.';
                
                // Notify resident and email (same as 'complete')
                $emailStmt = $pdo->prepare("
                    SELECT u.first_name, u.surname, e.email, dt.doc_name
                    FROM document_requests dr
                    JOIN users u ON dr.resident_id = u.user_id
                    LEFT JOIN account a ON u.user_id = a.user_id
                    LEFT JOIN email e ON a.email_id = e.email_id
                    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                    WHERE dr.request_id = ?
                ");
                $emailStmt->execute([$request_id]);
                $residentData = $emailStmt->fetch();
                
                $uStmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
                $uStmt->execute([$request_id]);
                $reqRow = $uStmt->fetch();
                if ($reqRow && $reqRow['resident_id']) {
                    $notifResident = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
                    $notifResident->execute([$reqRow['resident_id'], 'document', 'Your document is ready for pickup', $request_id]);
                }
                
                if ($residentData && $residentData['email']) {
                    require_once '../../includes/email_notif.php';
                    $residentName = ucwords(strtolower($residentData['first_name'])) . ' ' . ucwords(strtolower($residentData['surname']));
                    $documentType = $residentData['doc_name'];
                    sendDocumentReadyEmail($residentData['email'], $residentName, $documentType, $request_id);
                }
                
                // Document is ready for pickup
                
            } elseif ($action === 'complete') {
                // Mark as completed when resident receives the document (signed -> completed)
                $status_to_save = 'completed';
                $message = "Document marked as completed - resident has received the document.";
                $alertClass = 'success';
                $remarks_to_save = 'The Document is already Received';
                
                // Get resident details for email notification
                $emailStmt = $pdo->prepare("
                    SELECT u.first_name, u.surname, e.email, dt.doc_name
                    FROM document_requests dr
                    JOIN users u ON dr.resident_id = u.user_id
                    LEFT JOIN account a ON u.user_id = a.user_id
                    LEFT JOIN email e ON a.email_id = e.email_id
                    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                    WHERE dr.request_id = ?
                ");
                $emailStmt->execute([$request_id]);
                $residentData = $emailStmt->fetch();
                
                // Notify resident that document transaction is complete
                $uStmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
                $uStmt->execute([$request_id]);
                $reqRow = $uStmt->fetch();
                if ($reqRow && $reqRow['resident_id']) {
                    $notifResident = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
                    $notifResident->execute([$reqRow['resident_id'], 'document', 'Your document is ready for pickup', $request_id]);
                }
                
                // Send email notification
                if ($residentData && $residentData['email']) {
                    require_once '../../includes/email_notif.php';
                    $residentName = ucwords(strtolower($residentData['first_name'])) . ' ' . ucwords(strtolower($residentData['surname']));
                    $documentType = $residentData['doc_name'];
                    
                    sendDocumentReadyEmail(
                        $residentData['email'],
                        $residentName,
                        $documentType,
                        $request_id
                    );
                }
                
            } elseif ($action === 'picked-up') {
                // New final step: Completed -> Picked Up
                $status_to_save = 'picked-up';
                $message = "Document marked as picked up.";
                $alertClass = 'success';
                $remarks_to_save = 'Document has been picked up by the resident.';

            } elseif ($action === 'sign-as-secretary') {
                // Secretary signs document when captain is not available (printed -> signed)
                $status_to_save = 'signed';
                $message = "Document signed by Secretary.";
                $alertClass = 'success';
                $remarks_to_save = 'Your document is signed and ready for pickup';
                
                // Notify resident that document is signed
                $uStmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
                $uStmt->execute([$request_id]);
                $reqRow = $uStmt->fetch();
                if ($reqRow && $reqRow['resident_id']) {
                    $notifResident = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
                    $notifResident->execute([$reqRow['resident_id'], 'document', 'Your document has been signed and is ready for pickup', $request_id]);
                }
                
            } else {
                throw new Exception("Invalid action");
            }

            // Fallback default for pending
            if (!isset($remarks_to_save)) {
                if ($status_to_save === 'pending') {
                    $remarks_to_save = 'Your Have Requested A Document, awaiting for approval';
                } else {
                    $remarks_to_save = '';
                }
            }

            $stmt = $pdo->prepare("UPDATE document_requests SET request_status = ?, request_remarks = ? WHERE request_id = ?");
            $stmt->execute([$status_to_save, $remarks_to_save, $request_id]);

            // Log activity
            logActivity($_SESSION['user_id'], "Updated request status: $request_id to $status_to_save");
            
            // Prepare status badge HTML for client update
            $statusBadge = '';
            switch ($status_to_save) {
                case 'completed': $statusBadge = "<span class='badge bg-success'>Completed</span>"; break;
                case 'signed': $statusBadge = "<span class='badge bg-info'>Signed</span>"; break;
                case 'ready': $statusBadge = "<span class='badge bg-success'>Ready for Pickup</span>"; break;
                case 'rejected': $statusBadge = "<span class='badge bg-danger'>Rejected</span>"; break;
                case 'printed': $statusBadge = "<span class='badge bg-primary'>Printed</span>"; break;
                case 'for-signing': $statusBadge = "<span class='badge bg-info'>For Signing</span>"; break;
                case 'in-progress': $statusBadge = "<span class='badge bg-primary'>In Progress</span>"; break;
                case 'picked-up': $statusBadge = "<span class='badge bg-success'>Picked Up</span>"; break;
                case 'pending': $statusBadge = "<span class='badge bg-warning'>Pending</span>"; break;
                default: $statusBadge = "<span class='badge bg-secondary'>" . htmlspecialchars(ucfirst($status_to_save)) . "</span>";
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'status' => $status_to_save,
                    'statusBadge' => $statusBadge,
                    'alertClass' => $alertClass,
                ]);
                exit;
            }

            // Fallback: legacy redirect flow for non-AJAX submissions
            $currentURL = $_SERVER['REQUEST_URI'];
            $baseURL = strtok($currentURL, '?');
            $statusParam = isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : '';
            $redirectURL = $baseURL . $statusParam;

            if (!empty($statusParam)) {
                $redirectURL .= '&success=' . urlencode($message);
            } else {
                $redirectURL .= '?success=' . urlencode($message);
            }

            echo "<script> setTimeout(function(){ window.location.href = '" . addslashes($redirectURL) . "'; }, 1500); </script>";
            echo "<div class='alert alert-$alertClass'>$message <br><small>Redirecting...</small></div>";
            
        } catch (Exception $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            echo "<div class='alert alert-danger'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    ?>

    <div class="table-responsive">
        <table class="table table-striped table-hover" id="requestsTable">
            <thead class="table-dark">
                <tr>
                    <th>User Name</th>
                    <th>Document Requested</th>
                    <th>Purpose</th>
                    <th>Request Time</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Build query based on filters
                $statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
                $docTypeFilter = isset($_GET['doc_type']) ? trim($_GET['doc_type']) : '';
                $dateRange = isset($_GET['date_range']) ? trim($_GET['date_range']) : '';
                $dateInput = isset($_GET['date_input']) ? trim($_GET['date_input']) : '';
                
                // Pagination setup
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                $records_per_page = 10;
                $offset = ($page - 1) * $records_per_page;
                
                $baseQuery = "
                    FROM document_requests dr
                    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                    JOIN users u ON dr.resident_id = u.user_id
                    LEFT JOIN account a ON u.user_id = a.user_id
                    LEFT JOIN email e ON a.email_id = e.email_id
                ";

                // Always exclude cancelled/canceled from secretary view
                $excludeCancelled = "dr.request_status NOT IN ('cancelled','canceled')";

                // Dynamic WHERE parts and parameters
                $whereParts = [$excludeCancelled];
                $params = [];

                // Status filter
                if ($statusFilter !== 'all') {
                    if ($statusFilter === 'for-signing') {
                        $whereParts[] = "dr.request_status = 'printed'";
                    } elseif ($statusFilter === 'completed') {
                        $whereParts[] = "dr.request_status IN ('completed','ready')";
                    } elseif ($statusFilter === 'picked-up') {
                        $whereParts[] = "dr.request_status = 'completed'";
                        // Updated to match new status name
                        $whereParts[count($whereParts)-1] = "dr.request_status = 'picked-up'";
                    } else {
                        $whereParts[] = "dr.request_status = :status";
                        $params['status'] = $statusFilter;
                    }
                }

                // Document type filter
                if ($docTypeFilter !== '') {
                    $whereParts[] = "dr.doc_type_id = :doc_type";
                    $params['doc_type'] = $docTypeFilter;
                }

                // Specific date filter (highest priority)
                if ($dateInput !== '') {
                    $whereParts[] = "DATE(dr.date_requested) = :date_input";
                    $params['date_input'] = $dateInput;
                } else {
                    // Date range filter
                    if ($dateRange !== '') {
                        switch ($dateRange) {
                            case 'today':
                                $whereParts[] = "DATE(dr.date_requested) = CURDATE()";
                                break;
                            case 'week':
                                $whereParts[] = "WEEK(dr.date_requested) = WEEK(CURDATE()) AND YEAR(dr.date_requested) = YEAR(CURDATE())";
                                break;
                            case 'month':
                                $whereParts[] = "MONTH(dr.date_requested) = MONTH(CURDATE()) AND YEAR(dr.date_requested) = YEAR(CURDATE())";
                                break;
                        }
                    }
                }

                $whereClause = ' WHERE ' . implode(' AND ', $whereParts);
                
                // Get total count for pagination
                $countQuery = "SELECT COUNT(*) " . $baseQuery . $whereClause;
                $countStmt = $pdo->prepare($countQuery);
                $countStmt->execute($params);
                $total_records = $countStmt->fetchColumn();
                $total_pages = ceil($total_records / $records_per_page);
                
                // Build main query with pagination
                $query = "
                    SELECT 
                        dr.*, 
                        dt.doc_name AS doc_type_name, 
                        u.first_name, 
                        u.surname, 
                        e.email as email,
                        dr.date_requested
                    " . $baseQuery . $whereClause . "
                    ORDER BY dr.request_id DESC
                    LIMIT $records_per_page OFFSET $offset
                ";
                
                // Prepare and execute query
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                
                while ($request = $stmt->fetch()) {
                    // Get full name from first_name and surname
                    $fullName = htmlspecialchars(trim($request['first_name'] . ' ' . $request['surname']));
                    if (empty($fullName)) {
                        $fullName = htmlspecialchars($request['email']);
                    }
           
                    $docType = htmlspecialchars($request['doc_type_name']);
                    $purpose = htmlspecialchars($request['request_purpose'] ?? 'N/A');
                    
                    // Use date_requested instead of created_at
                    $requestTime = isset($request['date_requested']) && $request['date_requested'] 
                        ? date('Y-m-d H:i:s', strtotime($request['date_requested'])) 
                        : 'N/A';
                    
                    $status = $request['request_status'];
                    
                    // Status badge
                    $statusBadge = '';
                    switch ($status) {
                        case 'completed':
                            $statusBadge = "<span class='badge bg-success'>Completed</span>";
                            break;
                        case 'signed':
                            $statusBadge = "<span class='badge bg-info'>Signed</span>";
                            break;
                        case 'ready':
                            $statusBadge = "<span class='badge bg-success'>Ready for Pickup</span>";
                            break;
                        case 'rejected':
                            $statusBadge = "<span class='badge bg-danger'>Rejected</span>";
                            break;
                        case 'printed':
                            $statusBadge = "<span class='badge bg-primary'>Printed</span>";
                            break;
                        case 'for-signing':
                            $statusBadge = "<span class='badge bg-info'>For Signing</span>";
                            break;
                        case 'in-progress':
                            $statusBadge = "<span class='badge bg-primary'>In Progress</span>";
                            break;
                        case 'picked-up':
                            $statusBadge = "<span class='badge bg-success'>Picked Up</span>";
                            break;
                        case 'pending':
                            $statusBadge = "<span class='badge bg-warning'>Pending</span>";
                            break;
                        default:
                            $statusBadge = "<span class='badge bg-secondary'>" . ucfirst($status) . "</span>";
                    }
                    
                    echo "<tr data-status='$status' data-request-id='{$request['request_id']}'>";
                    echo "<td>$fullName</td>";
                    echo "<td>$docType</td>";
                    echo "<td>$purpose</td>";
                    echo "<td>$requestTime</td>";
                    echo "<td>$statusBadge</td>";
                    echo "<td class='text-end'>";
                    
                    // Actions based on status
                    if ($status === 'pending') {
                        // Only Details button
                        echo "<button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>";
                    } elseif ($status === 'in-progress') {
                        // Only Details button
                        echo "<button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>";
                    } elseif ($status === 'printed') {
                        // Only Details button (status already shown in Status column)
                        echo "<button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>";
                    } elseif ($status === 'signed') {
                        // Only Details button (Complete remains in preview modal)
                        echo "<button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>";
                    } elseif ($status === 'ready') {
                        // Only Details button
                        echo "<button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>";
                    } elseif ($status === 'for-signing') {
                        // Only Details button
                        echo "<button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>";
                    } else {
                        // For completed, rejected, and any others: only Details
                        echo "<button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>";
                    }
                    
                    echo "</td>";
                    echo "</tr>";
                }
                ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-3 px-2">
            <small class="text-muted">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?> entries
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <!-- Previous Button -->
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
                    
                    <!-- Next Button -->
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

<!-- Request Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Request Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="previewContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success d-none" id="modalAcceptBtn"><i class="bi bi-check-circle me-1"></i> Accept</button>
                <button type="button" class="btn btn-danger d-none" id="modalRejectBtn"><i class="bi bi-x-circle me-1"></i> Reject</button>
                <button type="button" class="btn btn-info d-none" id="modalViewBtn"><i class="bi bi-file-text me-1"></i> View</button>
                <button type="button" class="btn btn-success d-none" id="modalSignAsSecBtn"><i class="bi bi-pen me-1"></i> Sign</button>
                <button type="button" class="btn btn-success d-none" id="modalCompletePreviewBtn"><i class="bi bi-check2-circle me-1"></i> Complete</button>
                <button type="button" class="btn btn-success d-none" id="modalPickedUpBtn"><i class="bi bi-bag-check me-1"></i> Mark as Picked Up</button>
                <button type="button" class="btn btn-primary d-none" id="modalMarkPrintedBtn"><i class="bi bi-printer me-1"></i> Mark as Printed</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Document Preview Modal -->
<div class="modal fade" id="documentPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Document Preview & Print</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="documentPreviewFrame" style="width: 100%; height: 80vh; border: none;"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="generatePDF()"><i class="bi bi-file-earmark-pdf"></i> Generate PDF</button>
                <button type="button" class="btn btn-success" id="modalPrintBtn" style="display: none;" onclick="printDocumentFromModal()"><i class="bi bi-printer"></i> Print</button>
                <button type="button" class="btn btn-success" id="modalSignBtn" style="display: none;" onclick="signDocumentFromModal()"><i class="bi bi-pen"></i> Sign</button>
                <button type="button" class="btn btn-success" id="modalCompleteBtn" style="display: none;" onclick="completeDocumentFromModal()"><i class="bi bi-check-circle"></i> Complete</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
<script>
// Debug: Check if Bootstrap is loaded
document.addEventListener('DOMContentLoaded', function() {
    if (typeof bootstrap === 'undefined') {
        console.error('Bootstrap JavaScript is not loaded!');
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Bootstrap JavaScript is not loaded. Dropdowns may not work.'
        });
    } else {
        console.log('Bootstrap is loaded successfully');
    }
    
    // Initialize the request count display
    updateVisibleCount();
});

// Filters: quick buttons and auto open when active
document.addEventListener('DOMContentLoaded', () => {
    // Quick filter buttons for status/date_range
    document.querySelectorAll('.quick-filter-btn').forEach(button => {
        button.addEventListener('click', () => {
            const filterType = button.dataset.filterType;
            const filterValue = button.dataset.filterValue;
            const baseUrl = new URL(window.location.href.split('?')[0]);
            const currentParams = new URLSearchParams(window.location.search);

            // Preserve other params except the one being changed and pagination
            const ignore = ['page', filterType];
            currentParams.forEach((value, key) => {
                if (!ignore.includes(key)) baseUrl.searchParams.set(key, value);
            });

            const isActive = currentParams.get(filterType) === filterValue;
            if (!isActive) {
                baseUrl.searchParams.set(filterType, filterValue);
                if (filterType === 'date_range') {
                    baseUrl.searchParams.delete('date_input');
                }
            }

            // Navigate
            window.location.href = baseUrl.toString();
        });
    });

    // Auto-submit on document type change
    const docTypeSelect = document.querySelector('select[name="doc_type"]');
    if (docTypeSelect && docTypeSelect.form) {
        docTypeSelect.addEventListener('change', function(){ this.form.submit(); });
    }

    // Filter panel: always hidden initially; user toggles manually
    const panel = document.getElementById('filterPanel');
    const toggleBtn = document.getElementById('toggleFiltersBtn');
    if (panel && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
        const collapseInstance = bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false });
        // (leave collapsed by default)
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                collapseInstance.toggle();
            });
        }
    }

    // Initialize flatpickr for specific date
    const datePickerElement = document.getElementById('datePicker');
    if (typeof flatpickr !== 'undefined' && datePickerElement) {
        flatpickr(datePickerElement, { dateFormat: 'Y-m-d', allowInput: true });
        const urlObj = new URL(window.location);
        const dateInputVal = urlObj.searchParams.get('date_input');
        if (dateInputVal && datePickerElement._flatpickr) {
            datePickerElement._flatpickr.setDate(dateInputVal, true);
        }
    }
});

// Clear the specific date input
function clearSpecificDateFilter() {
    const el = document.getElementById('datePicker');
    if (el && el._flatpickr) {
        el._flatpickr.clear();
    } else if (el) {
        el.value = '';
    }
}

function updateRequest(requestId, action) {
    console.log('updateRequest called:', requestId, action);
    
    if (action === 'accept') {
        // ACCEPT ACTION - Pending → In Progress
        Swal.fire({
            title: 'Approve Document Request?',
            html: `
                <div class="text-start">
                    <p class="mb-3">You are about to approve this document request and move it to <strong>In Progress</strong> status.</p>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Next Steps:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Document will be prepared for printing</li>
                            <li>Resident will be notified of approval</li>
                            <li>You can now generate and print the document</li>
                        </ul>
                    </div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Approve Request',
            cancelButtonText: 'Cancel',
            customClass: {
                confirmButton: 'btn btn-success px-4',
                cancelButton: 'btn btn-secondary'
            },
            buttonsStyling: false
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                Swal.fire({
                    title: 'Processing...',
                    text: 'Accepting the document request',
                    icon: 'info',
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ update_request: '1', request_id: requestId, action, ajax: '1' })
                }).then(r => r.json()).then(res => {
                    Swal.close();
                    if (res.success) {
                        updateRowStatus(requestId, res.status, res.statusBadge);
                        Swal.fire({
                            icon: 'success',
                            title: 'Request Approved!',
                            text: res.message || 'Moved to In Progress.',
                            timer: 1200,
                            showConfirmButton: false,
                            timerProgressBar: true
                        }).then(() => {
                            window.location.href = 'document_management.php?status=in-progress';
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: res.message || 'Failed to approve the request.',
                            confirmButtonText: 'OK'
                        });
                    }
                }).catch((error) => {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Network Error',
                        text: 'Please check your connection and try again',
                        confirmButtonText: 'OK'
                    });
                });
            }
        });
    } else if (action === 'reject') {
        // REJECT ACTION
        Swal.fire({
            title: 'Reject Document Request?',
            html: `
                <div class="text-start">
                    <p class="mb-3">You are about to <strong class="text-danger">reject</strong> this document request.</p>
                    <div class="alert alert-danger">
                        <i class="bi bi-x-circle me-2"></i>
                        <strong>This action cannot be undone. The resident will be notified of the rejection.</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Rejection Reason:</label>
                        <select id="rejectionReason" class="form-select" required>
                            <option value="">Choose a reason...</option>
                            <option value="Incomplete Information">Incomplete or incorrect information</option>
                            <option value="Non-Residency">Requester is not a verified resident</option>
                            <option value="Unpaid Obligations">Unpaid barangay fees or obligations</option>
                            <option value="Pending Complaints">Pending complaints or violations</option>
                            <option value="Invalid Purpose">Invalid purpose or potential misuse</option>
                            <option value="Document Not Available">Requested document not available</option>
                            <option value="Others">Other reasons</option>
                        </select>
                    </div>
                    <div id="otherReasonContainer" style="display: none;">
                        <label class="form-label fw-semibold">Please specify reason:</label>
                        <textarea id="rejectionDetails" class="form-control" rows="3" 
                                  placeholder="Provide specific details for rejection..."></textarea>
                    </div>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Reject Request',
            cancelButtonText: 'Cancel',
            customClass: {
                confirmButton: 'btn btn-danger px-4',
                cancelButton: 'btn btn-secondary'
            },
            buttonsStyling: false,
            preConfirm: () => {
                const reason = document.getElementById('rejectionReason').value;
                const details = document.getElementById('rejectionDetails').value;
                
                if (!reason) {
                    Swal.showValidationMessage('Please select a rejection reason');
                    return false;
                }
                
                if (reason === 'Others' && !details.trim()) {
                    Swal.showValidationMessage('Please provide details for the rejection');
                    return false;
                }
                
                return { 
                    reason: reason, 
                    details: details,
                    fullReason: reason === 'Others' ? details : reason
                };
            },
            didOpen: () => {
                const select = document.getElementById('rejectionReason');
                const container = document.getElementById('otherReasonContainer');
                
                select.addEventListener('change', function() {
                    if (this.value === 'Others') {
                        container.style.display = 'block';
                    } else {
                        container.style.display = 'none';
                    }
                });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const rejectionReason = result.value.fullReason;

                // Show loading state
                Swal.fire({
                    title: 'Processing...',
                    text: 'Rejecting the document request',
                    icon: 'info',
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ 
                        update_request: '1', 
                        request_id: requestId, 
                        action, 
                        rejection_reason: rejectionReason, 
                        ajax: '1' 
                    })
                }).then(r => r.json()).then(res => {
                    Swal.close();
                    if (res.success) {
                        updateRowStatus(requestId, res.status, res.statusBadge);
                        Swal.fire({
                            icon: 'success',
                            title: 'Request Rejected!',
                            text: res.message,
                            timer: 2000,
                            showConfirmButton: false,
                            timerProgressBar: true
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: res.message || 'Failed to reject the request',
                            confirmButtonText: 'OK'
                        });
                    }
                }).catch((error) => {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Network Error',
                        text: 'Please check your connection and try again',
                        confirmButtonText: 'OK'
                    });
                });
            }
        });
    } else {
        // For other actions (print, complete, sign-as-secretary, picked-up)
        let config = {};
        
        switch(action) {
            case 'for-signing':
            case 'print':
                config = {
                    title: 'Mark as Printed & Signed?',
                    html: `
                        <div class="text-start">
                            <p class="mb-3">Mark this request as <strong>Completed</strong> — printed and signed; ready for pickup.</p>
                            <div class="alert alert-success">
                                <i class="bi bi-check2-circle me-2"></i>
                                <strong>This will:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Set status to Completed</li>
                                    <li>Indicate document is printed and signed</li>
                                    <li>Show it under Completed tab</li>
                                </ul>
                            </div>
                        </div>
                    `,
                    icon: 'question',
                    confirmButtonText: 'Yes, Mark Completed',
                    successTitle: 'Marked as Completed!',
                    successHtml: `
                        <div class="text-start">
                            <p>Request status updated to <span class="badge bg-success">Completed</span>.</p>
                            <p class="mb-0">Printed and signed; ready for pickup.</p>
                        </div>
                    `
                };
                break;
                
            case 'sign-as-secretary':
                config = {
                    title: 'Sign Document as Secretary?',
                    html: `
                        <div class="text-start">
                            <p class="mb-3">You are about to sign this document and mark it as <strong>Signed</strong>.</p>
                            <div class="alert alert-info">
                                <i class="bi bi-shield-check me-2"></i>
                                <strong>This action will:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Mark document as officially signed</li>
                                    <li>Notify resident that document is ready for pickup</li>
                                    <li>Complete the signing process</li>
                                </ul>
                            </div>
                        </div>
                    `,
                    icon: 'question',
                    confirmButtonText: 'Yes, Sign Document',
                    successTitle: 'Document Signed!',
                    successHtml: `
                        <div class="text-start">
                            <p>Document has been <strong>signed</strong> and marked as <span class="badge bg-info">Signed</span>.</p>
                            <div class="alert alert-success mt-2">
                                <i class="bi bi-bell me-2"></i>
                                <strong>Notification sent:</strong> Resident notified that document is ready for pickup
                            </div>
                        </div>
                    `,
                };
                break;
                
            case 'complete':
                config = {
                    title: 'Mark Document as Completed?',
                    html: `
                        <div class="text-start">
                            <p class="mb-3">You are about to mark this document request as <strong>Completed</strong>.</p>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>
                                <strong>Please confirm that:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Resident has received the document</li>
                                    <li>All required signatures are in place</li>
                                    <li>Document has been properly handed over</li>
                                </ul>
                            </div>
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" id="confirmReceipt">
                                <label class="form-check-label" for="confirmReceipt">
                                    I confirm the resident has received the document
                                </label>
                            </div>
                        </div>
                    `,
                    icon: 'success',
                    confirmButtonText: 'Yes, Mark as Completed',
                    successTitle: 'Request Completed!',
                    successHtml: `
                        <div class="text-start">
                            <p>Document request has been <strong>completed</strong> <span class="badge bg-success">Completed</span>.</p>
                            <div class="alert alert-success mt-2">
                                <i class="bi bi-flag-fill me-2"></i>
                                <strong>Process Complete:</strong> Resident has received the document
                            </div>
                        </div>
                    `,
                    requireCheckbox: true,
                    checkboxId: 'confirmReceipt',
                    checkboxMessage: 'Please confirm that resident has received the document'
                };
                break;
            
            case 'picked-up':
                config = {
                    title: 'Mark Document as Picked Up?',
                    html: `
                        <div class="text-start">
                            <p class="mb-3">Confirm that the resident has <strong>picked up</strong> the document.</p>
                            <div class="alert alert-success">
                                <i class="bi bi-bag-check me-2"></i>
                                This will set the status to <span class="badge bg-success">Picked Up</span>.
                            </div>
                        </div>
                    `,
                    icon: 'question',
                    confirmButtonText: 'Yes, Mark as Picked Up',
                    successTitle: 'Marked as Picked Up!',
                    successHtml: `
                        <div class="text-start">
                            <p>Status updated to <span class="badge bg-success">Picked Up</span>.</p>
                        </div>
                    `
                };
                break;
                
            default:
                config = {
                    title: 'Confirm Action',
                    text: `Are you sure you want to ${action.replace('-', ' ')} this request?`,
                    icon: 'warning',
                    confirmButtonText: 'Yes, proceed!'
                };
        }

        const swalConfig = {
            title: config.title,
            html: config.html,
            icon: config.icon,
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d',
            confirmButtonText: config.confirmButtonText,
            cancelButtonText: 'Cancel',
            customClass: {
                confirmButton: 'btn btn-primary px-4',
                cancelButton: 'btn btn-secondary'
            },
            buttonsStyling: false
        };

        // Add preConfirm for checkbox validation if required
        if (config.requireCheckbox) {
            swalConfig.preConfirm = () => {
                if (!document.getElementById(config.checkboxId).checked) {
                    Swal.showValidationMessage(config.checkboxMessage);
                    return false;
                }
                return true;
            };
        }

        Swal.fire(swalConfig).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                Swal.fire({
                    title: 'Processing...',
                    text: 'Updating request status',
                    icon: 'info',
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ update_request: '1', request_id: requestId, action, ajax: '1' })
                }).then(r => r.json()).then(res => {
                    Swal.close();
                    if (res.success) {
                        updateRowStatus(requestId, res.status, res.statusBadge);
                        // Define redirect targets for workflow chain
                        const redirectTargets = {
                            'for-signing': 'completed',
                            'print': 'completed',
                            'picked-up': 'picked-up'
                        };
                        const targetStatus = redirectTargets[action] || null;
                        let swalPromise;
                        if (config.successTitle) {
                            swalPromise = Swal.fire({
                                icon: 'success',
                                title: config.successTitle,
                                html: config.successHtml,
                                timer: 1200,
                                showConfirmButton: false,
                                timerProgressBar: true
                            });
                        } else {
                            swalPromise = Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: res.message,
                                timer: 1000,
                                showConfirmButton: false,
                                timerProgressBar: true
                            });
                        }
                        if (targetStatus) {
                            swalPromise.then(() => {
                                window.location.href = 'document_management.php?status=' + targetStatus;
                            });
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: res.message || `Failed to ${action.replace('-', ' ')} the request`,
                            confirmButtonText: 'OK'
                        });
                    }
                }).catch((error) => {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Network Error',
                        text: 'Please check your connection and try again',
                        confirmButtonText: 'OK'
                    });
                });
            }
        });
    }
}

// Print document - Generate and display in modal for printing
function printDocument(requestId) {
    console.log('printDocument called:', requestId);
    const iframe = document.getElementById('documentPreviewFrame');
    iframe.src = `view_document.php?request_id=${requestId}`;
    const modal = new bootstrap.Modal(document.getElementById('documentPreviewModal'));
    modal.show();
}

// Mark document as printed and move to for-signing status
function markAsPrinted(requestId, checkbox = null) {
    updateRequest(requestId, 'for-signing');
}

// Sign document as secretary (when captain is not available)
function signDocument(requestId) {
    updateRequest(requestId, 'sign-as-secretary');
}

// Mark document as completed (when resident has received it)
function completeDocument(requestId) {
    updateRequest(requestId, 'complete');
}

function showPreview(requestId) {
    console.log('showPreview called:', requestId);
    fetch(`get_request_details.php?id=${requestId}`)
        .then(response => response.json())
        .then(data => {
            const html = data && typeof data === 'object' ? (data.html || '') : '';
            document.getElementById('previewContent').innerHTML = html;
            window.currentPreviewRequestId = requestId;

            // Determine status from the corresponding row via data attribute
            let rowStatus = '';
            const row = document.querySelector(`tbody tr[data-request-id="${requestId}"]`);
            if (row) rowStatus = row.getAttribute('data-status') || '';

            // Show/hide modal action buttons based on status
            const acceptBtn = document.getElementById('modalAcceptBtn');
            const rejectBtn = document.getElementById('modalRejectBtn');
            const viewBtn = document.getElementById('modalViewBtn');
            const markPrintedBtn = document.getElementById('modalMarkPrintedBtn');
            const pickedUpBtn = document.getElementById('modalPickedUpBtn');
            const signAsSecBtn = document.getElementById('modalSignAsSecBtn');
            const completePreviewBtn = document.getElementById('modalCompletePreviewBtn');

            [acceptBtn, rejectBtn, viewBtn, markPrintedBtn, signAsSecBtn, completePreviewBtn, pickedUpBtn].forEach(el => { if (el) el.classList.add('d-none'); });

            if (rowStatus === 'pending') {
                if (acceptBtn) acceptBtn.classList.remove('d-none');
                if (rejectBtn) rejectBtn.classList.remove('d-none');
            } else if (rowStatus === 'in-progress') {
                if (viewBtn) viewBtn.classList.remove('d-none');
                if (markPrintedBtn) markPrintedBtn.classList.remove('d-none');
            } else if (rowStatus === 'printed' || rowStatus === 'for-signing') {
                if (viewBtn) viewBtn.classList.remove('d-none');
                if (signAsSecBtn) signAsSecBtn.classList.remove('d-none');
            } else if (rowStatus === 'signed') {
                if (viewBtn) viewBtn.classList.remove('d-none');
                if (completePreviewBtn) completePreviewBtn.classList.remove('d-none');
            } else if (rowStatus === 'completed') {
                if (viewBtn) viewBtn.classList.remove('d-none');
                if (pickedUpBtn) pickedUpBtn.classList.remove('d-none');
            } else if (rowStatus === 'picked-up') {
                if (viewBtn) viewBtn.classList.remove('d-none');
            }

            // Wire click handlers
            if (acceptBtn) acceptBtn.onclick = function() {
                const pm = bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal'));
                if (pm) pm.hide();
                if (window.currentPreviewRequestId) updateRequest(window.currentPreviewRequestId, 'accept');
            };
            if (rejectBtn) rejectBtn.onclick = function() {
                const pm = bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal'));
                if (pm) pm.hide();
                if (window.currentPreviewRequestId) updateRequest(window.currentPreviewRequestId, 'reject');
            };
            if (viewBtn) viewBtn.onclick = function() {
                const pm = bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal'));
                if (pm) pm.hide();
                if (window.currentPreviewRequestId) viewDocument(window.currentPreviewRequestId);
            };
            if (completePreviewBtn) completePreviewBtn.onclick = function() {
                const pm = bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal'));
                if (pm) pm.hide();
                if (window.currentPreviewRequestId) completeDocument(window.currentPreviewRequestId);
            };
            if (signAsSecBtn) signAsSecBtn.onclick = function() {
                const pm = bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal'));
                if (pm) pm.hide();
                if (window.currentPreviewRequestId) signDocument(window.currentPreviewRequestId);
            };
            if (markPrintedBtn) markPrintedBtn.onclick = function() {
                const pm = bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal'));
                if (pm) pm.hide();
                if (window.currentPreviewRequestId) markAsPrinted(window.currentPreviewRequestId);
            };
            if (pickedUpBtn) pickedUpBtn.onclick = function() {
                const pm = bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal'));
                if (pm) pm.hide();
                if (window.currentPreviewRequestId) updateRequest(window.currentPreviewRequestId, 'picked-up');
            };

            new bootstrap.Modal(document.getElementById('previewModal')).show();
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({ icon: 'error', title: 'Error', text: 'Error loading preview' });
        });
}

function openPrintDialog(requestId, filename) {
    console.log('openPrintDialog called:', requestId, filename);
    
    // Create a hidden iframe for printing without opening new tab
    const iframe = document.createElement('iframe');
    iframe.style.position = 'absolute';
    iframe.style.left = '-9999px';
    iframe.style.top = '-9999px';
    iframe.style.width = '1px';
    iframe.style.height = '1px';
    iframe.src = `../../uploads/generated_documents/${filename}`;
    document.body.appendChild(iframe);
    
    iframe.onload = function() {
        // Wait a bit longer for the document to fully render
        setTimeout(() => {
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
                
                // Don't remove iframe immediately - wait for user to finish printing
                // Clean up after a longer delay to allow printing to complete
                setTimeout(() => {
                    if (iframe.parentNode) {
                        document.body.removeChild(iframe);
                    }
                }, 30000); // 30 seconds delay for cleanup
                
            } catch (e) {
                // Fallback: Open in new window if iframe printing fails
                console.log('Iframe printing failed, using fallback');
                const printWindow = window.open(`../../uploads/generated_documents/${filename}`, '_blank');
                setTimeout(() => {
                    if (printWindow && !printWindow.closed) {
                        printWindow.print();
                    }
                }, 1000);
                
                // Clean up iframe since fallback is used
                if (iframe.parentNode) {
                    document.body.removeChild(iframe);
                }
            }
        }, 1000); // Increased delay to ensure document is fully loaded
    };
    
    // Error handling for load failure
    iframe.onerror = function() {
        console.log('Failed to load document for printing');
        if (iframe.parentNode) {
            document.body.removeChild(iframe);
        }
    };
}

function viewDocument(requestId) {
    console.log('viewDocument called:', requestId);
    // Store the current request ID for signing/completing
    window.currentRequestId = requestId;
    
    // Open document in modal instead of new tab
    const iframe = document.getElementById('documentPreviewFrame');
    iframe.src = `view_document.php?request_id=${requestId}`;
    
    // Show/hide action buttons based on document status (use row data attributes)
    const printBtn = document.getElementById('modalPrintBtn');
    const signBtn = document.getElementById('modalSignBtn');
    const completeBtn = document.getElementById('modalCompleteBtn');

    // Reset visibility
    if (printBtn) printBtn.style.display = 'none';
    if (signBtn) signBtn.style.display = 'none';
    if (completeBtn) completeBtn.style.display = 'none';

    const row = document.querySelector(`tbody tr[data-request-id="${requestId}"]`);
    const statusKey = row ? (row.getAttribute('data-status') || '').toLowerCase() : '';
    console.log('Document status key for request', requestId, ':', statusKey);

    // Map status to visible controls
    if (statusKey === 'in-progress') {
        if (printBtn) printBtn.style.display = 'inline-block';
    } else if (statusKey === 'signed' && completeBtn) {
        completeBtn.style.display = 'inline-block';
    } else if (statusKey === 'completed') {
        // keep hidden
        // show Picked Up CTA in preview modal instead of here
    } else if (statusKey === 'printed' || statusKey === 'for-signing') {
        if (signBtn) signBtn.style.display = 'inline-block';
    } else if (statusKey === 'ready') {
        // Depending on flow, could show complete; keeping as-is for now
    }
    
    const modal = new bootstrap.Modal(document.getElementById('documentPreviewModal'));
    modal.show();
}

// Helper: update table row status without reload and optionally hide if filtered out
function updateRowStatus(requestId, newStatus, statusBadgeHtml) {
    const row = document.querySelector(`tbody tr[data-request-id='${requestId}']`);
    if (!row) return;
    row.setAttribute('data-status', newStatus);
    // status cell is 5th column
    const statusCell = row.children[4];
    if (statusCell) statusCell.innerHTML = statusBadgeHtml || newStatus;

    // If current view is filtered by status and row no longer matches, remove it
    const params = new URLSearchParams(location.search);
    const view = params.get('status') || 'all';
    const matchesView = (function(){
        if (view === 'all') return true;
        if (view === 'for-signing') return newStatus === 'printed';
        if (view === 'completed') return ['completed','ready'].includes(newStatus);
        if (view === 'picked-up') return newStatus === 'picked-up';
        return newStatus === view;
    })();
    if (!matchesView) {
        row.parentNode.removeChild(row);
    }

    // Recalculate count display if present
    if (typeof updateVisibleCount === 'function') updateVisibleCount();
}

function signDocumentFromModal() {
    if (window.currentRequestId) {
        signDocument(window.currentRequestId);
        // Close the modal after signing
        const modal = bootstrap.Modal.getInstance(document.getElementById('documentPreviewModal'));
        if (modal) {
            modal.hide();
        }
    }
}

function completeDocumentFromModal() {
    if (window.currentRequestId) {
        completeDocument(window.currentRequestId);
        // Close the modal after completing
        const modal = bootstrap.Modal.getInstance(document.getElementById('documentPreviewModal'));
        if (modal) {
            modal.hide();
        }
    }
}

function printDocumentFromModal() {
    console.log('printDocumentFromModal called');
    const iframe = document.getElementById('documentPreviewFrame');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.print();
    }
}

function downloadDocumentOnly(filename) {
    console.log('downloadDocumentOnly called:', filename);
    
    // Simply trigger download without updating status
    const link = document.createElement('a');
    link.href = `../../uploads/generated_documents/${filename}`;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function markAsCompleted(requestId, checkbox) {
    console.log('markAsCompleted called:', requestId, checkbox.checked);
    
    if (checkbox.checked) {
        Swal.fire({
            title: 'Mark as Picked Up?',
            text: 'Confirm the resident has picked up the document. This will move it to the Picked Up list.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, mark as picked up!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="update_request" value="1">
                    <input type="hidden" name="request_id" value="${requestId}">
                    <input type="hidden" name="action" value="picked-up">
                `;
                document.body.appendChild(form);
                form.submit();
            } else {
                // Uncheck if user cancels
                checkbox.checked = false;
            }
        });
    }
}

function printDocumentOnly(filename) {
    console.log('printDocumentOnly called:', filename);
    
    // Open print dialog for the document
    const printWindow = window.open(`../../uploads/generated_documents/${filename}`, '_blank');
    printWindow.onload = function() {
        printWindow.print();
    };
}

// Status filtering function
function filterByStatus(status) {
    const table = document.getElementById('requestsTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    // Update button states
    const buttons = document.querySelectorAll('[id^="filter-"]');
    buttons.forEach(btn => {
        btn.classList.remove('btn-primary', 'active');
        btn.classList.add('btn-outline-primary');
    });
    
    // Set active button
    document.getElementById('filter-' + status).classList.remove('btn-outline-primary');
    document.getElementById('filter-' + status).classList.add('btn-primary', 'active');
    
    // Filter rows
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const rowStatus = row.getAttribute('data-status');
        
        if (status === 'all' || rowStatus === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
    
    // Update visible count
    updateVisibleCount();
}

// Function to count and display visible rows
function updateVisibleCount() {
    const table = document.getElementById('requestsTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    let visibleCount = 0;
    
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].style.display !== 'none') {
            visibleCount++;
        }
    }
    
    // Add or update count display
    let countDisplay = document.getElementById('request-count');
    if (!countDisplay) {
        countDisplay = document.createElement('small');
        countDisplay.id = 'request-count';
        countDisplay.className = 'text-muted ms-2';
        document.querySelector('h2').appendChild(countDisplay);
    }
}

// Generate PDF from HTML document
function generatePDF() {
    const iframe = document.getElementById('documentPreviewFrame');
    const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
    
    // Load jsPDF library if not already loaded
    if (typeof window.jspdf === 'undefined') {
        Swal.fire({
            icon: 'info',
            title: 'Loading PDF Library',
            text: 'Loading PDF library, please try again in a moment...',
            timer: 2000,
            showConfirmButton: false
        });
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
        document.head.appendChild(script);
        return;
    }
    
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({
        unit: 'mm',
        format: 'a4',
        orientation: 'portrait'
    });
    
    // Get the document content
    const element = iframeDoc.body;
    
    doc.html(element, {
        callback: function(doc) {
            doc.save('barangay_document.pdf');
        },
        x: 0,
        y: 0,
        width: 210,
        windowWidth: 794, // A4 width in pixels at 96 DPI
        html2canvas: {
            scale: 0.6,
            useCORS: true,
            logging: false
        }
    });
}

// Print document from modal
function printDocumentFromModal() {
    const iframe = document.getElementById('documentPreviewFrame');
    try {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    } catch (e) {
        console.error('Print failed:', e);
        Swal.fire({
            icon: 'error',
            title: 'Print Failed',
            text: 'Unable to print. Please use the browser print function.'
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Make sure Bootstrap dropdowns work
    const dropdownElements = document.querySelectorAll('[data-bs-toggle="dropdown"]');
    dropdownElements.forEach(function(element) {
        element.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const dropdown = new bootstrap.Dropdown(element);
            dropdown.toggle();
        });
    });
});
</script>

<?php include 'footer.php'; ?>
