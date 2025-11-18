
<?php 
// Handle POST request BEFORE any output
session_start();
require_once '../../includes/config.php';

// Handle form submission first (before any HTML output)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_GET['ajax_calendar'])) {
    $doc_type_id = $_POST['doc_type_id'];
    $purpose = !empty($_POST['purpose']) ? sanitize($_POST['purpose']) : 'Document request';
    $custom_purpose = !empty($_POST['custom_purpose']) ? sanitize($_POST['custom_purpose']) : '';
    $pickup_schedule_id = !empty($_POST['pickup_schedule_id']) ? $_POST['pickup_schedule_id'] : null;

    // If custom purpose is provided, use it instead of predefined purpose
    if (!empty($custom_purpose)) {
        $purpose = $custom_purpose;
    }

    // Prepare insert statement using new column names
    $stmt = $pdo->prepare("
        INSERT INTO document_requests 
            (resident_id, doc_type_id, schedule_id, request_purpose, request_remarks, pickup_representative, request_status)
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");

    // Execute insert
    $success = $stmt->execute([
        $_SESSION['user_id'], 
        $doc_type_id, 
        $pickup_schedule_id, 
        $purpose,
        $purpose, // using purpose as remarks for now
        $_POST['pickup_representative'] ?? null
    ]);

    if ($success) {
        // keep schedule logic intact (decrement slot count)
        if ($pickup_schedule_id) {
            $pdo->prepare("UPDATE schedule SET schedule_slots = schedule_slots - 1 WHERE schedule_id = ? AND schedule_slots > 0")
                ->execute([$pickup_schedule_id]);
        }
            
        logActivity($_SESSION['user_id'], 'Resident requested document');

        // Use session to store success message and redirect to prevent duplicate submission
        $_SESSION['request_success'] = true;
        header('Location: request_document.php');
        exit;
    } else {
        $_SESSION['request_error'] = true;
    }
}

// AJAX calendar handler
if (isset($_GET['ajax_calendar'])) {
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    $year  = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

    echo build_calendar($month, $year, true);
    exit;
}

