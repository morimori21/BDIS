<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('secretary');

if (!isset($_GET['ticket_id'])) {
    echo "Invalid ticket ID";
    exit;
}

$ticket_id = intval($_GET['ticket_id']);

$ticket_id = (int)$_GET['ticket_id'];

// Get current user ID safely (works for both session structures)
if (isset($_SESSION['user']['user_id'])) {
    $current_user_id = $_SESSION['user']['user_id'];
} elseif (isset($_SESSION['user_id'])) {
    $current_user_id = $_SESSION['user_id'];
} else {
    echo "Error: current user not found in session.";
    exit;
}

function getProfileImageSrc($blobOrPath) {
    if (empty($blobOrPath)) {
        return '/Project_A2/assets/images/default-avatar.png';
    }

    // If it's binary data (BLOB)
    if (is_string($blobOrPath) && strpos($blobOrPath, "\0") !== false) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $blobOrPath);
        finfo_close($finfo);
        return 'data:' . $mime . ';base64,' . base64_encode($blobOrPath);
    }

    // Otherwise, treat as a path in uploads folder
    return '/Project_A2/uploads/' . htmlspecialchars($blobOrPath);
}

// Get ticket details
$stmt = $pdo->prepare("
    SELECT 
        st.*, 
        CONCAT(u.first_name, ' ', u.surname) AS resident_name,
        e.email AS resident_email,
        u.profile_picture AS resident_picture,
        dt.doc_name AS document_name,
        dr.request_status AS request_status,
        dr.pickup_representative
    FROM support_tickets st
    LEFT JOIN document_requests dr ON st.request_id = dr.request_id
    LEFT JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    JOIN users u ON st.user_id = u.user_id
    LEFT JOIN account a ON u.user_id = a.user_id
    LEFT JOIN email e ON a.email_id = e.email_id
    WHERE st.ticket_id = ?
");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    echo "Ticket not found";
    exit;
}

