<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('admin');

// Handle ticket assignments and status updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $ticket_ids = $_POST['ticket_ids'] ?? [];
    
    if (!empty($ticket_ids)) {
        foreach ($ticket_ids as $ticket_id) {
            switch ($action) {
                case 'assign_secretary':
                    $secretary_id = $_POST['assign_to'];
                    $pdo->prepare("UPDATE support_tickets SET assigned_to = ?, status = 'in_progress', updated_at = CURRENT_TIMESTAMP WHERE ticket_id = ?")
                        ->execute([$secretary_id, $ticket_id]);
                    break;
                case 'mark_resolved':
                    $pdo->prepare("UPDATE support_tickets SET status = 'resolved', resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE ticket_id = ?")
                        ->execute([$ticket_id]);
                    break;
                case 'mark_closed':
                    $pdo->prepare("UPDATE support_tickets SET status = 'closed', updated_at = CURRENT_TIMESTAMP WHERE ticket_id = ?")
                        ->execute([$ticket_id]);
                    break;
            }
        }
        
        logActivity($_SESSION['user_id'], "Bulk action '$action' applied to " . count($ticket_ids) . " tickets");
        $success = "Bulk action applied to " . count($ticket_ids) . " tickets";
    }
}



$sql = "
    SELECT u.user_id, u.first_name
    FROM users u
    INNER JOIN user_roles ur ON u.user_id = ur.user_id
    WHERE ur.role = 'secretary'
";

$secretaries = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// If you're fetching only one secretary (for example, logged-in user)
$username = $secretaries[0]['first_name'] ?? '';

include 'header.php';
?>