// Now include header (after POST handling)
include 'header.php';

    function build_calendar($month, $year) {
        global $pdo;
        $daysOfWeek = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        $firstDayOfMonth = mktime(0, 0, 0, $month, 1, $year);
        $numberDays = date('t', $firstDayOfMonth);
        $dateComponents = getdate($firstDayOfMonth);
        $monthName = $dateComponents['month'];
        $dayOfWeek = $dateComponents['wday'];
        $today = date('Y-m-d');
        //CONTROLS
        $prev_month = date('n', mktime(0, 0, 0, $month - 1, 1, $year));
        $prev_year  = date('Y', mktime(0, 0, 0, $month - 1, 1, $year));
        $next_month = date('n', mktime(0, 0, 0, $month + 1, 1, $year));
        $next_year  = date('Y', mktime(0, 0, 0, $month + 1, 1, $year));
        //STRUCTURES
        $calendar = "
            <center>
                <h2>$monthName $year</h2>
                <a href='#' class='btn btn-sm btn-primary calendar-nav' data-month='$prev_month' data-year='$prev_year'>Prev</a>
                <a href='#' class='btn btn-sm btn-primary calendar-nav' data-month='".date('n')."' data-year='".date('Y')."'>Current</a>
                <a href='#' class='btn btn-sm btn-primary calendar-nav' data-month='$next_month' data-year='$next_year'>Next</a>
            </center><br>
            <table class='table table-bordered text-center'>
            <thead><tr>";

        //DATE IN THE CALENDAR
        foreach ($daysOfWeek as $day) {
            $calendar .= "<th class='bg-primary text-white'>$day</th>";
        }
        $calendar .= "</tr></thead><tbody><tr>";

        if ($dayOfWeek > 0) {
            for ($k = 0; $k < $dayOfWeek; $k++) {
                $calendar .= "<td class='empty'></td>";
            }
        }

        $currentDay = 1;
        while ($currentDay <= $numberDays) {
            if ($dayOfWeek == 7) {
                $dayOfWeek = 0;
                $calendar .= "</tr><tr>";
            }

        $monthPadded = str_pad($month, 2, "0", STR_PAD_LEFT);
        $dayPadded = str_pad($currentDay, 2, "0", STR_PAD_LEFT);
        $date = "$year-$monthPadded-$dayPadded";

        $stmt = $pdo->prepare("SELECT schedule_id, schedule_slots FROM schedule WHERE schedule_date = ? LIMIT 1");
        $stmt->execute([$date]);
        $row = $stmt->fetch();

        $classes = ["selectable-day"];
        $slotsText = "";
        $disabled = false;

        if ($date < $today) {
            $classes[] = "day-past";
            $disabled = true;
        } elseif ($row) {
            $slots = (int)$row['schedule_slots'];
            if ($slots <= 0) {
                $classes[] = "day-full";
                $slotsText = "<small class='text-danger'>Full</small>";
                $disabled = true;
            } else {
                $classes[] = "day-available";
                $slotsText = "<small>{$slots} slots</small>";
            }
        } else {
            $classes[] = "day-noschedule";
            $disabled = true;
        }

        if ($date == $today) {
            $classes[] = "day-today";
        }

        $disabledAttr = $disabled ? "disabled" : "";
        $scheduleId = $row ? $row['schedule_id'] : "";

        $calendar .= "
            <td class='".implode(" ", $classes)." $disabledAttr' 
                data-date='$date' 
                data-id='$scheduleId'>
                <div class='p-2'>
                    <h6 class='mb-0'>$currentDay</h6>
                    $slotsText
                </div>
            </td>
        ";

        $currentDay++;
        $dayOfWeek++;
    }

    if ($dayOfWeek != 7) {
        for ($i = 0; $i < 7 - $dayOfWeek; $i++) {
            $calendar .= "<td class='empty'></td>";
        }
    }

    $calendar .= "</tr></tbody></table>";
    return $calendar;
}
?>


<style>
    .overlay {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0,0,0,0.55);
        backdrop-filter: blur(3px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        transition: opacity 0.25s ease;
    }

    .overlay.show {
        display: flex;
        opacity: 1;
    }
    .overlay-content {
        width: 90%;
        max-width: 800px;
        min-height: 500px;   
        max-height: 600px;  
        border-radius: 1rem;
        overflow: hidden;   
        display: flex;
        flex-direction: column;
        background-color: #fff;
    }
    .overlay-body {
        flex: 1 1 auto;
        padding: 1rem;
    }
    .selectable-row {
        cursor: pointer;
        transition: background-color 0.15s ease;
    }
    .selectable-row:hover {
        background-color: #e8f2ff;
    }

    .selectable-day {
        cursor: pointer;
        transition: background-color 0.2s ease, transform 0.1s ease;
        border-radius: 0.5rem;
    }

    .selectable-day:hover:not(.disabled) {
        transform: scale(1.05);
        box-shadow: 0 0 5px rgba(0,0,0,0.15);
    }


    .day-past {
        background-color: #d6d8db !important;
        color: #555;
        cursor: not-allowed;
    }


    .day-noschedule {
        background-color: #ffffff !important;
        color: #999;
        cursor: not-allowed;
    }


    .day-full {
        background-color: #f5c6cb !important;
        color: #721c24;
        cursor: not-allowed;
    }


    .day-available {
        background-color: #d4edda !important;
        color: #155724;
    }


    .day-today {
        border: 2px solid #007bff !important;
    }
    #overlayCalendarWrapper, 
    #overlayCalendarWrapper * {
        user-select: none;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
    }
    </style>