//receiver
$stmt = $pdo->prepare("
    SELECT 
        u.user_id,
        CONCAT(u.first_name, ' ', u.surname) AS full_name,
        u.profile_picture,
        ur.role
    FROM users u
    JOIN user_roles ur ON u.user_id = ur.user_id
    WHERE u.user_id IN (
        SELECT DISTINCT user_id FROM chat_messages WHERE ticket_id = :ticket_id
        UNION
        SELECT user_id FROM support_tickets WHERE ticket_id = :ticket_id
    )
    AND u.user_id != :current_user_id
");
$stmt->execute([
    'ticket_id' => $ticket_id,
    'current_user_id' => $current_user_id
]);
$receivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get responses
$stmt = $pdo->prepare("
    SELECT 
        cm.message_id,
        cm.ticket_id,
        cm.user_id,
        cm.message,
        u.first_name,
        CONCAT(u.first_name, ' ', u.surname) AS user_full_name,
        u.profile_picture,
        ur.role
    FROM chat_messages cm
    JOIN users u ON cm.user_id = u.user_id
    LEFT JOIN user_roles ur ON u.user_id = ur.user_id
    WHERE cm.ticket_id = ?
    ORDER BY cm.message_id ASC
");
$stmt->execute([$ticket_id]);
$responses = $stmt->fetchAll(PDO::FETCH_ASSOC);



$statusColors = [
    'open' => 'primary',
    'in-progress' => 'info',
    'resolved' => 'success',
    'closed' => 'secondary'
];
$statusColor = $statusColors[$ticket['ticket_status']] ?? 'secondary';
?>

<div class="row" style="height: 80vh;">
    <!-- LEFT COLUMN: Receivers + Conversation -->
    <div class="col-lg-8 mb-4 h-100 d-flex flex-column">

       <!-- Receivers -->
<div class="card mb-3 p-2">
    <?php if (!empty($receivers)): ?>
        <div class="d-flex flex-column gap-2 overflow-auto" style="max-height: 180px;">
            <?php foreach ($receivers as $receiver): ?>
                <div class="d-flex align-items-center border-bottom pb-2">
                   <div class="rounded-circle overflow-hidden border border-secondary me-3" 
     style="width: 55px; height: 55px; flex-shrink: 0;">

    <?php
    if (!empty($receiver['profile_picture'])) {
        // Detect MIME type dynamically (works for JPEG, PNG, etc.)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $receiver['profile_picture']);
        finfo_close($finfo);

        // Convert binary to base64
        $base64 = base64_encode($receiver['profile_picture']);
        $profileSrc = 'data:' . $mime . ';base64,' . $base64;
    } else {
        // Default avatar fallback
        $profileSrc = '/Project_A2/assets/images/default-avatar.png';
    }
    ?>

    <img src="<?php echo getProfileImageSrc($receiver['profile_picture']); ?>" 
     alt="Profile" class="w-100 h-100" style="object-fit: cover;">
</div>
                    <div class="d-flex flex-column">
                        <span class="fw-bold" style="font-size: 0.9rem;">
                            <?php echo htmlspecialchars($receiver['full_name']); ?>
                        </span>
                        <small class="text-muted" style="font-size: 0.75rem;">
                            <?php echo htmlspecialchars(ucfirst($receiver['role'])); ?>
                        </small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="text-muted m-2">No receivers available</p>
    <?php endif; ?>
</div>

<!-- chat -->
<div class="card flex-grow-1 d-flex flex-column position-relative">
    <div class="card-body flex-grow-1 overflow-auto" id="chat-body" style="padding-bottom: 100px;">
        <?php if (empty($responses)): ?>
            <p class="text-muted">No responses yet.</p>
        <?php else: ?>
            <?php foreach ($responses as $response): 
                $isSecretary = $response['role'] === 'secretary' || $response['role'] === 'staff';
                $bubbleClass = $isSecretary 
                    ? 'bg-light text-dark me-auto' 
                    : 'bg-primary text-white ms-auto';
                $alignClass = $isSecretary ? 'justify-content-start' : 'justify-content-end';
                $profileSrc = getProfileImageSrc($response['profile_picture']);
            ?>
            <div class="d-flex mb-3 <?php echo $alignClass; ?> align-items-start">
                <?php if ($isSecretary): ?>
                    <div class="rounded-circle overflow-hidden border border-secondary me-2" style="width:40px;height:40px;">
                        <img src="<?php echo $profileSrc; ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;">
                    </div>
                <?php endif; ?>

                <div>
                    <small class="fw-bold d-block"><?php echo htmlspecialchars($response['user_full_name']); ?></small>
                    <div class="p-3 rounded <?php echo $bubbleClass; ?>" style="max-width:75%;"><?php echo nl2br(htmlspecialchars($response['message'])); ?></div>
                </div>

                <?php if (!$isSecretary): ?>
                    <div class="rounded-circle overflow-hidden border border-secondary ms-2" style="width:40px;height:40px;">
                        <img src="<?php echo $profileSrc; ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;">
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<!-- response -->
<?php if ($ticket['ticket_status'] !== 'closed'): ?>
<div class="card-footer p-2 position-relative" id="chat-footer" style="display: flex; flex-direction: column;">
    <form method="POST" class="d-flex flex-column gap-2" id="response-form">
        <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">
        <input type="hidden" name="message" id="hidden-message">

        <!-- contenteditable div -->
        <div class="auto-grow" contenteditable="true" data-placeholder="Type a Message..." 
             style="min-height: 40px; max-height: 200px; border: 1px solid #ced4da; border-radius: 4px; padding: 6px; overflow-y: auto;"></div>

        <button type="submit" name="respond_ticket" class="btn btn-primary align-self-end" style="height: 40px; width: 40px;">
            &gt;
        </button>
    </form>
</div>

<style>
/* placeholder styling */
.auto-grow:empty::before {
    content: attr(data-placeholder);
    color: #6c757d;
    pointer-events: none;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const chatForm = document.getElementById('response-form');
    const chatBody = document.getElementById('chat-body');
    const inputDiv = chatForm.querySelector('.auto-grow');
    const MAX_CHARS = 2500;

    // Resize observer for auto-growing input div
    const observer = new ResizeObserver(entries => {
        for (let entry of entries) {
            chatForm.style.height = entry.contentRect.height + 60 + 'px'; // extra padding for button
        }
    });
    observer.observe(inputDiv);

    // Limit characters in contenteditable
    inputDiv.addEventListener('input', () => {
        if (inputDiv.innerText.length > MAX_CHARS) {
            inputDiv.innerText = inputDiv.innerText.substring(0, MAX_CHARS);
            const range = document.createRange();
            const sel = window.getSelection();
            range.selectNodeContents(inputDiv);
            range.collapse(false);
            sel.removeAllRanges();
            sel.addRange(range);
        }
    });

    // Handle form submission
    chatForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const message = inputDiv.innerText.trim();
        if (!message) {
            Swal.fire({
                icon: 'warning',
                title: 'Empty Message',
                text: 'Message cannot be empty.'
            });
            return;
        }

        const ticketId = chatForm.querySelector('[name="ticket_id"]').value;

        fetch('support_tickets.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                respond_ticket: '1',
                ticket_id: ticketId,
                message: message
            })
        })
        .then(response => {
            if (!response.ok) return response.text().then(text => { throw new Error(`HTTP ${response.status}: ${text}`); });
            return response.text();
        })
        .then(data => {
            console.log("Server response:", data);

            // Append message bubble instantly
            const bubble = document.createElement('div');
            bubble.classList.add('d-flex','mb-3','justify-content-end','align-items-start');
            bubble.innerHTML = `
                <div>
                    <small class="fw-bold d-block">You</small>
                    <div class="p-3 rounded bg-primary text-white" style="max-width:75%;">${message.replace(/\n/g,'<br>')}</div>
                    <small class="text-light d-block" style="font-size:0.75rem;">${new Date().toLocaleString()}</small>
                </div>
                <div class="rounded-circle overflow-hidden border border-secondary ms-2" style="width:40px;height:40px;">
                    <img src="<?php echo getProfileImageSrc($_SESSION['profile_picture'] ?? null); ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;">
                </div>
            `;
            chatBody.appendChild(bubble);
            chatBody.scrollTop = chatBody.scrollHeight;

            // Clear input
            inputDiv.innerText = '';
        })
        .catch(err => {
            console.error("Error sending message:", err);
            Swal.fire({
                icon: 'error',
                title: 'Send Failed',
                text: 'Failed to send message: ' + err.message
            });
        });
    });
});
</script>


