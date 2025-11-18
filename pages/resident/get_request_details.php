<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotResident();

if (isset($_GET['id'])) {
    $request_id = $_GET['id'];
    
    // Only allow residents to view their own requests
    $stmt = $pdo->prepare("
        SELECT dr.*, dt.doc_name as doc_type_name, dt.doc_price
        FROM document_requests dr 
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id 
        WHERE dr.request_id = ? AND dr.resident_id = ?
    ");
    $stmt->execute([$request_id, $_SESSION['user_id']]);
    $request = $stmt->fetch();
    
    if ($request) {
        // Get user information using the correct column names from the database
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
                
                // Get address from address table using address_id
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

        // Get email from email table using correct relationship
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
        $pickupDate = isset($request['pickup_date']) && $request['pickup_date'] ? date('F j, Y', strtotime($request['pickup_date'])) : 'Not set';
        $price = '₱' . number_format($request['doc_price'], 2);
        
        // Get pickup schedule details if available
        $pickupDisplay = $pickupDate;
        if (!empty($request['schedule_id'])) {
            $sstmt = $pdo->prepare("SELECT schedule_date FROM schedule WHERE schedule_id = ?");
            $sstmt->execute([$request['schedule_id']]);
            $sch = $sstmt->fetch();
            if ($sch) {
                $pickupDisplay = date('F j, Y', strtotime($sch['schedule_date']));
            }
        }
        
        // Handle pickup representative
        $pickupInfo = '';
        if (!empty($request['pickup_representative'])) {
            $pickupInfo = htmlspecialchars($request['pickup_representative']);
        }
        
        // Status badge styling
        $statusBadge = '';
        switch(strtolower($request['request_status'] ?? 'pending')) {
            case 'completed':
                $statusBadge = '<span class="badge bg-success">Completed</span>';
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
                                    <div class='col-5'><strong>Pickup Date:</strong></div>
                                    <div class='col-7'>$pickupDisplay</div>
                                </div>";
                                
        // Only show pickup representative if there is one
        if (!empty($request['pickup_representative'])) {
            echo "
                                <div class='row mb-2'>
                                    <div class='col-5'><strong>Pickup Representative:</strong></div>
                                    <div class='col-7'>$pickupInfo</div>
                                </div>";
        }
        
        echo "
                                <div class='row mb-0'>
                                    <div class='col-5'><strong>Status:</strong></div>
                                    <div class='col-7'>$statusBadge</div>
                                </div>
                            </div>
                        </div>
                    </div>";
        
        // Show remarks if available
        if (!empty($request['request_remarks'])) {
            echo "
                    <div class='mt-4'>
                        <h6 class='text-primary mb-3 fw-bold'>
                            <i class='bi bi-chat-text me-2'></i>Additional Remarks
                        </h6>
                        <div class='bg-light p-3 rounded'>
                            <p class='mb-0 text-muted'>" . htmlspecialchars($request['request_remarks']) . "</p>
                        </div>
                    </div>";
        }
        
        echo "
                </div>
            </div>
        </div>";
        
        
    } else {
        echo "<div class='alert alert-danger'><i class='bi bi-exclamation-triangle me-2'></i>Request not found or you don't have permission to view this request.</div>";
    }
} else {
    echo "<div class='alert alert-danger'><i class='bi bi-exclamation-triangle me-2'></i>Invalid request ID.</div>";
}
?>