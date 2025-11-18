w<?php 
include 'header.php'; 
require_once '../../includes/document_generator.php';
?>

<style>
/* Disable scrolling */
body {
    overflow: hidden;
}

.container {
    max-height: 100vh;
    overflow: hidden;
}

/* Fix for header overlap */
.container {
    padding-top: 80px;
    margin-top: 0;
}

/* Fix dropdown visibility in table */
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

/* Print checkbox styling */
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

/* Action column width */
.table th:last-child,
.table td:last-child {
    min-width: 520px;
}

/* View button styling */
.btn-sm {
    min-width: 70px;
}

.btn-group .btn-sm {
    min-width: auto;
}

/* Compact table styling */
.table td, .table th {
    padding: 0.4rem 0.6rem;
    font-size: 0.85rem;
    vertical-align: middle;
}

.table thead th {
    font-size: 0.85rem;
    padding: 0.5rem 0.6rem;
}

/* Badge sizing */
.badge {
    font-size: 0.75rem;
    padding: 0.3rem 0.5rem;
}

/* Button sizing for compact view */
.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
    min-width: 65px;
}

.btn-group .btn-sm {
    min-width: auto;
    padding: 0.25rem 0.4rem;
}

/* Action column */
.table th:last-child,
.table td:last-child {
    min-width: 480px;
}

/* Checkbox smaller */
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
</style>

