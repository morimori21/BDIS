<?php
require_once '../../includes/config.php';

// --- PHP Timezone Fix: Set the default timezone to Asia/Manila (PHT) ---
date_default_timezone_set('Asia/Manila');

// --- Handle ticket submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ticket'])) {
    $request_id = !empty($_POST['request_id']) ? $_POST['request_id'] : null;
    
    // Check if 'other' subject is selected and use the specified subject instead
    $subject = ($_POST['subject'] === 'other' && !empty($_POST['other_subject'])) 
               ? sanitize($_POST['other_subject']) 
               : sanitize($_POST['subject']);
               
    $description = sanitize($_POST['description']);
    $default_status = 'open';

    $stmt = $pdo->prepare("
        INSERT INTO support_tickets (user_id, request_id, ticket_subject, ticket_description, ticket_status)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$_SESSION['user_id'], $request_id, $subject, $description, $default_status]);
    $ticketId = $pdo->lastInsertId();
    
    $initialMessage = "Subject: $subject\n\n$description";
    $stmt = $pdo->prepare("
        INSERT INTO chat_messages (ticket_id, user_id, message, message_sent_at) 
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$ticketId, $_SESSION['user_id'], $initialMessage]);

    logActivity($_SESSION['user_id'], "Created support ticket #$ticketId");

    $_SESSION['success_message'] = "Support ticket #$ticketId has been submitted successfully!";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- Display success message ---
$success = '';
if (!empty($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// --- Handle ticket response ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['respond_ticket'])) {
    $ticket_id = $_POST['ticket_id'];
    $message = sanitize($_POST['message']);
    
    $stmt = $pdo->prepare("SELECT ticket_id FROM support_tickets WHERE ticket_id = ? AND user_id = ?");
    $stmt->execute([$ticket_id, $_SESSION['user_id']]);
    
    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("
            INSERT INTO chat_messages (ticket_id, user_id, message, message_sent_at) 
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$ticket_id, $_SESSION['user_id'], $message]);
        
        logActivity($_SESSION['user_id'], "Responded to support ticket #$ticket_id");
        $success = "Response added to ticket #$ticket_id";
    }
}

// --- Statistics queries (Kept for potential future use or display) ---
$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'open' AND user_id = ?");
$stmt->execute([$currentUserId]);
$openCount = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'in-progress' AND user_id = ?");
$stmt->execute([$currentUserId]);
$progressCount = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'resolved' AND user_id = ?");
$stmt->execute([$currentUserId]);
$resolvedCount = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'closed' AND user_id = ?");
$stmt->execute([$currentUserId]);
$closedCount = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE user_id = ?");
$stmt->execute([$currentUserId]);
$totalCount = $stmt->fetch()['count'];


// --- FILTER LOGIC: UPDATED FOR PHT CONSISTENCY ---
$filter = $_GET['filter'] ?? 'all'; // Default filter to 'all'
$search = $_GET['search'] ?? '';
$date = $_GET['date'] ?? '';

$wherePieces = ["st.user_id = :uid"];
$params = [':uid' => $currentUserId];

// Status Filter (from tabs or quick filters)
if ($filter !== 'all' && in_array($filter, ['open', 'in-progress', 'resolved', 'closed'])) {
    $wherePieces[] = "st.ticket_status = :status";
    $params[':status'] = $filter;
}

// Search Filter
if ($search) {
    // Search by subject, description, document name, and ticket ID
    $wherePieces[] = "(st.ticket_subject LIKE :search OR st.ticket_description LIKE :search OR dt.doc_name LIKE :search OR st.ticket_id LIKE :search)";
    $params[':search'] = "%$search%";
}

// Date Filter
if ($date) {
    if ($date === 'WEEK') {
        // Use PHP's date function (which is now set to Asia/Manila) to get 7 days ago
        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
        $wherePieces[] = "st.ticket_created_at >= :seven_days_ago";
        $params[':seven_days_ago'] = $sevenDaysAgo;
    } elseif ($date === 'MONTH') {
        // Use PHP's date function to get the start of the current month in PHT
        $startOfMonth = date('Y-m-01 00:00:00');
        $wherePieces[] = "st.ticket_created_at >= :start_of_month";
        $params[':start_of_month'] = $startOfMonth;
    } else {
        // Specific date filter: The client-side JS now correctly calculates the PHT date.
        $wherePieces[] = "DATE(st.ticket_created_at) = :date";
        $params[':date'] = $date;
    }
}