<?php else: ?>
<div class="card-footer text-center text-muted">
    This ticket is closed. No further responses can be added.
</div>
<?php endif; ?>


    </div>
</div>

    <!-- RIGHT COLUMN: Ticket Details + Management -->
    <div class="col-lg-4 mb-4 h-100 d-flex flex-column">

        <!-- Ticket Details -->
        <div class="card mb-3 flex-grow-1 overflow-auto">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Ticket Details</h5>
            </div>
            <div class="card-body">
                <p><strong>Ticket #:</strong> <?php echo $ticket['ticket_id']; ?></p>
                <p><strong>Status:</strong> <span class="badge bg-<?php echo $statusColor; ?>"><?php echo ucfirst(str_replace('_',' ',$ticket['ticket_status'])); ?></span></p>
                <p><strong>Updated:</strong> <?php echo isset($ticket['updated_at']) ? date('F j, Y g:i A', strtotime($ticket['updated_at'])) : 'N/A'; ?></p>

                <?php if ($ticket['document_name']): ?>
                    <hr>
                    <p><strong>Related Request:</strong> #<?php echo $ticket['request_id']; ?> - <?php echo htmlspecialchars($ticket['document_name']); ?></p>
                    <p><strong>Request Status:</strong> <span class="badge bg-info"><?php echo ucfirst($ticket['request_status'] ?? 'N/A'); ?></span></p>
                    <a href="../secretary/document_management.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-file-text"></i> View Request
                    </a>
                <?php else: ?>
                    <p><strong>Type:</strong> General Inquiry</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Management Actions -->
        <div class="card mt-3">
            <div class="card-header">
                <h6 class="mb-0">Ticket Management</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">
                    <div class="mb-3">
                        <label class="form-label">Update Status:</label>
                        <select class="form-select" name="status" required>
                            <option value="open" <?php echo $ticket['ticket_status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in-progress" <?php echo $ticket['ticket_status'] == 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $ticket['ticket_status'] == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="closed" <?php echo $ticket['ticket_status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                    <button type="submit" name="update_status" class="btn btn-success w-100 mb-2">
                        <i class="bi bi-check-circle"></i> Update Status
                    </button>
                </form>

                <?php if ($ticket['ticket_status'] !== 'resolved' && $ticket['ticket_status'] !== 'closed'): ?>
                    <form method="POST">
                        <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">
                        <input type="hidden" name="status" value="resolved">
                        <button type="submit" name="update_status" class="btn btn-success btn-sm w-100 mb-2">
                            <i class="bi bi-check-circle"></i> Mark Resolved
                        </button>
                    </form>
                <?php endif; ?>

                <button class="btn btn-outline-secondary btn-sm w-100" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Ticket
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, .card-header, .form-control, .form-select { display: none !important; }
    .col-lg-4 { display: none !important; }
    .col-lg-8 { width: 100% !important; }
}
</style>
