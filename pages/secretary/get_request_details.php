<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('secretary');

if (isset($_GET['id'])) {
    $request_id = $_GET['id'];
    
    $stmt = $pdo->prepare("
        SELECT dr.*, 
               dt.doc_name AS doc_type_name, dt.doc_price, dt.doc_status AS doc_type_status,
               u.first_name, u.middle_name, u.surname, u.suffix,
               u.street, u.contact_number, u.birthdate, u.sex, e.email as email,
               ur.role
        FROM document_requests dr
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
        LEFT JOIN users u ON dr.resident_id = u.user_id
        LEFT JOIN account a ON u.user_id = a.user_id
        LEFT JOIN email e ON a.email_id = e.email_id
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        WHERE dr.request_id = ?
    ");
    
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();


    
    if ($request) {
        // Handle both residents and non-residents
        if ($request['first_name'] && $request['surname']) {
            $fullName = htmlspecialchars($request['first_name'] . ' ' . ($request['middle_name'] ? $request['middle_name'] . ' ' : '') . $request['surname']);
        } else {
            $fullName = htmlspecialchars($request['email'] ?? 'Unknown User');
        }
        
        $docType = htmlspecialchars($request['doc_type_name']);
        $reason = htmlspecialchars($request['request_purpose'] ?? 'N/A');
        $address = htmlspecialchars($request['street'] ?? 'N/A');
        $contact_number = htmlspecialchars($request['contact_number'] ?? 'N/A');
        $email = htmlspecialchars($request['email'] ?? 'N/A');
        $gender = htmlspecialchars($request['sex'] ?? 'N/A');
        $birthdate = $request['birthdate'] ? date('F j, Y', strtotime($request['birthdate'])) : 'N/A';
        $age = $request['birthdate'] ? date_diff(date_create($request['birthdate']), date_create('today'))->y : 'N/A';
        $requestDate = $request['date_requested'] ? date('F j, Y g:i A', strtotime($request['date_requested'])) : 'N/A';
        
        // Handle pickup date/representative
        $pickupInfo = 'N/A';
        if (!empty($request['pickup_representative'])) {
            $pickupInfo = htmlspecialchars($request['pickup_representative']);
        }
        
        $price = '₱' . number_format($request['doc_price'] ?? 0, 2);
        
        // Status badge styling
        $statusBadge = '';
        switch(strtolower($request['request_status'] ?? 'pending')) {
            case 'completed':
                $statusBadge = '<span class="badge bg-success">Completed</span>';
                break;
            case 'signed':
                $statusBadge = '<span class="badge bg-info">Signed</span>';
                break;
            case 'printed':
                $statusBadge = '<span class="badge bg-primary">Printed</span>';
                break;
            case 'ready':
                $statusBadge = '<span class="badge bg-success">Ready</span>';
                break;
            case 'in-progress':
                $statusBadge = '<span class="badge bg-warning text-dark">In Progress</span>';
                break;
            case 'for-signing':
                $statusBadge = '<span class="badge bg-info">For Signing</span>';
                break;
            case 'rejected':
                $statusBadge = '<span class="badge bg-danger">Rejected</span>';
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
        echo "<div class='alert alert-danger'><i class='bi bi-exclamation-triangle me-2'></i>Request not found.</div>";
    }
} else {
    echo "<div class='alert alert-danger'><i class='bi bi-exclamation-triangle me-2'></i>Invalid request ID.</div>";
}
?>