<div class="container">
    <div class="row">
        <div class="col-md-8">
            <?php
            // Ensure pickup_schedule_id column exists in document_requests to avoid crashes on older databases
            try {
                $pdo->exec("ALTER TABLE document_requests ADD COLUMN IF NOT EXISTS pickup_schedule_id INT NULL AFTER reason");
            } catch (PDOException $e) {
                // Some MySQL versions don't support IF NOT EXISTS in ALTER; fallback: try to add column only if it doesn't exist
                try {
                    $colCheck = $pdo->query("SHOW COLUMNS FROM document_requests LIKE 'pickup_schedule_id'");
                    if ($colCheck->rowCount() == 0) {
                        $pdo->exec("ALTER TABLE document_requests ADD COLUMN pickup_schedule_id INT NULL AFTER reason");
                    }
                } catch (PDOException $e2) {
                    // ignore - we'll handle missing column by allowing NULLs where used
                }
            }

            // Display success message from session if available
            if (isset($_SESSION['request_success']) && $_SESSION['request_success']) {
                echo "<div class='alert alert-success alert-dismissible fade show'>
                        <strong>Document request submitted successfully!</strong><br>
                        <small>Your request has been submitted and is pending approval.</small>
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                    </div>";
                unset($_SESSION['request_success']); // Clear the message after displaying
            }
            
            // Display error message from session if available
            if (isset($_SESSION['request_error']) && $_SESSION['request_error']) {
                echo "<div class='alert alert-danger alert-dismissible fade show'>
                        <strong>Failed to submit request!</strong>
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                    </div>";
                unset($_SESSION['request_error']); // Clear the message after displaying
            }
            ?>
            
            <div class="card">
                <div class="card-header bg-primary text-white py-2">
                    <h6 class="mb-0">📝 Document Request Form</h6>
                </div>
                <div class="card-body py-2">
                    <form method="POST">
                        <div class="mb-2">
                            <label for="doc_type_id" class="form-label mb-1 small">Document Type *</label>
                            <select class="form-select form-select-sm" id="doc_type_id" name="doc_type_id" required onchange="updatePurposeOptions()">
                                <option value="">Select Document Type</option>
                                <?php
                                $stmt = $pdo->query("SELECT * FROM document_types ORDER BY doc_name");
                                while ($type = $stmt->fetch()) {
                                    echo "<option value='{$type['doc_type_id']}' data-doc-name='{$type['doc_name']}'>{$type['doc_name']} - ₱{$type['doc_price']}</option>";
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
                        
                     <div class="mb-2 position-relative">
                                <label class="form-label mb-1 small">Claiming Schedule</label>
                                <div class="d-flex align-items-center gap-2">
                                    <input type="text" class="form-control form-control-sm" id="selected_schedule_display"
                                        name="selected_schedule_display" placeholder="No schedule selected" readonly>
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="openOverlayBtn">
                                        Select Schedule
                                    </button>
                                </div>
                                <input type="hidden" id="pickup_schedule_id" name="pickup_schedule_id">
                                <div class="form-text small">Optionally coordinate with the captain's schedule.</div>
                            </div>

                            <div id="scheduleOverlay" class="overlay">
                            <div class="overlay-content card shadow-lg">
                                <div class="overlay-header d-flex justify-content-between align-items-center bg-primary text-white p-3 rounded-top">
                                <h5 class="mb-0 fw-bold">Select Captain Schedule</h5>
                                <button type="button" class="btn btn-light btn-sm" id="closeOverlayBtn">✕</button>
                                </div>

                                <div class="overlay-body p-3 bg-white rounded-bottom">
                                <p class="text-muted small mb-3">Tap a date below to select your schedule.</p>
                                
                                <div class="container">
                                    <div class="row">
                                    <div  class="col-md-12" id="overlayCalendarWrapper">
                                        <?php
                                        $dateComponents = getdate();
                                        if(isset($_GET['month']) && isset($_GET['year'])){
                                        $month = $_GET['month'];
                                        $year = $_GET['year'];
                                        } else {
                                            $month = $dateComponents['mon'];
                                            $year = $dateComponents['year'];
                                        }

                                        echo build_calendar($month, $year);
                                        ?>
                                    </div>
                                    </div>
                                </div>
                                </div>
                            </div>
                            </div>
                        
                        <!-- Pickup Representative Field -->
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
            <!-- Document Types Info -->
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0">📋 Available Documents</h6>
                </div>
                <div class="card-body">
                    <?php
                    $stmt = $pdo->query("SELECT * FROM document_types ORDER BY doc_price ASC");
                    while ($type = $stmt->fetch()):
                    ?>
                        <div class="mb-3 p-2 border-start border-primary border-3">
                            <strong><?php echo htmlspecialchars($type['doc_name']); ?></strong><br>
                            <span class="text-success">₱<?php echo number_format($type['doc_price'], 2); ?></span><br>
                            <small class="text-muted"><?php echo htmlspecialchars($type['description'] ?? 'Standard barangay document'); ?></small>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <!-- Support Tickets Link -->
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
    // Form validation and enhancement
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
    const overlay = document.getElementById("scheduleOverlay");
    const openBtn = document.getElementById("openOverlayBtn");
    const closeBtn = document.getElementById("closeOverlayBtn");

    // Open overlay
    openBtn.addEventListener("click", () => overlay.classList.add("show"));
    // Close overlay
    closeBtn.addEventListener("click", () => overlay.classList.remove("show"));
    // Click outside to close
    overlay.addEventListener("click", e => {
        if (e.target === overlay) overlay.classList.remove("show");
    });

    // Calendar functions
    function loadOverlayCalendar(month, year) {
        const wrapper = document.getElementById('overlayCalendarWrapper');
        if (!wrapper) return;
        wrapper.innerHTML = '<div class="text-center p-3">Loading...</div>';

    const url = new URL(window.location.href);
    url.searchParams.set('ajax_calendar', 1);
    url.searchParams.set('month', month);
    url.searchParams.set('year', year);

    fetch(url, { credentials: 'same-origin' })
    .then(resp => resp.text())
    .then(html => {
        console.log(html); // check what is returned
        wrapper.innerHTML = html;
        attachOverlayNavHandlers();
        attachOverlayDateHandlers();
    })

        .catch(err => {
            wrapper.innerHTML = '<div class="text-danger p-3">Failed to load calendar.</div>';
            console.error(err);
        });
    }

    function attachOverlayNavHandlers() {
        document.querySelectorAll('#overlayCalendarWrapper .calendar-nav').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const month = parseInt(this.dataset.month);
            const year = parseInt(this.dataset.year);
            loadOverlayCalendar(month, year);
        });
        });
    }

    function attachOverlayDateHandlers() {
    document.querySelectorAll('#overlayCalendarWrapper .selectable-day').forEach(cell => {
        if (cell.classList.contains('disabled')) return; // skip full dates
        cell.addEventListener('click', function() {
        const date = this.dataset.date;
        if (!date) return;
        document.getElementById('selected_schedule_display').value = date;
    const scheduleId = this.dataset.id;
    document.getElementById('pickup_schedule_id').value = scheduleId;
        overlay.classList.remove('show');
        });
    });
    }

    // Initialize calendar handlers
    attachOverlayNavHandlers();
    attachOverlayDateHandlers();

    // Purpose options for different document types
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

    // Purpose update functions
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
        // Not needed anymore with dropdown, but keeping for compatibility
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
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const purposeSelect = document.getElementById('purpose');
            const customPurpose = document.getElementById('custom_purpose').value;
            const docTypeSelect = document.getElementById('doc_type_id');
            
            // Only validate purpose if a document type is selected
            if (docTypeSelect.value && purposeSection.style.display !== 'none') {
                if (!purposeSelect.value) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Purpose Required',
                        text: 'Please select a purpose for your document request.'
                    });
                    return false;
                }
                
                if (purposeSelect.value === 'other' && !customPurpose.trim()) {
                    e.preventDefault();
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