$whereSQL = 'WHERE ' . implode(' AND ', $wherePieces);


include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"> 
    <style>
        .filter-card:hover {
            transform: scale(1.03);
            transition: 0.2s;
            box-shadow: 0 0 10px rgba(0,0,0,0.15);
        }

        .nav-tabs {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            border-bottom: 1px solid #dee2e6;
        }
        .nav-tabs .nav-item {
            margin-bottom: -1px;
        }
        .nav-tabs .nav-link {
            border: 1px solid transparent;
            border-top-left-radius: 0.25rem;
            border-top-right-radius: 0.25rem;
            padding: 0.5rem 1rem;
            white-space: nowrap;
            color: #6c757d;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        .nav-tabs .nav-link:hover {
            color: #495057;
            background-color: #e9ecef;
            border-color: #dee2e6;
        }
        .nav-tabs .nav-link.active {
            color: #495057;
            background-color: #fff;
            border-color: #dee2e6 #dee2e6 #fff;
            font-weight: 600;
        }
        .clickable-filter.active {
            background-color: var(--bs-info);
            color: white;
        }
    </style>
</head>
<body>

<div class="container" style=" margin-bottom: 50px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Support Ticket</h2>
        <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#filterPanel" aria-expanded="<?= ($search || $date || $filter !== 'all') ? 'true' : 'false' ?>" aria-controls="filterPanel">
            <i class="fas fa-filter me-1"></i> Filters
        </button>
    </div>
    <?php if (!empty($success)): ?>
        <div id="successAlert" class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="collapse <?= ($search || $date || $filter !== 'all') ? 'show' : '' ?> mb-3" id="filterPanel">
        <div class="card card-body shadow-sm p-3 border-top">
            <form action="" method="GET" class="row g-3">
                
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">

                <div class="col-12 col-md-6 col-lg-5">
                    <label for="search" class="form-label">Search Keywords</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="search" name="search" placeholder="Subject, Details, Request #..." value="<?= htmlspecialchars($search) ?>">
                        <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search"></i></button>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-5">
                    <label for="datePicker" class="form-label">Filter by Date</label>
                    <div class="input-group">
                        <input type="text" class="form-control flatpickr" id="datePicker" placeholder="Select a specific date..." name="date_input" value="<?= $date && $date !== 'WEEK' && $date !== 'MONTH' ? htmlspecialchars($date) : '' ?>">
                        <button class="btn btn-outline-danger" type="button" onclick="clearDateFilter()"><i class="fas fa-times"></i></button>
                    </div>
                    <small class="form-text text-muted">Use the quick filters below if needed.</small>
                </div>

                <div class="col-12 col-md-12 col-lg-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 me-2"><i class="fas fa-check me-1"></i> Apply</button>
                    <a href="support_tickets.php" class="btn btn-outline-secondary w-100"><i class="fas fa-undo me-1"></i> Reset</a>
                </div>
            </form>
            
            <hr class="mt-4 mb-2">
            
            <div class="d-flex flex-wrap justify-content-start align-items-center pt-2">
                <span class="me-3 mb-2 fw-semibold">Quick Filters:</span>
                <?php
                $quick_filters = [
                    'all' => 'All Statuses',
                    'open' => 'Open ('. $openCount .')',
                    'in-progress' => 'In Progress ('. $progressCount .')',
                    'resolved' => 'Resolved ('. $resolvedCount .')',
                    'closed' => 'Closed ('. $closedCount .')',
                ];
                $date_quick_filters = [
                    'today' => 'Today',
                    'yesterday' => 'Yesterday',
                    'WEEK' => 'Last 7 Days',
                    'MONTH' => 'This Month',
                ];
                ?>
                <?php foreach ($quick_filters as $key => $label): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary me-2 mb-2 clickable-status-filter <?= ($filter === $key) ? 'active' : '' ?>" data-filter="<?= $key ?>">
                        <?= htmlspecialchars($label) ?>
                    </button>
                <?php endforeach; ?>
                
                <span class="me-3 mb-2 fw-semibold ms-md-4">Quick Dates:</span>
                <?php foreach ($date_quick_filters as $key => $label): 
                    // Determine if a date filter is active for styling
                    $isActiveDate = false;
                    if ($date === $key) {
                        $isActiveDate = true;
                    } elseif ($key === 'today' && $date && date('Y-m-d', strtotime($date)) === date('Y-m-d')) {
                        $isActiveDate = true;
                    } elseif ($key === 'yesterday' && $date) {
                        $yesterday = new DateTime();
                        $yesterday->modify('-1 day');
                        if (date('Y-m-d', strtotime($date)) === $yesterday->format('Y-m-d')) {
                            $isActiveDate = true;
                        }
                    }
                ?>
                    <button type="button" class="btn btn-sm btn-outline-info me-2 mb-2 clickable-date-filter <?= $isActiveDate ? 'active' : '' ?>" data-filter="<?= $key ?>">
                        <?= htmlspecialchars($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            
        </div>
    </div>


    <ul class="nav nav-tabs mb-3" id="ticketTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="mytickets-tab" data-bs-toggle="tab" data-bs-target="#myTickets" type="button" role="tab" aria-controls="myTickets" aria-selected="true">
                📋 My Tickets
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="submit-tab" data-bs-toggle="tab" data-bs-target="#submitTicket" type="button" role="tab" aria-controls="submitTicket" aria-selected="false">
                📝 Submit Ticket
            </button>
        </li>
    </ul>

    <div class="tab-content" id="ticketTabsContent">
        <div class="tab-pane fade show active" id="myTickets" role="tabpanel" aria-labelledby="mytickets-tab">
            <div class="row">
                <div class="col-12">
                    <div class="card bg-white"> 
                        <div class="card-header bg-primary text-white">
                            <h5>Tickets</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $sql = "
                                SELECT 
                                    st.*,
                                    dt.doc_name AS document_name,
                                    (SELECT COUNT(*) FROM support_tickets st2 WHERE st2.user_id = st.user_id AND st2.ticket_id <= st.ticket_id) AS ticket_number,
                                    (SELECT COUNT(*) FROM chat_messages cm WHERE cm.ticket_id = st.ticket_id) AS response_count
                                FROM support_tickets st
                                LEFT JOIN document_requests dr ON st.request_id = dr.request_id
                                LEFT JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                                $whereSQL
                                ORDER BY st.ticket_created_at DESC
                            ";
                            $stmt = $pdo->prepare($sql);
                            foreach ($params as $k=>$v) {
                                // use INT for uid, strings otherwise
                                if ($k === ':uid') $stmt->bindValue($k, $v, PDO::PARAM_INT);
                                else $stmt->bindValue($k, $v, PDO::PARAM_STR);
                            }
                            $stmt->execute();
                            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>

                            <?php if (empty($tickets)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-ticket-alt fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No tickets found for the current filters.</p>
                                    <small>Try clearing your search or date filters.</small>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Ticket #</th>
                                                <th>Subject</th>
                                                <th>Status</th>
                                                <th>Responses</th>
                                                <th>Last Updated</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tickets as $ticket): 
                                                $statusColors = ['open'=>'primary','in-progress'=>'info','resolved'=>'success','closed'=>'secondary'];
                                                $statusColor = $statusColors[$ticket['ticket_status']] ?? 'secondary';
                                            ?>
                                                <tr>
                                                    <td><strong>#<?= $ticket['ticket_number'] ?></strong></td>
                                                    <td>
                                                        <?= htmlspecialchars($ticket['ticket_subject']) ?>
                                                        <?php if (!empty($ticket['document_name'])): ?>
                                                            <small class="text-muted d-block">Related: <?= htmlspecialchars($ticket['document_name']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?= $statusColor ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $ticket['ticket_status'])) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= $ticket['response_count'] - 1 ?></td> <td><small><?= isset($ticket['ticket_created_at']) ? date('M j, Y g:i A', strtotime($ticket['ticket_created_at'])) : 'N/A' ?></small></td>
                                                    <td>
                                                        <a href="view_ticket.php?ticket_id=<?= $ticket['ticket_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="bi bi-eye"></i> View
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="submitTicket" role="tabpanel" aria-labelledby="submit-tab">
            <div class="row">
                <div class="col-md-6">
                    <div class="card bg-white">
                        <div class="card-header bg-primary text-white">
                            <h5>📝 Submit New Support Ticket</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label for="subject" class="form-label">Subject *</label>
                                    <select class="form-select" id="subject" name="subject" required>
                                        <option value="" disabled selected hidden>-- Select a subject --</option>
                                        <option value="Change Info Issue">CHANGE INFO ISSUE</option>
                                        <option value="Follow up an Request">FOLLOW UP REQUEST</option>
                                        <option value="other">OTHER</option>
                                    </select>
                                </div>

                                <div class="mb-3" id="other_subject_div" style="display:none;">
                                    <label for="other_subject" class="form-label">Please specify *</label>
                                    <input type="text" class="form-control" id="other_subject" name="other_subject" placeholder="Specify your subject">
                                </div>

                                <div class="mb-3" id="request_div" style="display:none;">
                                    <label for="request_id" class="form-label">Related Document Request</label>
                                    <select class="form-select" id="request_id" name="request_id">
                                        <option value="">-- Select a related request --</option>
                                        <?php
                                        $stmt = $pdo->prepare("
                                            SELECT dr.request_id, dt.doc_name, dr.request_status, dr.date_requested 
                                            FROM document_requests dr
                                            LEFT JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                                            WHERE dr.resident_id = ?
                                            ORDER BY dr.date_requested DESC
                                        ");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        while ($request = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                            $date_req = date('M j, Y', strtotime($request['date_requested']));
                                            $docName = $request['doc_name'] ?? 'Unknown Document';
                                            echo "<option value='{$request['request_id']}'>#{$request['request_id']} - {$docName} ({$request['request_status']}) - $date_req</option>";
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="description" class="form-label">Description *</label>
                                    <textarea class="form-control" id="description" name="description" rows="4" required placeholder="Please provide detailed information about your concern..."></textarea>
                                </div>

                                <button type="submit" name="submit_ticket" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Submit Ticket
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card bg-white">
                        <div class="card-header bg-info text-white">
                            <h5>💡 Common Issues & Solutions</h5>
                        </div>
                        <div class="card-body">
                            <h6>Before submitting a ticket, check these common solutions:</h6>
                            <ul class="list-unstyled">
                                <li class="mb-2"><strong>📋 Document Status Questions:</strong><br>
                                    <small>Check your <a href="request_history.php">Request History</a> for current status</small>
                                </li>
                                <li class="mb-2"><strong>⏰ Processing Time:</strong><br>
                                    <small>Documents typically take 3-5 business days to process</small>
                                </li>
                                <li class="mb-2"><strong>💰 Payment Issues:</strong><br>
                                    <small>Payments are processed during document pickup</small>
                                </li>
                                <li class="mb-2"><strong>📄 Document Requirements:</strong><br>
                                    <small>Each document type has specific requirements listed during request</small>
                                </li>
                            </ul>

                            <div class="alert alert-warning">
                                <small><strong>📞 Emergency Contact:</strong><br>
                                For urgent matters outside office hours, contact the barangay hall directly.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// Helper function to clear the current date filter and reload
function clearDateFilter() {
    const url = new URL(window.location);
    url.searchParams.delete('date');
    url.searchParams.delete('date_input');
    // If we're clearing the date, we should also delete the filter param if it's not set
    if (!url.searchParams.get('search') && !url.searchParams.get('filter')) {
        window.location.href = 'support_tickets.php';
    } else {
        window.location.href = url.toString();
    }
}

document.addEventListener("DOMContentLoaded", function() {
    // Auto-hide success alert after 1.48s
    const alert = document.getElementById('successAlert');
    if (alert) {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 1480);
    }

    // --- Subject dropdown logic ---
    document.getElementById('subject').addEventListener('change', function() {
        const value = this.value;
        const otherDiv = document.getElementById('other_subject_div');
        const requestDiv = document.getElementById('request_div');

        if (value === 'other') {
            otherDiv.style.display = 'block';
            requestDiv.style.display = 'none';
        } else if (value === 'Follow up an Request') {
            requestDiv.style.display = 'block';
            otherDiv.style.display = 'none';
        } else {
            otherDiv.style.display = 'none';
            requestDiv.style.display = 'none';
        }
    });

    // --- Quick Filter Button Clicks: Filter by Status ---
    document.querySelectorAll('.clickable-status-filter').forEach(button => {
        button.addEventListener('click', () => {
            const filter = button.dataset.filter;
            const currentUrl = new URL(window.location);
            
            // Set the status filter
            currentUrl.searchParams.set('filter', filter);
            
            window.location.href = currentUrl.toString();
        });
    });

    // --- Quick Filter Button Clicks: Filter by Date (FIXED for PHT/UTC+8) ---
    document.querySelectorAll('.clickable-date-filter').forEach(button => {
        button.addEventListener('click', () => {
            const filter = button.dataset.filter;
            const currentUrl = new URL(window.location);
            
            // Clear existing date/date_input first
            currentUrl.searchParams.delete('date');
            currentUrl.searchParams.delete('date_input');
            
            let dateParam = filter;

            if (filter === 'today' || filter === 'yesterday') {
                // PHT is UTC+8. We calculate the correct date based on this offset.
                const PHT_OFFSET_HOURS = 8;
                const d = new Date();
                
                // 1. Get the current time in UTC milliseconds
                const utcTimeMs = d.getTime() + (d.getTimezoneOffset() * 60000);
                
                // 2. Add PHT offset (8 hours) to get the time in PHT
                const phtTimeMs = utcTimeMs + (PHT_OFFSET_HOURS * 60 * 60 * 1000);
                
                // 3. Create a new Date object based on PHT time
                const phtDate = new Date(phtTimeMs);

                // 4. Adjust for 'yesterday' filter if needed
                if (filter === 'yesterday') {
                    phtDate.setDate(phtDate.getDate() - 1);
                }

                // 5. Format the PHT date as YYYY-MM-DD
                const year = phtDate.getFullYear();
                // We must use standard getMonth/getDate and pad them, not UTC methods, as the time has been manually offset.
                const month = String(phtDate.getMonth() + 1).padStart(2, '0');
                const day = String(phtDate.getDate()).padStart(2, '0');
                
                dateParam = `${year}-${month}-${day}`;
            }
            
            // WEEK and MONTH pass their filter keys directly for PHP logic to handle
            
            currentUrl.searchParams.set('date', dateParam);
            window.location.href = currentUrl.toString();
        });
    });
    
    // --- Flatpickr Initialization (Logic to prevent infinite loop remains) ---
    const datePickerElement = document.getElementById("datePicker");
    if (typeof flatpickr !== 'undefined' && datePickerElement) {
        flatpickr(datePickerElement, {
            dateFormat: "Y-m-d",
            allowInput: true,
            onChange: function(selectedDates, dateStr, instance) {
                // When date is selected via picker, update the URL and submit the form
                if (dateStr) {
                    const form = datePickerElement.closest('form');
                    const url = new URL(window.location.origin + window.location.pathname);
                    form.querySelectorAll('input, select').forEach(input => {
                        if (input.name && input.value) {
                            url.searchParams.set(input.name, input.value);
                        }
                    });
                    
                    // Manually set the main 'date' parameter
                    url.searchParams.set('date', dateStr);
                    url.searchParams.delete('date_input'); // Clean up the auxiliary input
                    
                    window.location.href = url.toString();
                }
            }
        });
        
        // Set date picker value from URL if present (prevents infinite loop)
        const url = new URL(window.location);
        const dateInput = url.searchParams.get('date');
        if(dateInput && dateInput !== 'WEEK' && dateInput !== 'MONTH') {
            datePickerElement._flatpickr.setDate(dateInput, false); 
        }
    }
});
</script>

<?php include 'footer.php'; ?>