<?php
require_once '../../includes/config.php';


// --- Handle ticket submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ticket'])) {
    $request_id = !empty($_POST['request_id']) ? $_POST['request_id'] : null;
    
    $subject = sanitize($_POST['subject']);
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

// --- Statistics queries ---
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'open' AND user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$openCount = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'in-progress' AND user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$progressCount = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'resolved' AND user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$resolvedCount = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'closed' AND user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$closedCount = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$totalCount = $stmt->fetch()['count'];

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Bootstrap CSS & JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        /* Hover effect for dashboard cards */
        .filter-card:hover {
            transform: scale(1.03);
            transition: 0.2s;
            box-shadow: 0 0 10px rgba(0,0,0,0.15);
        }

        /* Tabs styling */
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
    </style>
</head>
<body>

<div class="container" style=" margin-bottom: 50px;">
    <!-- Dashboard Statistics -->
    <div class="row mb-4">
        <!-- Open -->
        <div class="col-md-2">
            <div class="card border-primary filter-card" data-status="open" style="cursor:pointer;">
                <div class="card-body text-center">
                    <h6 class="text-primary">🆕 Open</h6>
                    <h4 class="text-primary"><?= $openCount ?></h4>
                </div>
            </div>
        </div>

        <!-- In Progress -->
        <div class="col-md-2">
            <div class="card border-info filter-card" data-status="in_progress" style="cursor:pointer;">
                <div class="card-body text-center">
                    <h6 class="text-info">🔄 In Progress</h6>
                    <h4 class="text-info"><?= $progressCount ?></h4>
                </div>
            </div>
        </div>

        <!-- Resolved -->
        <div class="col-md-2">
            <div class="card border-success filter-card" data-status="resolved" style="cursor:pointer;">
                <div class="card-body text-center">
                    <h6 class="text-success">✅ Resolved</h6>
                    <h4 class="text-success"><?= $resolvedCount ?></h4>
                </div>
            </div>
        </div>

        <!-- Closed -->
        <div class="col-md-2">
            <div class="card border-secondary filter-card" data-status="closed" style="cursor:pointer;">
                <div class="card-body text-center">
                    <h6 class="text-secondary">🗂️ Closed</h6>
                    <h4 class="text-secondary"><?= $closedCount ?></h4>
                </div>
            </div>
        </div>

        <!-- Total Tickets -->
        <div class="col-md-4">
            <div class="card border-dark filter-card" data-status="all" style="cursor:pointer;">
                <div class="card-body text-center">
                    <h5 class="text-dark">📊 Total Tickets</h5>
                    <h3 class="text-dark"><?= $totalCount ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Message -->
    <?php if (!empty($success)): ?>
        <div id="successAlert" class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Tabs Navigation -->
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
        <!-- TAB 1: My Tickets -->
        <div class="tab-pane fade show active" id="myTickets" role="tabpanel" aria-labelledby="mytickets-tab">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                            <h5>Tickets</h5>
                        </div>
                        <div class="card-body">
                            <!-- Ticket Table -->
                            <?php
                            $filter = $_GET['filter'] ?? '';
                            $sql = "
                                SELECT 
                                    st.*,
                                    dt.doc_name AS document_name,
                                    ROW_NUMBER() OVER (PARTITION BY st.user_id ORDER BY st.ticket_id ASC) AS ticket_number,
                                    (SELECT COUNT(*) FROM chat_messages cm WHERE cm.ticket_id = st.ticket_id) AS response_count
                                FROM support_tickets st
                                LEFT JOIN document_requests dr ON st.request_id = dr.request_id
                                LEFT JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                                WHERE st.user_id = ?
                            ";
                            if ($filter === 'pending') $sql .= " AND st.ticket_status = 'open'";
                            elseif ($filter === 'in-progress') $sql .= " AND st.ticket_status = 'in-progress'";
                            elseif ($filter === 'resolved') $sql .= " AND st.ticket_status = 'resolved'";
                            elseif ($filter === 'closed') $sql .= " AND st.ticket_status = 'closed'";
                            $sql .= " ORDER BY st.ticket_id ASC";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$_SESSION['user_id']]);
                            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>

                            <?php if (empty($tickets)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-ticket-alt fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No tickets found for this filter.</p>
                                    <small>Try changing the filter above.</small>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Ticket #</th>
                                                <th>Related Document</th>
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
                                                        <?= !empty($ticket['document_name']) ? htmlspecialchars($ticket['document_name']) : '<em class="text-muted">General Inquiry</em>' ?>
                                                        <?php if ($ticket['response_count'] > 0): ?>
                                                            <span class="badge bg-info ms-1"><?= $ticket['response_count'] ?> replies</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?= $statusColor ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $ticket['ticket_status'])) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= $ticket['response_count'] ?></td>
                                                    <td><small><?= isset($ticket['ticket_created_at']) ? date('M j, Y g:i A', strtotime($ticket['ticket_created_at'])) : 'N/A' ?></small></td>
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

        <!-- TAB 2: Submit Ticket -->
        <div class="tab-pane fade" id="submitTicket" role="tabpanel" aria-labelledby="submit-tab">
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
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
                                            $date = date('M j, Y', strtotime($request['date_requested']));
                                            $docName = $request['doc_name'] ?? 'Unknown Document';
                                            echo "<option value='{$request['request_id']}'>#{$request['request_id']} - {$docName} ({$request['request_status']}) - $date</option>";
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

                <!-- Quick Actions -->
                <div class="col-md-6">
                    <div class="card">
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
document.addEventListener("DOMContentLoaded", function() {
    // Auto-hide success alert after 1.48s
    const alert = document.getElementById('successAlert');
    if (alert) {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 1480);
    }

    // Subject dropdown logic
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

    // Filter dashboard cards
    const cards = document.querySelectorAll('.filter-card');
    const tbody = document.querySelector('table tbody');
    if (!tbody) return;

    let noTicketsRow = document.createElement('tr');
    noTicketsRow.id = 'noTicketsRow';
    noTicketsRow.innerHTML = `<td colspan="7" class="text-center text-muted py-3">⚠️ No tickets found for this status.</td>`;
    noTicketsRow.style.display = 'none';
    tbody.appendChild(noTicketsRow);

    cards.forEach(card => {
        card.addEventListener('click', function () {
            const status = this.getAttribute('data-status');

            cards.forEach(c => c.classList.remove('border-3','border-dark'));
            this.classList.add('border-3','border-dark');

            let anyVisible = false;
            const rows = tbody.querySelectorAll('tr:not(#noTicketsRow)');
            rows.forEach(row => {
                const statusText = row.querySelector('td:nth-child(4) span').innerText.trim().toLowerCase().replace(' ', '_');
                if (status === 'all' || statusText === status) {
                    row.style.display = '';
                    anyVisible = true;
                } else row.style.display = 'none';
            });

            noTicketsRow.style.display = anyVisible ? 'none' : '';
            if (status === 'all') {
                Array.from(rows)
                    .sort((a, b) => new Date(a.querySelector('td:nth-child(6)').innerText) - new Date(b.querySelector('td:nth-child(6)').innerText))
                    .forEach(tr => tbody.appendChild(tr));
            }
        });
    });
});
</script>

<?php include 'footer.php'; ?>
