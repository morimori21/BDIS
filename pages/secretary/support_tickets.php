<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('secretary');

// Remove the accept_ticket and reject_ticket POST handlers

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $ticket_id = $_POST['ticket_id'];
    $new_status = $_POST['status'];

    $stmt = $pdo->prepare("UPDATE support_tickets SET ticket_status = ? WHERE ticket_id = ?");
    $stmt->execute([$new_status, $ticket_id]);

    logActivity($_SESSION['user_id'], "Updated ticket #$ticket_id status to $new_status");
    $_SESSION['success_message'] = "Ticket #$ticket_id status updated to " . str_replace('_',' ', $new_status);
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// Handle ticket response
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['respond_ticket'])) {
    $ticket_id = $_POST['ticket_id'];
    
    // Normalize message: strip HTML tags, decode entities, convert non-breaking spaces
    $message = $_POST['message'] ?? '';
    $message = html_entity_decode(strip_tags($message));
    $message = str_replace("\xc2\xa0", ' ', $message); // convert &nbsp; to space
    $message = trim($message);

    if (empty($message)) {
        http_response_code(400);
        exit("Message cannot be empty");
    }

    $sender_id = $_SESSION['user_id'];

    // Verify sender is authorized to respond
    $stmt = $pdo->prepare("
        SELECT ticket_id 
        FROM support_tickets 
        WHERE ticket_id = :ticket_id
        AND (user_id = :sender_id OR assigned_to = :sender_id)
    ");
    $stmt->execute([
        ':ticket_id' => $ticket_id,
        ':sender_id' => $sender_id
    ]);

    if ($stmt->fetch()) {
        // Insert message into chat
        $stmt = $pdo->prepare("
            INSERT INTO chat_messages (ticket_id, sender_id, message, sent_at) 
            VALUES (:ticket_id, :sender_id, :message, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':ticket_id' => $ticket_id,
            ':sender_id' => $sender_id,
            ':message' => $message
        ]);

        logActivity($sender_id, "Responded to support ticket #$ticket_id");
        $_SESSION['success_message'] = "Response added to ticket #$ticket_id";
    } else {
        http_response_code(403);
        echo "You are not authorized to respond to this ticket.";
    }
}

// Display success message
$success = '';
if (!empty($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']); // show only once
}

include 'header.php'; 
?>

<!-- Bootstrap CSS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<style>
    /* Disable scrolling */
    body {
        overflow: hidden;
        font-size: 14px; /* Base font size */
    }

    .filter-card:hover {
        transform: scale(1.03);
        transition: 0.2s;
        box-shadow: 0 0 10px rgba(0,0,0,0.15);
    }

    /* Improved table styling with better spacing */
    .table td, .table th {
        padding: 0.5rem 0.6rem; /* Increased padding */
        font-size: 0.85rem; /* Larger font size */
        vertical-align: middle;
        line-height: 1.4;
        border-bottom: 1px solid #dee2e6;
    }

    .table thead th {
        font-size: 0.9rem; /* Larger header font */
        padding: 0.6rem 0.7rem;
        background-color: #f8f9fa;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
    }

    .table-hover tbody tr:hover {
        background-color: rgba(0, 123, 255, 0.05);
    }

    /* Better badge sizing */
    .badge {
        font-size: 0.75rem; /* Larger badge text */
        padding: 0.3rem 0.5rem; /* More padding */
        font-weight: 500;
    }

    /* Improved button sizing */
    .btn-sm {
        padding: 0.4rem 0.75rem; /* More padding */
        font-size: 0.8rem; /* Larger text */
        line-height: 1.3;
        border-radius: 0.375rem;
    }

    /* Pagination styling */
    .pagination .page-link {
        border: 1px solid #dee2e6;
        margin: 0 2px;
        border-radius: 0.375rem;
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
        color: #495057;
    }

    .pagination .page-item.active .page-link {
        background-color: #007bff;
        border-color: #007bff;
        color: white;
    }

    .pagination .page-item.disabled .page-link {
        color: #6c757d;
        background-color: #fff;
        border-color: #dee2e6;
    }

    /* Card body with better spacing */
    .card-body {
        padding: 1rem 1.25rem; /* Increased padding */
    }

    /* Metric cards with better typography */
    .filter-card .card-body {
        padding: 1rem 0.75rem;
    }

    .filter-card h6 {
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }

    .filter-card h4, .filter-card h3, .filter-card h5 {
        font-weight: 700;
        margin-bottom: 0;
    }

    /* Container and layout improvements */
    .container {
        padding: 0 15px;
    }

    .card {
        border: 1px solid rgba(0, 0, 0, 0.125);
        border-radius: 0.5rem;
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }

    .card-header {
        padding: 0.75rem 1.25rem;
        font-weight: 600;
    }

    /* Alert styling */
    .alert {
        font-size: 0.9rem;
        padding: 0.75rem 1.25rem;
        border-radius: 0.375rem;
    }

    /* Better spacing for empty state */
    .text-center.py-4 {
        padding: 2rem 1rem !important;
    }

    .text-center.py-4 i {
        font-size: 3rem;
        margin-bottom: 1rem;
    }

    /* Improved action buttons */
    .btn-outline-primary {
        border-width: 1px;
        font-weight: 500;
    }

    /* Table responsive container */
    .table-responsive {
        border-radius: 0.375rem;
        border: 1px solid #dee2e6;
    }

    /* Status badges with better contrast */
    .bg-primary { background-color: #007bff !important; }
    .bg-info { background-color: #17a2b8 !important; }
    .bg-success { background-color: #28a745 !important; }
    .bg-secondary { background-color: #6c757d !important; }

    /* Row hover effects */
    .table tbody tr {
        transition: background-color 0.15s ease-in-out;
    }
</style>

<div class="container mt-3">
    <div class="row mb-3">
    <!-- Open -->
    <div class="col-md-2 mb-2">
        <div class="card border-primary filter-card" data-status="open" style="cursor:pointer;">
            <div class="card-body text-center">
                <h6 class="text-primary">🆕 Open</h6>
                <?php
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'open'");
                $stmt->execute();
                $openCount = $stmt->fetch()['count'];
                ?>
                <h4 class="text-primary"><?php echo $openCount; ?></h4>
            </div>
        </div>
    </div>
<!-- In Progress -->
        <div class="col-md-2 mb-2">
            <div class="card border-info filter-card" data-status="in-progress" style="cursor:pointer;">
                <div class="card-body text-center">
                    <h6 class="text-info">🔄 In Progress</h6>
                <?php
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'in-progress'");
                    $stmt->execute();
                    $progressCount = $stmt->fetch()['count'];
                    ?>
                    <h4 class="text-info"><?php echo $progressCount; ?></h4>
                </div>
            </div>
        </div>

<!-- Resolved -->
    <div class="col-md-2 mb-2">
        <div class="card border-success filter-card" data-status="resolved" style="cursor:pointer;">
            <div class="card-body text-center">
                <h6 class="text-success">✅ Resolved</h6>
                <?php
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'resolved'");
                $stmt->execute();
                $resolvedCount = $stmt->fetch()['count'];
                ?>
                <h4 class="text-success"><?php echo $resolvedCount; ?></h4>
            </div>
        </div>
    </div>

    <!-- Closed -->
    <div class="col-md-2 mb-2">
        <div class="card border-secondary filter-card" data-status="closed" style="cursor:pointer;">
            <div class="card-body text-center">
                <h6 class="text-secondary">🗂️ Closed</h6>
                <?php
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE ticket_status = 'closed' AND user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $closedCount = $stmt->fetch()['count'];
                ?>
                <h4 class="text-secondary"><?php echo $closedCount; ?></h4>
            </div>
        </div>
    </div>

    <!-- Total Tickets -->
    <div class="col-md-4 mb-2">
        <div class="card border-dark filter-card" data-status="all" style="cursor:pointer;">
            <div class="card-body text-center">
                <h5 class="text-dark">📊 Total Tickets</h5>
                <?php
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM support_tickets");
                    $stmt->execute();
                    $totalCount = $stmt->fetch()['count'];
                ?>

                <h3 class="text-dark"><?php echo $totalCount; ?></h3>
            </div>
        </div>
    </div>

    
    <?php if (!empty($success)): ?>
    <div id="successAlert" class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="tab-content" id="ticketTabsContent">
        <!-- TAB 1: My Tickets -->
        <div class="tab-pane fade show active" id="myTickets" role="tabpanel" aria-labelledby="mytickets-tab">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center py-3">
                            <h6 class="mb-0 fw-bold">Tickets</h6>
                        </div>
                
                <div class="card-body p-3">
                    <?php
                        $filter = $_GET['filter'] ?? '';
                        $user_id = $_SESSION['user_id'];
                        
                        // Pagination setup
                        $entriesPerPage = 10;
                        $currentPage = isset($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
                        $offset = ($currentPage - 1) * $entriesPerPage;

                        // Base SQL for counting
                        $countSql = "
                            SELECT COUNT(*) as total
                            FROM support_tickets st
                            LEFT JOIN document_requests dr ON st.request_id = dr.request_id
                            LEFT JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                            LEFT JOIN users u ON st.user_id = u.user_id
                            WHERE 1=1
                        ";

                        // Apply status filter
                        $statusMap = [
                            'pending' => 'open',
                            'in-progress' => 'in-progress',
                            'resolved' => 'resolved',
                            'closed' => 'closed'
                        ];

                        if (isset($statusMap[$filter])) {
                            $countSql .= " AND st.ticket_status = :status";
                        }

                        $countStmt = $pdo->prepare($countSql);
                        if (isset($statusMap[$filter])) {
                            $countStmt->execute([':status' => $statusMap[$filter]]);
                        } else {
                            $countStmt->execute();
                        }
                        $totalTickets = $countStmt->fetch()['total'];
                        $totalPages = max(1, ceil($totalTickets / $entriesPerPage));

                        // Main SQL with pagination
                        $sql = "
                            SELECT 
                                st.*,
                                dt.doc_name AS document_name,
                                CONCAT(u.first_name, ' ', u.surname) AS resident_name,
                                ROW_NUMBER() OVER (ORDER BY st.ticket_id ASC) AS ticket_number,
                                (SELECT COUNT(*) FROM chat_messages cm WHERE cm.ticket_id = st.ticket_id) AS response_count
                            FROM support_tickets st
                            LEFT JOIN document_requests dr ON st.request_id = dr.request_id
                            LEFT JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                            LEFT JOIN users u ON st.user_id = u.user_id
                            WHERE 1=1
                        ";

                    // **Filter logic for secretaries**
                    // Show all tickets
                    $sql .= " AND 1=1";

                    // Apply status filter if specified
                    if (isset($statusMap[$filter])) {
                        $sql .= " AND st.ticket_status = :status";
                    }

                    $sql .= " ORDER BY st.ticket_id ASC LIMIT :limit OFFSET :offset";

                    $stmt = $pdo->prepare($sql);

                    // Bind parameters
                    if (isset($statusMap[$filter])) {
                        $stmt->bindValue(':status', $statusMap[$filter], PDO::PARAM_STR);
                    }
                    $stmt->bindValue(':limit', $entriesPerPage, PDO::PARAM_INT);
                    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                    $stmt->execute();

                    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    ?>

<!-- ✅ Only one display check -->
                <?php if (empty($tickets)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-ticket-alt fa-3x text-muted mb-3"></i>
                        <p class="text-muted fs-6 mb-2">No tickets found for this filter.</p>
                        <small class="text-muted">Try changing the filter above.</small>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                <tr>
                    <th class="fw-semibold">Ticket #</th>
                    <th class="fw-semibold">Resident</th>
                    <th class="fw-semibold">Related Document</th>
                    <th class="fw-semibold">Status</th>
                    <th class="fw-semibold">Responses</th>
                    <th class="fw-semibold">Created</th>
                    <th class="fw-semibold">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tickets as $ticket): 
                    $statusColors = [
                        'open' => 'primary',
                        'in-progress' => 'info',
                        'resolved' => 'success',
                        'closed' => 'secondary'
                    ];
                    $statusColor = $statusColors[$ticket['ticket_status']] ?? 'secondary';
                ?>
                    <tr>
                        <td class="fw-bold">#<?php echo $ticket['ticket_number']; ?></td>
                        <td><?php echo isset($ticket['resident_name']) ? htmlspecialchars($ticket['resident_name']) : 'N/A'; ?></td>
                        <td>
                            <?php 
                            if (!empty($ticket['document_name'])) {
                                echo htmlspecialchars($ticket['document_name']);
                            } else {
                                echo '<em class="text-muted">General Inquiry</em>';
                            }
                            ?>
                            <?php if ($ticket['response_count'] > 0): ?>
                                <span class="badge bg-info ms-2"><?php echo $ticket['response_count']; ?> replies</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $statusColor; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $ticket['ticket_status'])); ?>
                            </span>
                        </td>
                        <td class="text-center"><?php echo $ticket['response_count']; ?></td>
                        <td><span class="text-muted"><?php echo isset($ticket['ticket_created_at']) ? date('M j, Y g:i A', strtotime($ticket['ticket_created_at'])) : 'N/A'; ?></span></td>
                        <td>
                            <!-- Only View button remains -->
                            <a href="view_ticket.php?ticket_id=<?php echo $ticket['ticket_id']; ?>" 
                               class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye me-1"></i> View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Section -->
    <div class="d-flex justify-content-between align-items-center mt-4 px-2 pb-1">
        <div class="text-muted" style="font-size: 0.9rem;">
            Showing <?= min($offset + 1, $totalTickets) ?> to <?= min($offset + $entriesPerPage, $totalTickets) ?> of <?= $totalTickets ?> entries
        </div>
        <nav>
            <ul class="pagination mb-0">
                <!-- Previous Button -->
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $currentPage - 1 ?><?= $filter ? '&filter=' . $filter : '' ?>" style="color: #6c757d;">Previous</a>
                </li>
                
                <!-- Page Numbers -->
                <?php 
                $startPage = max(1, $currentPage - 2);
                $endPage = min($totalPages, $currentPage + 2);
                
                for($p = $startPage; $p <= $endPage; $p++): 
                ?>
                    <li class="page-item <?= $currentPage == $p ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $p ?><?= $filter ? '&filter=' . $filter : '' ?>" 
                           style="<?= $currentPage == $p ? 'background-color: #007bff; border-color: #007bff; color: white;' : 'color: #6c757d;' ?>">
                            <?= $p ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <!-- Next Button -->
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $currentPage + 1 ?><?= $filter ? '&filter=' . $filter : '' ?>" style="color: #6c757d;">Next</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
</div>
</div>
</div>
</div>
</div>

<script>
// Auto-hide success alert after 6 seconds (6000 ms)
document.addEventListener("DOMContentLoaded", function() {
    const alert = document.getElementById('successAlert');
    if (alert) {
        setTimeout(() => {
            // Use Bootstrap's built-in alert dispose
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 1480);
    }
});


document.addEventListener('DOMContentLoaded', function () {
    const cards = document.querySelectorAll('.filter-card');
    const tbody = document.querySelector('table tbody');

    // Only add noTicketsRow if table exists
    if (!tbody) return;

    let noTicketsRow = document.createElement('tr');
    noTicketsRow.id = 'noTicketsRow';
    noTicketsRow.innerHTML = `
        <td colspan="7" class="text-center text-muted py-4">
            ⚠️ No tickets found for this status.
        </td>
    `;
    noTicketsRow.style.display = 'none'; // hide initially
    tbody.appendChild(noTicketsRow);

    cards.forEach(card => {
        card.addEventListener('click', function () {
            const status = this.getAttribute('data-status'); // 'open', 'in_progress', etc.

            // Highlight selected card
            cards.forEach(c => c.classList.remove('border-3', 'border-dark'));
            this.classList.add('border-3', 'border-dark');

            let anyVisible = false;
            const rows = tbody.querySelectorAll('tr:not(#noTicketsRow)');
            rows.forEach(row => {
                const statusText = row.querySelector('td:nth-child(4) span').innerText
                    .trim()
                    .toLowerCase()
                    .replace(' ', '_'); // normalize

                if (status === 'all' || statusText === status) {
                    row.style.display = '';
                    anyVisible = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Show or hide the noTicketsRow
            noTicketsRow.style.display = anyVisible ? 'none' : '';
            
            // Sort by date ascending if "all"
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