<div class="container">
    <h2><i class="bi bi-folder2-open me-2"></i>Requests
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
        } elseif ($statusFilter === 'ready') {
            $badgeColor = 'bg-success';
        } elseif ($statusFilter === 'for-signing') {
            $badgeColor = 'bg-info';
        }
        
        echo " - <span class='badge $badgeColor'>$filterLabel</span>";
        ?>
    </h2>
    
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
    // Handle AJAX request for status update
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_request'])) {
        $request_id = $_POST['request_id'];
        $action = $_POST['action'];
        
        try {
            if ($action === 'accept') {
                // Accept the document request (document will be generated when printed)
                $status_to_save = 'in-progress';
                $message = "Request accepted. You can now print the document.";
                $alertClass = 'success';
                
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
                // Mark as printed - document will be waiting for signature
                $status_to_save = 'printed';
                $message = "Document marked as printed and forwarded to captain for signing.";
                $alertClass = 'info';
                
                // Note: Captain will see documents with 'printed' status in their documents_for_signing page
                
            } elseif ($action === 'reject') {
                $status_to_save = 'rejected';
                $rejection_reason = isset($_POST['rejection_reason']) ? $_POST['rejection_reason'] : 'No reason provided';
                $message = "Request has been rejected.";
                $alertClass = 'danger';
                
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
                // Mark document as printed - waiting for captain to sign
                $status_to_save = 'printed';
                $message = "Document marked as printed and forwarded to captain for signing.";
                $alertClass = 'info';
                
                // Note: Captain will see documents with 'printed' status in their documents_for_signing page
                
            } elseif ($action === 'complete') {
                // Mark as completed when resident receives the document (signed -> completed)
                $status_to_save = 'completed';
                $message = "Document marked as completed - resident has received the document.";
                $alertClass = 'success';
                
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
                
            } elseif ($action === 'sign-as-secretary') {
                // Secretary signs document when captain is not available (printed -> signed)
                $status_to_save = 'signed';
                $message = "Document signed by Secretary.";
                $alertClass = 'success';
                
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

            $stmt = $pdo->prepare("UPDATE document_requests SET request_status = ? WHERE request_id = ?");
            $stmt->execute([$status_to_save, $request_id]);

            // Log activity
            logActivity($_SESSION['user_id'], "Updated request status: $request_id to $status_to_save");
            
            // Build redirect URL properly
            $currentURL = $_SERVER['REQUEST_URI'];
            $baseURL = strtok($currentURL, '?');
            $statusParam = isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : '';
            $redirectURL = $baseURL . $statusParam;
            
            // Add success parameter
            if (!empty($statusParam)) {
                $redirectURL .= '&success=' . urlencode($message);
            } else {
                $redirectURL .= '?success=' . urlencode($message);
            }
            
            echo "<script>
                setTimeout(function() {
                    window.location.href = '" . addslashes($redirectURL) . "';
                }, 1500);
            </script>";
            echo "<div class='alert alert-$alertClass'>$message <br><small>Redirecting...</small></div>";
            
        } catch (Exception $e) {
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
                    <th>Request Time</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Build query based on status filter
                $statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
                
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
                
                // Add WHERE clause for status filter
                $whereClause = "";
                if ($statusFilter !== 'all') {
                    // Special case: "for-signing" filter shows "printed" status documents
                    if ($statusFilter === 'for-signing') {
                        $whereClause = " WHERE dr.request_status = 'printed'";
                    // Special case: "completed" filter shows "completed", "signed", and "ready" status documents
                    } elseif ($statusFilter === 'completed') {
                        $whereClause = " WHERE dr.request_status IN ('completed', 'signed', 'ready')";
                    } else {
                        $whereClause = " WHERE dr.request_status = :status";
                    }
                }
                
                // Get total count for pagination
                $countQuery = "SELECT COUNT(*) " . $baseQuery . $whereClause;
                if ($statusFilter !== 'all') {
                    if ($statusFilter === 'for-signing' || $statusFilter === 'completed') {
                        $countStmt = $pdo->query($countQuery);
                    } else {
                        $countStmt = $pdo->prepare($countQuery);
                        $countStmt->execute(['status' => $statusFilter]);
                    }
                } else {
                    $countStmt = $pdo->query($countQuery);
                }
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
                if ($statusFilter !== 'all') {
                    if ($statusFilter === 'for-signing' || $statusFilter === 'completed') {
                        $stmt = $pdo->query($query);
                    } else {
                        $stmt = $pdo->prepare($query);
                        $stmt->execute(['status' => $statusFilter]);
                    }
                } else {
                    $stmt = $pdo->query($query);
                }
                
                while ($request = $stmt->fetch()) {
                    // Get full name from first_name and surname
                    $fullName = htmlspecialchars(trim($request['first_name'] . ' ' . $request['surname']));
                    if (empty($fullName)) {
                        $fullName = htmlspecialchars($request['email']);
                    }
           
                    $docType = htmlspecialchars($request['doc_type_name']);
                    
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
                        case 'pending':
                            $statusBadge = "<span class='badge bg-warning'>Pending</span>";
                            break;
                        default:
                            $statusBadge = "<span class='badge bg-secondary'>" . ucfirst($status) . "</span>";
                    }
                    
                    echo "<tr data-status='$status'>";
                    echo "<td>$fullName</td>";
                    echo "<td>$docType</td>";
                    echo "<td>$requestTime</td>";
                    echo "<td>$statusBadge</td>";
                    echo "<td>";
                    
                    // Actions based on status
                    if ($status === 'pending') {
                        // Show dropdown for accept/reject
                        echo "
                        <div class='d-flex gap-2'>
                            <button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> See Details</button>
                            <div class='dropdown'>
                                <button class='btn btn-sm btn-success dropdown-toggle' type='button' id='dropdownMenuButton{$request['request_id']}' data-bs-toggle='dropdown' aria-expanded='false'>
                                    <i class='bi bi-gear'></i> Actions
                                </button>
                                <ul class='dropdown-menu' aria-labelledby='dropdownMenuButton{$request['request_id']}'>
                                    <li><a class='dropdown-item text-success' href='#' onclick=\"updateRequest({$request['request_id']}, 'accept'); return false;\"><i class='bi bi-check-circle'></i> Accept</a></li>
                                    <li><a class='dropdown-item text-danger' href='#' onclick=\"updateRequest({$request['request_id']}, 'reject'); return false;\"><i class='bi bi-x-circle'></i> Reject</a></li>
                                </ul>
                            </div>
                        </div>";
                    } elseif ($status === 'in-progress') {
                        // Show View button and printed checkbox for accepted documents
                        echo "
                        <div class='d-flex gap-2 align-items-center'>
                            <button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>
                            <button class='btn btn-sm btn-info' type='button' onclick=\"viewDocument({$request['request_id']})\"><i class='bi bi-file-text'></i> View</button>
                            <div class='form-check ms-2'>
                                <input class='form-check-input' type='checkbox' id='printed_{$request['request_id']}' onchange=\"markAsPrinted({$request['request_id']}, this)\">
                                <label class='form-check-label small' for='printed_{$request['request_id']}'>
                                     Document Printed?
                                </label>
                            </div>
                        </div>";
                    } elseif ($status === 'printed') {
                        // Show preview - document is printed and waiting for captain or secretary to sign
                        echo "
                        <div class='d-flex gap-2 align-items-center'>
                            <button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>
                            <button class='btn btn-sm btn-info' type='button' onclick=\"viewDocument({$request['request_id']})\"><i class='bi bi-file-text'></i> View</button>
                            <span class='badge bg-info'><i class='bi bi-hourglass-split'></i> Waiting for Signature</span>
                        </div>";
                    } elseif ($status === 'signed') {
                        // Show complete button - document has been signed and ready to be marked as completed when resident receives it
                        echo "
                        <div class='d-flex gap-2 align-items-center'>
                            <button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>
                            <button class='btn btn-sm btn-info' type='button' onclick=\"viewDocument({$request['request_id']})\"><i class='bi bi-file-text'></i> View</button>
                            <span class='badge bg-success'><i class='bi bi-check2-circle'></i> Ready for Release</span>
                        </div>";
                    } elseif ($status === 'ready') {
                        // Show complete button - document is ready for pickup
                        echo "
                        <div class='d-flex gap-2 align-items-center'>
                            <button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>
                            <button class='btn btn-sm btn-info' type='button' onclick=\"viewDocument({$request['request_id']})\"><i class='bi bi-file-text'></i> View</button>
                            <span class='badge bg-success'><i class='bi bi-check2-circle'></i> Ready for Release</span>
                        </div>";
                    } elseif ($status === 'for-signing') {
                        // Legacy status - redirect to printed
                        echo "
                        <div class='d-flex gap-2 align-items-center'>
                            <button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>
                            <button class='btn btn-sm btn-info' type='button' onclick=\"viewDocument({$request['request_id']})\"><i class='bi bi-file-text'></i> View</button>
                            <span class='badge bg-info'><i class='bi bi-hourglass-split'></i> Waiting for Signature</span>
                        </div>";
                    } else {
                        // For completed, rejected, etc.
                        echo "<div class='d-flex gap-2 align-items-center'>";
                        echo "<button class='btn btn-sm btn-outline-secondary' type='button' onclick=\"showPreview({$request['request_id']})\"><i class='bi bi-eye'></i> Details</button>";
                        
                        if ($status === 'completed') {
                            echo " <button class='btn btn-sm btn-info' type='button' onclick=\"viewDocument({$request['request_id']})\"><i class='bi bi-file-text'></i> View</button>";
                        }
                        echo "</div>";
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

function updateRequest(requestId, action) {
    console.log('updateRequest called:', requestId, action);
    
    if (action === 'accept') {
        // Just accept the request, don't generate document yet
        Swal.fire({
            title: 'Accept Request?',
            text: 'Are you sure you want to accept this document request?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, accept it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="update_request" value="1">
                    <input type="hidden" name="request_id" value="${requestId}">
                    <input type="hidden" name="action" value="${action}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    } else {
        // For other actions (reject, etc.)
        if (action === 'reject') {
            // Show SweetAlert with dropdown for rejection reasons
            Swal.fire({
                title: 'Reject Document Request',
                html: `
                    <p>Please select a reason for rejection:</p>
                    <select id="rejectionReason" class="swal2-input" style="width: 100%; padding: 10px; border: 1px solid #d9d9d9; border-radius: 5px;">
                        <option value="">Select a reason...</option>
                        <option value="Wrong information">Wrong information</option>
                        <option value="Non-Residency">Non-Residency</option>
                        <option value="Unpaid Barangay Obligation">Unpaid Barangay Obligation</option>
                        <option value="Pending or Unresolved Complaint">Pending or Unresolved Complaint</option>
                        <option value="Invalid Purpose or Misuse of Document">Invalid Purpose or Misuse of Document</option>
                        <option value="Non-Compliance with Barangay Rules or Ordinance">Non-Compliance with Barangay Rules or Ordinance</option>
                        <option value="Others">Others</option>
                    </select>
                    <textarea id="rejectionDetails" class="swal2-textarea" placeholder="Additional details (optional)" style="margin-top: 10px; display: none;"></textarea>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Reject Request',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    const reason = document.getElementById('rejectionReason').value;
                    const details = document.getElementById('rejectionDetails').value;
                    
                    if (!reason) {
                        Swal.showValidationMessage('Please select a reason for rejection');
                        return false;
                    }
                    
                    return { reason: reason, details: details };
                },
                didOpen: () => {
                    // Show textarea when "Others" is selected
                    const select = document.getElementById('rejectionReason');
                    const textarea = document.getElementById('rejectionDetails');
                    
                    select.addEventListener('change', function() {
                        if (this.value === 'Others') {
                            textarea.style.display = 'block';
                        } else {
                            textarea.style.display = 'none';
                        }
                    });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    
                    let rejectionReason = result.value.reason;
                    if (result.value.reason === 'Others' && result.value.details) {
                        rejectionReason = 'Others: ' + result.value.details;
                    }
                    
                    form.innerHTML = `
                        <input type="hidden" name="update_request" value="1">
                        <input type="hidden" name="request_id" value="${requestId}">
                        <input type="hidden" name="action" value="${action}">
                        <input type="hidden" name="rejection_reason" value="${rejectionReason}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        } else {
            Swal.fire({
                title: 'Confirm Action',
                text: `Are you sure you want to ${action} this request?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, proceed!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="update_request" value="1">
                        <input type="hidden" name="request_id" value="${requestId}">
                        <input type="hidden" name="action" value="${action}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
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
function markAsPrinted(requestId, checkbox) {
    if (checkbox.checked) {
        Swal.fire({
            title: 'Mark as Printed?',
            text: 'Mark this document as printed? It will be forwarded to captain for signing.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, mark as printed!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="update_request" value="1">
                    <input type="hidden" name="request_id" value="${requestId}">
                    <input type="hidden" name="action" value="for-signing">
                `;
                document.body.appendChild(form);
                form.submit();
            } else {
                // Uncheck the checkbox if user cancels
                checkbox.checked = false;
            }
        });
    }
}

// Sign document as secretary (when captain is not available)
function signDocument(requestId) {
    Swal.fire({
        title: 'Sign Document?',
        text: 'Sign this document as Secretary? This will mark the document as signed.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, sign it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="update_request" value="1">
                <input type="hidden" name="request_id" value="${requestId}">
                <input type="hidden" name="action" value="sign-as-secretary">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Mark document as completed (when resident has received it)
function completeDocument(requestId) {
    Swal.fire({
        title: 'Mark as Completed?',
        text: 'Mark this document as completed? This means the resident has received the document.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, complete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="update_request" value="1">
                <input type="hidden" name="request_id" value="${requestId}">
                <input type="hidden" name="action" value="complete">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

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
                title: 'Error',
                text: 'Error loading preview'
            });
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
    
    // Show/hide action buttons based on document status
    const printBtn = document.getElementById('modalPrintBtn');
    const signBtn = document.getElementById('modalSignBtn');
    const completeBtn = document.getElementById('modalCompleteBtn');
    
    // Reset button visibility
    printBtn.style.display = 'none';
    signBtn.style.display = 'none';
    completeBtn.style.display = 'none';
    
    // Find the specific row for this request to check its actual status
    const rows = document.querySelectorAll('tbody tr');
    let documentStatus = '';
    
    rows.forEach(row => {
        const viewButton = row.querySelector(`button[onclick*="viewDocument(${requestId})"]`);
        if (viewButton) {
            const statusBadge = row.querySelector('.badge');
            if (statusBadge) {
                documentStatus = statusBadge.textContent.trim();
                console.log('Document status for request', requestId, ':', documentStatus);
            }
        }
    });
    
    // Show appropriate button based on actual document status
    if (documentStatus.includes('In Progress')) {
        console.log('Showing Print button - document is In Progress');
        printBtn.style.display = 'inline-block';
    } else if (documentStatus.includes('Signed') && !documentStatus.includes('Completed')) {
        console.log('Showing Complete button - document is Signed');
        completeBtn.style.display = 'inline-block';
    } else if (documentStatus.includes('Completed')) {
        console.log('Hiding all buttons - document is Completed');
        // All buttons stay hidden
    } else if (documentStatus.includes('Waiting for Signature') || documentStatus.includes('Printed')) {
        console.log('Showing Sign button - document is waiting for signature');
        signBtn.style.display = 'inline-block';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('documentPreviewModal'));
    modal.show();
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
            title: 'Mark as Ready?',
            text: 'Mark this document as ready for pickup? This will notify the resident that their document is completed and ready for collection.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, mark as ready!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="update_request" value="1">
                    <input type="hidden" name="request_id" value="${requestId}">
                    <input type="hidden" name="action" value="complete">
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
</script>

<!-- Include jsPDF and html2canvas libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>

<style>
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

<script>
// Ensure dropdowns are clickable
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
