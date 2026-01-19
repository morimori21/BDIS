<?php
function formatNumberShort($number) {
    if ($number >= 1000000000) {
        return round($number / 1000000000, 1) . 'B';
    } elseif ($number >= 1000000) {
        return round($number / 1000000, 1) . 'M';
    } elseif ($number >= 1000) {
        return round($number / 1000, 1) . 'k';
    } else {
        return $number;
    }
}
?>

<?php 
session_start();
require_once '../../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_GET['ajax_calendar'])) {
    $doc_type_id = $_POST['doc_type_id'];
    $purpose = !empty($_POST['purpose']) ? $_POST['purpose'] : 'Document request';
    $custom_purpose = !empty($_POST['custom_purpose']) ? $_POST['custom_purpose'] : '';
    $pickup_schedule_id = null;

    if (!empty($custom_purpose)) {
        $purpose = $custom_purpose;
    }

    $stmt = $pdo->prepare("
        INSERT INTO document_requests 
            (resident_id, doc_type_id, request_purpose, request_remarks, pickup_representative, request_status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");

    $success = $stmt->execute([
        $_SESSION['user_id'], 
        $doc_type_id, 
        $purpose,
        $purpose, // using purpose as remarks for now
        $_POST['pickup_representative'] ?? null
    ]);

    if ($success) {

    logActivity($_SESSION['user_id'], 'Resident requested document');

    $resident_stmt = $pdo->prepare("
        SELECT u.first_name, u.surname 
        FROM users u 
        WHERE u.user_id = ?
    ");
    $resident_stmt->execute([$_SESSION['user_id']]);
    $resident = $resident_stmt->fetch();

    $doc_stmt = $pdo->prepare("SELECT doc_name FROM document_types WHERE doc_type_id = ?");
    $doc_stmt->execute([$doc_type_id]);
    $document = $doc_stmt->fetch();

    $secretaries_stmt = $pdo->prepare("
        SELECT ur.user_id 
        FROM user_roles ur 
        WHERE ur.role = 'secretary'
    ");
    $secretaries_stmt->execute();
    $secretaries = $secretaries_stmt->fetchAll();

    $notif_topic = $resident['first_name'] . " " . $resident['surname'] . " requested a " . $document['doc_name'];
    
    foreach ($secretaries as $secretary) {
        createNotification(
            $secretary['user_id'], 
            'document', 
            $notif_topic, 
            $doc_type_id 
        );
    }

    // Use session to store success message
    $_SESSION['request_success'] = true;
    header('Location: request_document.php');
    exit;
} else {
        $_SESSION['request_error'] = true;
    }
}

if (isset($_GET['ajax_calendar'])) {
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    $year  = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

    echo build_calendar($month, $year, true);
    exit;
}

include 'header.php';

function build_calendar($month, $year) {
    return "<div class='alert alert-info mb-2'>Scheduling has been removed. Submit requests without selecting a date.</div>";
}
?>

<div class="container">
    <div class="row">
        <div class="col-md-8">
            <?php
            try {
                $pdo->exec("ALTER TABLE document_requests ADD COLUMN IF NOT EXISTS pickup_schedule_id INT NULL AFTER reason");
            } catch (PDOException $e) {

                try {
                    $colCheck = $pdo->query("SHOW COLUMNS FROM document_requests LIKE 'pickup_schedule_id'");
                    if ($colCheck->rowCount() == 0) {
                        $pdo->exec("ALTER TABLE document_requests ADD COLUMN pickup_schedule_id INT NULL AFTER reason");
                    }
                } catch (PDOException $e2) {
                   
                }
            }


            if (isset($_SESSION['request_success']) && $_SESSION['request_success']) {
                echo "<div id='request-success-alert' class='alert alert-success alert-dismissible fade show'>
                        <strong>Document request submitted successfully!</strong><br>
                        <small>Your request has been submitted and is pending approval.</small>
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                    </div>
                    <script>
                        // Auto-hide after 1 second
                        setTimeout(function(){
                            var el = document.getElementById('request-success-alert');
                            if (!el) return;
                            try {
                                if (window.bootstrap && bootstrap.Alert) {
                                    var alert = bootstrap.Alert.getOrCreateInstance(el);
                                    alert.close();
                                } else {
                                    // Fallback: remove element
                                    el.classList.remove('show');
                                    el.parentNode && el.parentNode.removeChild(el);
                                }
                            } catch (e) {
                                el.classList.remove('show');
                                el.parentNode && el.parentNode.removeChild(el);
                            }
                        }, 1000);
                    </script>";
                unset($_SESSION['request_success']); 
            }
            
            // Display error message from session if available
            if (isset($_SESSION['request_error']) && $_SESSION['request_error']) {
                echo "<div class='alert alert-danger alert-dismissible fade show'>
                        <strong>Failed to submit request!</strong>
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                    </div>";
                unset($_SESSION['request_error']); 
            }
            ?>
            
            <div class="card">
                <div class="card-header bg-primary text-white py-2">
                    <h6 class="mb-0">📝 Document Request Form</h6>
                </div>
                <div class="card-body py-2">
                    <form method="POST" id="documentRequestForm">
                        <div class="mb-2">
                            <label for="doc_type_id" class="form-label mb-1 small">Document Type *</label>
                            <select class="form-select form-select-sm" id="doc_type_id" name="doc_type_id" required onchange="updatePurposeOptions()">
                                <option value="">Select Document Type</option>
                                <?php
                                $stmt = $pdo->query("
    SELECT dt.*
    FROM document_types dt
    INNER JOIN (
        SELECT doc_name, MAX(doc_type_id) AS max_id
        FROM document_types
        GROUP BY doc_name
    ) latest ON dt.doc_type_id = latest.max_id
    ORDER BY dt.doc_name ASC
");
                                while ($type = $stmt->fetch()) {
                                    echo "<option value='{$type['doc_type_id']}' data-doc-name='{$type['doc_name']}'>
                                        {$type['doc_name']} - ₱" . formatNumberShort($type['doc_price']) . "
                                    </option>";
                                }
                                ?>
                            </select>
                            <div class="form-text small">Choose the type of document you need to request.</div>
                        </div>

                        <!-- Purpose Section -->
                        <div class="mb-2" id="purpose-section" style="display: none;">
                            <label class="form-label mb-1 small">Document Purpose *</label>
                            <select class="form-select form-select-sm" id="purpose" name="purpose" required onchange="toggleCustomPurpose()">
                                <option value="">Select Purpose</option>
                            </select>
                            <div class="mt-2" id="custom-purpose-section" style="display: none;">
                                <input type="text" class="form-control form-control-sm" id="custom_purpose" name="custom_purpose" placeholder="Please specify your purpose...">
                            </div>
                            <div class="form-text small">Select the purpose for your document request.</div>
                        </div>
                        
                        <div class="mb-2">
                            <label for="pickup_representative" class="form-label mb-1 small">Pickup Representative (Optional)</label>
                            <input type="text" class="form-control form-control-sm" id="pickup_representative" name="pickup_representative" 
                                   placeholder="Enter name of person who will pickup the document">
                            <div class="form-text small">If someone else will pickup the document on your behalf, enter their name here.</div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                            <button type="submit" class="btn btn-primary btn-sm">
                                📤 Submit Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0">📋 Available Documents</h6>
                </div>
                <div class="card-body">
                    <?php
                    $stmt = $pdo->query("
    SELECT dt.*
    FROM document_types dt
    INNER JOIN (
        SELECT doc_name, MAX(doc_type_id) AS max_id
        FROM document_types
        GROUP BY doc_name
    ) latest ON dt.doc_type_id = latest.max_id
    ORDER BY dt.doc_name ASC
");

                    while ($type = $stmt->fetch()):
                    ?>
                        <div class="mb-3 p-2 border-start border-primary border-3">
                            <strong><?php echo htmlspecialchars($type['doc_name']); ?></strong><br>
                            <span class="text-success">₱<?php echo formatNumberShort($type['doc_price']); ?></span><br>
                            <small class="text-muted"><?php echo htmlspecialchars($type['description'] ?? 'Standard barangay document'); ?></small>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <div class="card mt-3">
                <div class="card-header bg-warning text-dark">
                    <h6 class="mb-0">🎫 Need Help?</h6>
                </div>
                <div class="card-body">
                    <p class="mb-2">Having issues with your document request?</p>
                    <a href="support_tickets.php" class="btn btn-warning btn-sm w-100">
                        🎫 Submit Support Ticket
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
    document.getElementById('doc_type_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            const price = selectedOption.text.match(/₱([\d,]+\.?\d*)/);
            if (price) {
                console.log('Selected document price:', price[1]);
            }
        }
    });

    document.addEventListener("DOMContentLoaded", () => {
    const purposeOptions = {
        'Barangay Clearance': [
            'Employment',
            'Business permit or business registration',
            'Police clearance application',
            'NBI clearance requirement',
            'Passport application',
            'Driver\'s license application',
            'School or scholarship requirement',
            'Government employment or contractual work',
            'Private job application',
            'Loan or financial assistance application',
            'Voter registration or reactivation',
            'Legal or court transactions',
            'Proof of good moral character',
            'Proof of residency',
            'Travel or local employment requirement',
            'Barangay ID or local documentation',
            'Cooperative or organization membership',
            'Utility service application (electricity, water, internet)',
            'Housing or property application',
            'Renewal of permits or licenses',
            'Personal record verification'
        ],
        'Certificate of Indigency': [
            'Employment',
            'School enrollment',
            'Scholarship application',
            'Government aid or financial assistance',
            'Medical assistance',
            'Proof of residence for legal documents',
            'Application for police clearance',
            'Application for postal ID',
            'Application for passport',
            'Loan application',
            'Utility service connection (electricity, water, internet, etc.)',
            'Voter registration or reactivation',
            'Business permit or business registration',
            'Travel or transportation requirement',
            'Adoption or guardianship process',
            'Property or housing application',
            'Membership in cooperatives or organizations',
            'Burial or funeral assistance',
            'Bank account opening',
            'Job order or contractual work in government offices'
        ],
        'Certificate of Residency': [
            'Employment verification',
            'Government requirements',
            'Legal proceedings',
            'Business registration'
        ],
        'Barangay ID': [
            'Personal identification',
            'Government transactions',
            'School requirements'
        ]
    };

    window.updatePurposeOptions = function() {
        const docTypeSelect = document.getElementById('doc_type_id');
        const purposeSection = document.getElementById('purpose-section');
        const purposeSelect = document.getElementById('purpose');
        
        if (docTypeSelect.value === '') {
            purposeSection.style.display = 'none';
            return;
        }

        const selectedOption = docTypeSelect.options[docTypeSelect.selectedIndex];
        const docName = selectedOption.getAttribute('data-doc-name');
        
        purposeSection.style.display = 'block';
        purposeSelect.innerHTML = '<option value="">Select Purpose</option>';

        if (purposeOptions[docName]) {
            purposeOptions[docName].forEach(purpose => {
                const option = document.createElement('option');
                option.value = purpose;
                option.textContent = purpose;
                purposeSelect.appendChild(option);
            });
        } else {
            // Default option for unknown document types
            const option = document.createElement('option');
            option.value = 'General purpose';
            option.textContent = 'General purpose';
            purposeSelect.appendChild(option);
        }
        
        // Add "Other" option at the end
        const otherOption = document.createElement('option');
        otherOption.value = 'other';
        otherOption.textContent = 'Other (please specify)';
        purposeSelect.appendChild(otherOption);
    };

    window.updatePurposeValue = function() {
    };

    window.toggleCustomPurpose = function() {
        const purposeSelect = document.getElementById('purpose');
        const customSection = document.getElementById('custom-purpose-section');
        const customInput = document.getElementById('custom_purpose');
        
        if (purposeSelect.value === 'other') {
            customSection.style.display = 'block';
            customInput.required = true;
        } else {
            customSection.style.display = 'none';
            customInput.required = false;
            customInput.value = '';
        }
    };

    // Add form validation to ensure a purpose is selected
    const form = document.getElementById('documentRequestForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const purposeSelect = document.getElementById('purpose');
            const customPurpose = document.getElementById('custom_purpose') ? document.getElementById('custom_purpose').value : '';
            const docTypeSelect = document.getElementById('doc_type_id');
            const purposeSection = document.getElementById('purpose-section');
            
            // Only validate purpose if a document type is selected
            if (docTypeSelect.value && purposeSection && purposeSection.style.display !== 'none') {
                if (!purposeSelect.value) {
                    e.preventDefault();
                    e.stopPropagation();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Purpose Required',
                        text: 'Please select a purpose for your document request.'
                    });
                    return false;
                }
                
                if (purposeSelect.value === 'other' && !customPurpose.trim()) {
                    e.preventDefault();
                    e.stopPropagation();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Custom Purpose Required',
                        text: 'Please specify your custom purpose.'
                    });
                    document.getElementById('custom_purpose').focus();
                    return false;
                }
            }
        });
    }

    });
    </script>

    <?php
    if (!isset($_GET['ajax_calendar'])) {
        include 'footer.php';
    }
?>