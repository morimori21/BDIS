<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotResident();

// Handle new ticket submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ticket'])) {
    $subject = sanitize($_POST['subject']);
    $description = sanitize($_POST['description']);
    // Note: priority field removed from new database structure
    $request_id = !empty($_POST['request_id']) ? $_POST['request_id'] : null;
    
    $stmt = $pdo->prepare("INSERT INTO support_tickets (user_id, request_id, subject, description, status) VALUES (?, ?, ?, ?, 'open')");
    $stmt->execute([$_SESSION['user_id'], $request_id, $subject, $description]);
    
    $ticketId = $pdo->lastInsertId();
    logActivity($_SESSION['user_id'], "Created support ticket #$ticketId: $subject");
    
    // Create notification for secretaries
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE role IN ('secretary', 'admin')");
    $stmt->execute();
    $staff = $stmt->fetchAll();
    
    foreach ($staff as $member) {
        $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")
            ->execute([$member['user_id'], "New support ticket #$ticketId submitted by resident"]);
    }
    
    $success = "Support ticket #$ticketId has been submitted successfully!";
}

// Handle ticket response
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['respond_ticket'])) {
    $ticket_id = $_POST['ticket_id'];
    $message = sanitize($_POST['message']);
    
    // Verify ticket belongs to current user
    $stmt = $pdo->prepare("SELECT ticket_id FROM support_tickets WHERE ticket_id = ? AND user_id = ?");
    $stmt->execute([$ticket_id, $_SESSION['user_id']]);
    
    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO ticket_responses (ticket_id, user_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$ticket_id, $_SESSION['user_id'], $message]);
        
        // Update ticket status to show activity
        $pdo->prepare("UPDATE support_tickets SET updated_at = CURRENT_TIMESTAMP WHERE ticket_id = ?")
            ->execute([$ticket_id]);
        
        logActivity($_SESSION['user_id'], "Responded to support ticket #$ticket_id");
        $success = "Response added to ticket #$ticket_id";
    }
}

include 'header.php'; 
?>

<div class="container">
    <h2>🎫 Support Tickets</h2>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Submit New Ticket -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5>📝 Submit New Support Ticket</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label for="subject" class="form-label">Subject *</label>
                            <input type="text" class="form-control" id="subject" name="subject" required 
                                   placeholder="Brief description of your concern">
                        </div>
                        
                        <div class="mb-3">
                            <label for="request_id" class="form-label">Related Document Request (Optional)</label>
                            <select class="form-select" id="request_id" name="request_id">
                                <option value="">-- Not related to any specific request --</option>
                                <?php
                                $stmt = $pdo->prepare("
                                    SELECT dr.request_id, dt.doc_name, dr.request_status, dr.date_requested 
                                    FROM document_requests dr 
                                    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id 
                                    WHERE dr.resident_id = ? 
                                    ORDER BY dr.date_requested DESC
                                ");
                                $stmt->execute([$_SESSION['user_id']]);
                                while ($request = $stmt->fetch()) {
                                    $date = date('M j, Y', strtotime($request['date_requested']));
                                    echo "<option value='{$request['request_id']}'>#{$request['request_id']} - {$request['doc_name']} ({$request['request_status']}) - $date</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="priority" class="form-label">Priority</label>
                            <select class="form-select" id="priority" name="priority" required>
                                <option value="low">🟢 Low - General inquiry</option>
                                <option value="medium" selected>🟡 Medium - Standard concern</option>
                                <option value="high">🟠 High - Important issue</option>
                                <option value="urgent">🔴 Urgent - Critical problem</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description *</label>
                            <textarea class="form-control" id="description" name="description" rows="4" required 
                                      placeholder="Please provide detailed information about your concern..."></textarea>
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
                        <li class="mb-2">
                            <strong>📋 Document Status Questions:</strong><br>
                            <small>Check your <a href="request_history.php">Request History</a> for current status</small>
                        </li>
                        <li class="mb-2">
                            <strong>⏰ Processing Time:</strong><br>
                            <small>Documents typically take 3-5 business days to process</small>
                        </li>
                        <li class="mb-2">
                            <strong>💰 Payment Issues:</strong><br>
                            <small>Payments are processed during document pickup</small>
                        </li>
                        <li class="mb-2">
                            <strong>📄 Document Requirements:</strong><br>
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
            </form>
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
        </div>
        <div class="col-md-4">
            <h5>Quick Topics</h5>
            <ul class="list-group">
                <li class="list-group-item">Document Status Inquiry</li>
                <li class="list-group-item">Payment Questions</li>
                <li class="list-group-item">General Support</li>
            </ul>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