<div class="container">
    <h2>🎫 Support Ticket Administration</h2>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

 <!-- Performance Metrics -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6>⚡ Avg Response Time</h6>
                    <?php
                    $stmt = $pdo->query("
                        SELECT AVG(TIMESTAMPDIFF(HOUR, st.created_at, cm.sent_at)) AS avg_hours
                        FROM support_tickets st
                        JOIN chat_messages cm ON st.ticket_id = cm.ticket_id
                        WHERE cm.sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        AND cm.sender_id != st.user_id
                    ");
                    $avgHours = $stmt->fetch()['avg_hours'] ?? 0;
                    ?>
                    <h5><?php echo number_format($avgHours, 1); ?> hours</h5>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6>📅 This Month</h6>
                    <?php
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM support_tickets WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
                    $monthlyCount = $stmt->fetch()['count'];
                    ?>
                    <h5><?php echo $monthlyCount; ?> tickets</h5>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6>🎯 Resolution Rate</h6>
                    <?php
                    $totalTickets = $pdo->query("SELECT COUNT(*) as count FROM support_tickets")->fetch()['count'];
                    $resolvedTickets = $pdo->query("SELECT COUNT(*) as count FROM support_tickets WHERE status IN ('resolved', 'closed')")->fetch()['count'];
                    $resolutionRate = $totalTickets > 0 ? ($resolvedTickets / $totalTickets) * 100 : 0;
                    ?>
                    <h5><?php echo number_format($resolutionRate, 1); ?>%</h5>
                </div>
            </div>
        </div>
    </div>
    
<!-- Comprehensive Statistics -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card border-primary">
            <div class="card-body text-center">
                <h6 class="text-primary">🆕 Open</h6>
                <?php
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'open'");
                $openCount = $stmt->fetch()['count'];
                ?>
                <h4 class="text-primary"><?php echo $openCount; ?></h4>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="card border-info">
            <div class="card-body text-center">
                <h6 class="text-info">🔄 In Progress</h6>
                <?php
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'in_progress'");
                $progressCount = $stmt->fetch()['count'];
                ?>
                <h4 class="text-info"><?php echo $progressCount; ?></h4>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="card border-success">
            <div class="card-body text-center">
                <h6 class="text-success">✅ Resolved</h6>
                <?php
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'resolved'");
                $resolvedCount = $stmt->fetch()['count'];
                ?>
                <h4 class="text-success"><?php echo $resolvedCount; ?></h4>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="card border-secondary">
            <div class="card-body text-center">
                <h6 class="text-secondary">🗂️ Closed</h6>
                <?php
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'closed'");
                $closedCount = $stmt->fetch()['count'];
                ?>
                <h4 class="text-secondary"><?php echo $closedCount; ?></h4>
            </div>
        </div>
    </div>

    <!-- Total (Wider Card) -->
    <div class="col-md-4">
        <div class="card border-dark">
            <div class="card-body text-center">
                <h5 class="text-dark">📊 Total Tickets</h5>
                <?php
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM support_tickets");
                $totalCount = $stmt->fetch()['count'];
                ?>
                <h3 class="text-dark"><?php echo $totalCount; ?></h3>
            </div>
        </div>
    </div>
</div>

    
   
    
    <!-- Bulk Actions -->
    <!-- <form method="POST" id="bulkForm">
        <div class="card mb-3">
            <div class="card-header bg-dark text-white">
                <div class="row align-items-center">
                    <div class="col-md-3">
                        <h6 class="mb-0">Bulk Actions</h6>
                    </div>
                    <div class="col-md-3">
                        <select name="bulk_action" class="form-select form-select-sm" required>
                            <option value="">Select Action</option>
                            <option value="assign_secretary">Assign to Secretary</option>
                            <option value="mark_resolved">Mark as Resolved</option>
                            <option value="mark_closed">Mark as Closed</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="assign_to" class="form-select form-select-sm" id="assignTo" style="display: none;">
                            <option value="">Select Secretary</option>
                            <?php foreach ($secretaries as $secretary): ?>
                                <option value="<?php echo $secretary['user_id']; ?>"><?php echo htmlspecialchars($secretary['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-warning btn-sm">Apply to Selected</button>
                    </div>
                </div>
            </div>
        </div> -->
        
        <!-- Tickets Table -->
        <div class="card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">All Support Tickets</h5>
                <!-- <div>
                    <button type="button" class="btn btn-light btn-sm" onclick="selectAll()">Select All</button>
                    <button type="button" class="btn btn-light btn-sm" onclick="selectNone()">Select None</button>
                </div> -->
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th width="50">
                                    <input type="checkbox" id="selectAllCheckbox" onchange="toggleAll()">
                                </th>
                                <th>Ticket #</th>
                                <th>Resident</th>
                                <th>Subject</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Created</th>
                                <th>Last Update</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("
                                  SELECT 
                                        st.*, 
                                        CONCAT(u.first_name, ' ', u.surname) AS resident_name,
                                        CONCAT(u_assigned.first_name, ' ', u_assigned.surname) AS assigned_username,
                                        (
                                            SELECT COUNT(*) 
                                            FROM chat_messages cm 
                                            WHERE cm.ticket_id = st.ticket_id
                                        ) AS response_count
                                    FROM support_tickets st
                                    JOIN users u ON st.user_id = u.user_id
                                    LEFT JOIN users u_assigned ON st.assigned_to = u_assigned.user_id
                                    ORDER BY 
                                        CASE st.priority 
                                            WHEN 'urgent' THEN 1 
                                            WHEN 'high' THEN 2 
                                            WHEN 'medium' THEN 3 
                                            WHEN 'low' THEN 4 
                                        END,
                                        st.created_at DESC
                            ");
                            
                            while ($ticket = $stmt->fetch()):
                                $priorityColors = [
                                    'low' => 'success',
                                    'medium' => 'warning', 
                                    'high' => 'danger',
                                    'urgent' => 'danger'
                                ];
                                $statusColors = [
                                    'open' => 'primary',
                                    'in_progress' => 'info',
                                    'resolved' => 'success',
                                    'closed' => 'secondary'
                                ];
                                $priorityColor = $priorityColors[$ticket['priority']] ?? 'secondary';
                                $statusColor = $statusColors[$ticket['status']] ?? 'secondary';
                            ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="ticket_ids[]" value="<?php echo $ticket['ticket_id']; ?>" class="ticket-checkbox">
                                    </td>
                                    <td><strong>#<?php echo $ticket['ticket_id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($ticket['resident_name']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars(substr($ticket['subject'], 0, 50)); ?>
                                        <?php if ($ticket['response_count'] > 0): ?>
                                            <span class="badge bg-info ms-1"><?php echo $ticket['response_count']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-<?php echo $priorityColor; ?>"><?php echo ucfirst($ticket['priority']); ?></span></td>
                                    <td><span class="badge bg-<?php echo $statusColor; ?>"><?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?></span></td>
                                    <td>
                                        <?php if ($ticket['assigned_username']): ?>
                                            <small><?php echo htmlspecialchars($ticket['assigned_username']); ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">Unassigned</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?php echo date('M j', strtotime($ticket['created_at'])); ?></small></td>
                                    <td><small><?php echo date('M j', strtotime($ticket['updated_at'])); ?></small></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewTicket(<?php echo $ticket['ticket_id']; ?>)">
                                            View
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </form>
    
    <!-- Ticket Details Modal -->
    <div class="modal fade" id="ticketModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ticket Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="ticketModalBody">
                    <!-- Ticket details will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function viewTicket(ticketId) {
    fetch('../secretary/get_ticket_management.php?ticket_id=' + ticketId)
        .then(response => response.text())
        .then(data => {
            document.getElementById('ticketModalBody').innerHTML = data;
            var modal = new bootstrap.Modal(document.getElementById('ticketModal'));
            modal.show();
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Loading Error',
                text: 'Error loading ticket details'
            });
        });
}

function toggleAll() {
    const checkboxes = document.querySelectorAll('.ticket-checkbox');
    const selectAll = document.getElementById('selectAllCheckbox').checked;
    checkboxes.forEach(checkbox => checkbox.checked = selectAll);
}

function selectAll() {
    document.getElementById('selectAllCheckbox').checked = true;
    toggleAll();
}

function selectNone() {
    document.getElementById('selectAllCheckbox').checked = false;
    toggleAll();
}

// Show/hide assignment dropdown based on action
document.querySelector('select[name="bulk_action"]').addEventListener('change', function() {
    const assignTo = document.getElementById('assignTo');
    if (this.value === 'assign_secretary') {
        assignTo.style.display = 'block';
        assignTo.required = true;
    } else {
        assignTo.style.display = 'none';
        assignTo.required = false;
    }
});
</script>

<?php include 'footer.php'; ?>