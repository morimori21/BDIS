<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotResident();

if (isset($_GET['id'])) {
    $request_id = $_GET['id'];
    $stmt = $pdo->prepare("
        SELECT dr.*, dt.doc_name as doc_type_name, dt.doc_price
        FROM document_requests dr 
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id 
        WHERE dr.request_id = ? AND dr.resident_id = ?
    ");
    $stmt->execute([$request_id, $_SESSION['user_id']]);
    $request = $stmt->fetch();
    
    // Always return JSON
    header('Content-Type: application/json');
    
    if ($request) {
        try {
            $userStmt = $pdo->prepare("SELECT first_name, middle_name, surname, contact_number, birthdate, sex, street, address_id FROM users WHERE user_id = ?");
            $userStmt->execute([$_SESSION['user_id']]);
            $user = $userStmt->fetch();
            
            if ($user) {
                $fullName = htmlspecialchars(trim($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['surname']));
                $contact_number = $user['contact_number'] ? htmlspecialchars($user['contact_number']) : 'Not provided';
                $gender = $user['sex'] ? htmlspecialchars($user['sex']) : 'Not specified';
                $birthdate = $user['birthdate'] ? date('F j, Y', strtotime($user['birthdate'])) : 'Not provided';
                $age = $user['birthdate'] ? date_diff(date_create($user['birthdate']), date_create('today'))->y : 'Not available';
                
                if ($user['address_id']) {
                    try {
                        $addressStmt = $pdo->prepare("SELECT brgy_name, municipality, province FROM address_config WHERE address_id = ?");
                        $addressStmt->execute([$user['address_id']]);
                        $addressData = $addressStmt->fetch();
                        if ($addressData) {
                            $address = htmlspecialchars($user['street'] . ', ' . $addressData['brgy_name'] . ', ' . $addressData['municipality'] . ', ' . $addressData['province']);
                        } else {
                            $address = $user['street'] ? htmlspecialchars($user['street']) : 'Not provided';
                        }
                    } catch (PDOException $e) {
                        $address = $user['street'] ? htmlspecialchars($user['street']) : 'Not provided';
                    }
                } else {
                    $address = $user['street'] ? htmlspecialchars($user['street']) : 'Not provided';
                }
            } else {
                $fullName = 'Unknown User';
                $contact_number = 'Not provided';
                $address = 'Not provided';
                $gender = 'Not specified';
                $birthdate = 'Not provided';
                $age = 'Not available';
            }
            
        } catch (PDOException $e) {
            $fullName = 'Error loading user data';
            $contact_number = 'Error';
            $address = 'Error';
            $gender = 'Error';
            $birthdate = 'Error';
            $age = 'Error';
        }

        try {
            $emailStmt = $pdo->prepare("
                SELECT e.email 
                FROM email e 
                JOIN account a ON e.email_id = a.email_id 
                WHERE a.user_id = ?
            ");
            $emailStmt->execute([$_SESSION['user_id']]);
            $emailResult = $emailStmt->fetch();
            $email = $emailResult ? htmlspecialchars($emailResult['email']) : 'Not provided';
        } catch (PDOException $e) {
            $email = 'Not available';
        }
        
        $docType = htmlspecialchars($request['doc_type_name']);
        $reason = htmlspecialchars($request['request_purpose'] ?? '');
        $requestDate = date('F j, Y g:i A', strtotime($request['date_requested']));
        $price = '₱' . formatNumberShort($request['doc_price'], 2);
        $pickupDisplay = !empty($request['pickup_representative']) ? htmlspecialchars($request['pickup_representative']) : 'Not set';
        
        $pickupInfo = '';
        if (!empty($request['pickup_representative'])) {
            $pickupInfo = htmlspecialchars($request['pickup_representative']);
        }
        
        $statusBadge = '';
        switch(strtolower($request['request_status'] ?? 'pending')) {
            case 'completed':
                $statusBadge = '<span class="badge bg-success">Completed</span>';
                break;
            case 'cancelled':
            case 'canceled':
                $statusBadge = '<span class="badge bg-secondary">Cancelled</span>';
                break;
            case 'ready':
                $statusBadge = '<span class="badge bg-success">Ready</span>';
                break;
            case 'signed':
                $statusBadge = '<span class="badge bg-info">Signed</span>';
                break;
            case 'in-progress':
                $statusBadge = '<span class="badge bg-warning text-dark">In Progress</span>';
                break;
            case 'rejected':
                $statusBadge = '<span class="badge bg-danger">Rejected</span>';
                break;
            case 'for-signing':
                $statusBadge = '<span class="badge bg-info">For Signing</span>';
                break;
            case 'approved':
                $statusBadge = '<span class="badge bg-success">Approved</span>';
                break;
            case 'printed':
                $statusBadge = '<span class="badge bg-primary">Printed</span>';
                break;
            case 'pending':
            default:
                $statusBadge = '<span class="badge bg-warning text-dark">Pending</span>';
                break;
        }
        
        // Build HTML content into a buffer to return via JSON
        ob_start();
        echo "
        <div class='container-fluid p-0'>
            <div class='card border-0 shadow-sm'>
                <div class='card-header bg-primary text-white py-3'>
                    <h5 class='mb-0'><i class='bi bi-file-text me-2'></i>Request #" . $request['request_id'] . " Details</h5>
                </div>
                <div class='card-body p-4'>
                    <div class='row g-4'>
                        <!-- Personal Information Column -->
                        <div class='col-md-6'>
                            <h6 class='text-primary mb-3 fw-bold'>
                                <i class='bi bi-person-circle me-2'></i>Personal Information
                            </h6>
                            <div class='bg-light p-3 rounded'>
                                <div class='row mb-2'>
                                    <div class='col-4'><strong>Full Name:</strong></div>
                                    <div class='col-8'>$fullName</div>
                                </div>
                                <div class='row mb-2'>
                                    <div class='col-4'><strong>Age:</strong></div>
                                    <div class='col-8'>$age years old</div>
                                </div>
                                <div class='row mb-2'>
                                    <div class='col-4'><strong>Gender:</strong></div>
                                    <div class='col-8'>" . ucfirst($gender) . "</div>
                                </div>
                                <div class='row mb-2'>
                                    <div class='col-4'><strong>Birthdate:</strong></div>
                                    <div class='col-8'>$birthdate</div>
                                </div>
                                <div class='row mb-2'>
                                    <div class='col-4'><strong>Address:</strong></div>
                                    <div class='col-8'>$address</div>
                                </div>
                                <div class='row mb-2'>
                                    <div class='col-4'><strong>Contact Number:</strong></div>
                                    <div class='col-8'>$contact_number</div>
                                </div>
                                <div class='row mb-0'>
                                    <div class='col-4'><strong>Email:</strong></div>
                                    <div class='col-8'>$email</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Request Information Column -->
                        <div class='col-md-6'>
                            <h6 class='text-primary mb-3 fw-bold'>
                                <i class='bi bi-file-earmark-text me-2'></i>Request Information
                            </h6>
                            <div class='bg-light p-3 rounded'>
                                <div class='row mb-2'>
                                    <div class='col-5'><strong>Document Type:</strong></div>
                                    <div class='col-7'>$docType</div>
                                </div>
                                <div class='row mb-2'>
                                    <div class='col-5'><strong>Price:</strong></div>
                                    <div class='col-7'>$price</div>
                                </div>
                                <div class='row mb-2'>
                                    <div class='col-5'><strong>Reason:</strong></div>
                                    <div class='col-7'>$reason</div>
                                </div>
                                <div class='row mb-2'>
                                    <div class='col-5'><strong>Request Date:</strong></div>
                                    <div class='col-7'>$requestDate</div>
                                </div>
                                <div class='row mb-2'>
                                    <div class='col-5'><strong>Pickup Representative:</strong></div>
                                    <div class='col-7'>$pickupDisplay</div>
                                </div>";
        
        echo "
                                <div class='row mb-0'>
                                    <div class='col-5'><strong>Status:</strong></div>
                                    <div class='col-7'>$statusBadge</div>
                                </div>
                            </div>
                        </div>
                    </div>";
        
        // Determine remarks based on status
        $statusKey = strtolower($request['request_status'] ?? 'pending');
        $remarks = '';
        $remarksRaw = trim((string)($request['request_remarks'] ?? ''));
        
        switch ($statusKey) {
            case 'pending':
                $remarks = 'Your Have Requested A Document, awaiting for approval';
                break;
            case 'in-progress':
                $remarks = 'Your Requested Document has been approved and waiting to be printed';
                break;
            case 'printed':
            case 'for-signing':
                $remarks = 'Your Document is printed, waiting for Barangay Captain to sign it.';
                break;
            case 'signed':
                $remarks = 'Your document is signed and ready for pickup';
                break;
            case 'completed':
                $remarks = 'The Document is already Received';
                break;
            case 'rejected':
                // Show the rejection reason from remarks
                if ($remarksRaw !== '') {
                    // Avoid double prefix if already saved with label
                    if (stripos($remarksRaw, 'Reason of Rejection') === 0) {
                        $remarks = $remarksRaw;
                    } else {
                        $remarks = 'Reason of Rejection: ' . $remarksRaw;
                    }
                } else {
                    $remarks = 'Reason of Rejection: Not specified';
                }
                break;
            case 'cancelled':
            case 'canceled':
                // Show the cancellation reason from remarks
                if ($remarksRaw !== '') {
                    // Check if it already has "Cancellation Reason:" prefix
                    if (stripos($remarksRaw, 'Cancellation Reason:') !== false) {
                        // Extract everything from "Cancellation Reason:" onwards
                        preg_match('/Cancellation Reason:\s*(.+)/i', $remarksRaw, $matches);
                        $remarks = 'Cancellation Reason: ' . (isset($matches[1]) ? trim($matches[1]) : 'Not specified');
                    } else if (stripos($remarksRaw, 'Reason of Cancelation') === 0 || stripos($remarksRaw, 'Reason of Cancellation') === 0) {
                        // Convert old format to new format
                        $remarks = preg_replace('/^Reason of Cancell?ation:\s*/i', 'Cancellation Reason: ', $remarksRaw);
                    } else {
                        $remarks = 'Cancellation Reason: ' . $remarksRaw;
                    }
                } else {
                    $remarks = 'Cancellation Reason: Not specified';
                }
                break;
            default:
                $remarks = $remarksRaw ? $remarksRaw : 'No additional remarks';
                break;
        }
        
        // Always show remarks section
        echo "
                    <div class='mt-4'>
                        <h6 class='text-primary mb-3 fw-bold'>
                            <i class='bi bi-chat-text me-2'></i>Additional Remarks
                        </h6>
                        <div class='bg-light p-3 rounded'>
                            <p class='mb-0 text-muted'>" . htmlspecialchars($remarks) . "</p>
                        </div>
                    </div>";
        
        echo "
                </div>
            </div>
        </div>";
        // Capture and return JSON
        $html_content = ob_get_clean();
        echo json_encode([
            'html' => $html_content,
            'status' => $request['request_status'] ?? 'pending',
            'request_id' => $request_id
        ]);
        return;
    } else {
        // Not found or unauthorized - return JSON with message HTML
        $html_content = "<div class='alert alert-danger'><i class='bi bi-exclamation-triangle me-2'></i>Request not found or you don't have permission to view this request.</div>";
        echo json_encode([
            'html' => $html_content,
            'status' => 'not-found',
            'request_id' => $request_id
        ]);
        return;
    }
} else {
    header('Content-Type: application/json');
    $html_content = "<div class='alert alert-danger'><i class='bi bi-exclamation-triangle me-2'></i>Invalid request ID.</div>";
    echo json_encode([
        'html' => $html_content,
        'status' => 'invalid',
        'request_id' => null
    ]);
    return;
}
?>