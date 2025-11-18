
<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('secretary');



if (isset($_POST['update_status'])) {

    $ticket_id = intval($_POST['ticket_id']);
    $new_status = $_POST['new_status'];

    $allowed = ['open', 'in-progress', 'resolved', 'closed'];
    if (!in_array($new_status, $allowed)) {
        die("Invalid status");
    }

    $stmt = $pdo->prepare("
        UPDATE support_tickets 
        SET ticket_status = ?
        WHERE ticket_id = ?
    ");
    $stmt->execute([$new_status, $ticket_id]);

    header("Location: view_ticket.php?ticket_id=" . $ticket_id);
    exit;
}

$ticket_id = intval($_GET['ticket_id']);
$current_user_id = $_SESSION['user_id'];

// Get Ticket Info
$stmt = $pdo->prepare("
    SELECT 
        st.ticket_id,
        st.user_id,
        st.request_id,
        st.ticket_subject,
        st.ticket_description,
        st.ticket_status,
        st.ticket_created_at,
        dt.doc_name AS document_name,
        dr.request_status AS request_status,
        CONCAT(u.first_name, ' ', u.surname) AS resident_name,
        ROW_NUMBER() OVER (ORDER BY st.ticket_id ASC) AS ticket_number
    FROM support_tickets st
    LEFT JOIN document_requests dr ON st.request_id = dr.request_id
    LEFT JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    JOIN users u ON st.user_id = u.user_id
    WHERE st.ticket_id = ?
");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    header('Location: support_tickets.php');
    exit;
}


// MARK ALL MESSAGES AS READ WHEN TICKET IS OPENED
$markReadStmt = $pdo->prepare("
    UPDATE chat_messages 
    SET message_is_read = 1 
    WHERE ticket_id = ? 
      AND user_id != ?
");
$markReadStmt->execute([$ticket_id, $current_user_id]);

// Get all participants (resident + secretary)
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

function getProfileImageSrc($blobOrPath) {
    if (empty($blobOrPath)) {
        return '/Project_A2/assets/images/default-avatar.png';
    }

    if (is_string($blobOrPath) && strpos($blobOrPath, "\0") !== false) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $blobOrPath);
        finfo_close($finfo);
        return 'data:' . $mime . ';base64,' . base64_encode($blobOrPath);
    }
    return '/Project_A2/uploads/' . htmlspecialchars($blobOrPath);
}

$statusColors = [
    'open' => 'primary',
    'in-progress' => 'info',
    'resolved' => 'success',
    'closed' => 'secondary'
];
$statusColor = $statusColors[$ticket['ticket_status']] ?? 'secondary';

include 'header.php';
?>

<div class="container-fluid" style="padding: 0 20px;">
    <!-- Back Button -->
    <div class="mb-4">
        <a href="support_tickets.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back to Tickets
        </a>
    </div>

    <div class="row" style="min-height: 70vh;">
       <div class="col-lg-8 mb-4 h-100 d-flex flex-column">
    <!-- Chat Section -->
    <div class="card flex-grow-1 d-flex flex-column position-relative" style="background-color: #f5f5f5; height: 80vh;">
        <!-- Chat Header with Ticket Subject -->
        <div class="card-header bg-white border-bottom" style="padding: 15px; flex-shrink: 0;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($ticket['ticket_subject'] ?? 'Support Ticket'); ?></h6>
                    <small class="text-muted">Ticket #<?php echo $ticket['ticket_number']; ?></small>
                </div>
                <span class="badge bg-<?php echo $statusColor; ?> fs-6">
                    <?php echo ucfirst(str_replace('_',' ',$ticket['ticket_status'])); ?>
                </span>
            </div>
        </div>

        <!-- Chat Body - Takes remaining space -->
        <div class="card-body overflow-auto" id="chat-body" style="padding: 20px; background-color: #f5f5f5; flex: 1; min-height: 0;">
            <div id="chat-messages">
                <!-- Messages will be loaded here via AJAX -->
                <?php if ($ticket['ticket_status'] !== 'closed'): ?>
                    <p class="text-center text-muted">Loading messages...</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Message Box or Closed Notice -->
        <?php if ($ticket['ticket_status'] !== 'closed'): ?>
            <div class="card-footer bg-white border-top p-3" id="chat-footer" style="flex-shrink: 0;">
                <form id="chat-form" class="d-flex align-items-end gap-2">
                    <input type="hidden" id="ticket-id" value="<?php echo $ticket['ticket_id']; ?>">

                    <div id="message-input"
                         class="form-control auto-grow"
                         contenteditable="true"
                         data-placeholder="Type a message..."></div>

                    <button type="submit" class="btn btn-primary rounded-circle" id="send-btn"
                            style="width:45px;height:45px;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="card-footer bg-light text-center text-muted border-top" style="padding: 15px; flex-shrink: 0;">
                <i class="bi bi-lock-fill me-2"></i>This ticket has been closed. No further responses can be added.
            </div>
        <?php endif; ?>
    </div>
</div>

        <!-- RIGHT COLUMN: Ticket Details -->
<div class="col-lg-4 mb-4 h-100">
    <div class="card h-100">
        <div class="card-header bg-primary text-white py-2">
            <h6 class="mb-0"><i class="bi bi-info-circle me-1"></i>Ticket Details</h6>
        </div>
        <div class="card-body overflow-auto" style="max-height: 90vh; padding-bottom: 20px;">

            <div class="mb-2">
                <label class="text-muted small mb-1">STATUS</label>
                <p><span class="badge bg-<?php echo $statusColor; ?> small"><?php echo ucfirst(str_replace('_',' ',$ticket['ticket_status'])); ?></span></p>
            </div>

            <div class="mb-2">
                <label class="text-muted small mb-1">DESCRIPTION</label>
                <p class="small" style="white-space: pre-wrap;"><?php echo htmlspecialchars($ticket['ticket_description'] ?? 'N/A'); ?></p>
            </div>

            <div class="mb-2">
                <label class="text-muted small mb-1">SUBMITTED BY</label>
                <p class="small"><?php echo htmlspecialchars($ticket['resident_name']); ?></p>
            </div>

            <div class="mb-2">
                <label class="text-muted small mb-1">CREATED</label>
                <p class="small"><?php echo date('M j, Y g:i A', strtotime($ticket['ticket_created_at'])); ?></p>
            </div>

            <?php if ($ticket['document_name']): ?>
                <hr class="my-2">
                <div class="mb-2">
                    <label class="text-muted small mb-1">RELATED REQUEST</label>
                    <p class="small">#<?php echo $ticket['request_id']; ?> - <?php echo htmlspecialchars($ticket['document_name']); ?></p>
                </div>
                <div class="mb-2">
                    <label class="text-muted small mb-1">REQUEST STATUS</label>
                    <p><span class="badge bg-info small"><?php echo ucfirst($ticket['request_status']); ?></span></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($receivers)): ?>
                <hr class="my-2">
                <div class="mb-2">
                    <label class="text-muted small mb-1">CONVERSATION WITH</label>
                    <?php foreach ($receivers as $receiver): ?>
                        <div class="d-flex align-items-center mb-1">
                            <div class="rounded-circle overflow-hidden border me-2" 
                                 style="width: 30px; height: 30px;">
                                <img src="<?php echo getProfileImageSrc($receiver['profile_picture']); ?>" 
                                     alt="Profile" class="w-100 h-100" style="object-fit: cover;">
                            </div>
                            <div>
                                <div class="fw-semibold small">
                                    <?php echo htmlspecialchars($receiver['full_name']); ?>
                                </div>
                                <small class="text-muted" style="font-size: 0.7rem;">
                                    <?php echo htmlspecialchars(ucfirst($receiver['role'])); ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Status Update Controls for Secretary -->
            <?php if ($ticket['ticket_status'] !== 'closed'): ?>
                <hr class="my-2">
                <div class="mb-2">
                    <label class="text-muted small mb-1">UPDATE STATUS</label>
                    <form method="POST" action="view_ticket.php?ticket_id=<?php echo $ticket['ticket_id']; ?>">
                        <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">
                        <select name="new_status" class="form-select form-select-sm mb-1" required>
                            <option value="">-- Select Status --</option>
                            <option value="open" <?php echo $ticket['ticket_status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in-progress" <?php echo $ticket['ticket_status'] === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $ticket['ticket_status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="closed" <?php echo $ticket['ticket_status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                        <button type="submit" name="update_status" class="btn btn-sm btn-primary w-100 mt-1">
                            <i class="bi bi-check-circle me-1"></i> Update Status
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Chat Container Structure */
.col-lg-8 .card {
    height: 80vh;
    max-height: 80vh;
}

#chat-body {
    flex: 1;
    min-height: 0;
}

#chat-messages {
    display: flex;
    flex-direction: column;
    gap: 15px;
    width: 100%;
    min-height: min-content;
}

/* Message Items */
.message-item {
    display: flex;
    gap: 10px;
    animation: fadeIn 0.3s ease-in;
    width: 100%;
}

.message-item.sent {
    flex-direction: row-reverse;
}

/* Message Avatar */
.message-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    flex-shrink: 0;
}

/* Message Content */
.message-content {
    max-width: 75%;
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.message-item.sent .message-content {
    align-items: flex-end;
}

.message-name {
    font-size: 0.75rem;
    font-weight: 600;
    color: #6c757d;
    margin-bottom: 4px;
    padding: 0 12px;
}

/* Message Bubble */
.message-bubble {
    padding: 12px 16px;
    border-radius: 18px;
    word-wrap: break-word;
    word-break: break-word;
    line-height: 1.4;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    max-width: 100%;
    overflow-wrap: break-word;
}

.message-item.sent .message-bubble {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-bottom-right-radius: 4px;
}

.message-item.received .message-bubble {
    background: white;
    color: #333;
    border: 1px solid #e9ecef;
    border-bottom-left-radius: 4px;
}

.message-time {
    font-size: 0.7rem;
    color: #999;
    margin-top: 4px;
    padding: 0 12px;
    white-space: nowrap;
}

/* Message Input */
.auto-grow {
    min-height: 40px;
    max-height: 200px;
    overflow-y: auto;
    border-radius: 20px;
    padding: 10px 14px;
    transition: height 0.1s ease;
    border: 1px solid #ced4da;
    background: white;
    flex: 1;
    resize: none;
    overflow-x: hidden;
    white-space: pre-wrap;
    word-wrap: break-word;
    outline: none;
    user-select: text;
    -webkit-user-select: text;
    -moz-user-select: text;
    -ms-user-select: text;
}

.auto-grow:focus {
    outline: none !important;
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.auto-grow::-webkit-resizer,
.auto-grow::-webkit-scrollbar-corner {
    display: none !important;
}

.auto-grow:empty::before {
    content: attr(data-placeholder);
    color: #6c757d;
    pointer-events: none;
}

/* Chat Footer */
#chat-footer {
    position: sticky;
    bottom: 0;
    background: #fff;
    z-index: 10;
    border-top: 1px solid #e9ecef;
}

/* Scrollbars */
#chat-body {
    scrollbar-width: thin;
    scrollbar-color: #888 #f1f1f1;
}

#chat-body::-webkit-scrollbar {
    width: 8px;
}

#chat-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

#chat-body::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 10px;
}

#chat-body::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Animations */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive */
@media (max-width: 768px) {
    .message-content {
        max-width: 85%;
    }
    
    .message-bubble {
        padding: 10px 14px;
    }
}


.message-read-status {
    text-align: right;
    padding: 2px 12px 0;
    font-size: 0.7rem;
}

.message-item.received .message-read-status {
    text-align: left;
}


.message-status {
    padding: 2px 12px 0;
    font-size: 0.65rem;
    margin-top: 2px;
}

.message-item.sent .message-status {
    text-align: right;
}

.message-item.received .message-status {
    text-align: left;
}

/* Style for different status states */
.message-status .text-primary {
    color: #667eea !important;
    font-weight: 500;
}

.message-status .text-muted {
    color: #6c757d !important;
}
</style>
<script>
(function() {
    console.log('Chat script initializing...');
    
    const ticketIdElement = document.getElementById('ticket-id');
    if (!ticketIdElement) {
        console.error('Ticket ID element not found');
        return;
    }
    const ticketId = ticketIdElement ? ticketIdElement.value : 0;
    const currentUserId = <?php echo $_SESSION['user_id']; ?>;
    let lastMessageId = 0;
    let isLoadingMessages = false;

    console.log('Ticket ID:', ticketId, 'Current User:', currentUserId);

    // Load messages
  function loadMessages() {
    // Don't load messages if ticket is closed and we've already loaded them
    if (isLoadingMessages) return;
    
    // Optional: Stop auto-refresh for closed tickets after first load
    // Auto-refresh messages every 3 seconds (only for open tickets)
const isClosed = <?php echo $ticket['ticket_status'] === 'closed' ? 'true' : 'false'; ?>;
if (!isClosed) {
    setInterval(loadMessages, 3000);
} else {
    // For closed tickets, just load once
    setTimeout(() => {
        if (ticketId) {
            console.log('Loading messages for closed ticket...');
            loadMessages();
        }
    }, 100);
}
    
    isLoadingMessages = true;
    
    console.log('Loading messages for ticket:', ticketId);
    
    fetch(`fetch_chat_messages.php?ticket_id=${ticketId}&last_id=${lastMessageId}`)
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        console.log('Messages loaded:', data);
        if (data.success) {
            const chatContainer = document.getElementById('chat-messages');
            const isFirstLoad = lastMessageId === 0;
            
            if (isFirstLoad) {
                chatContainer.innerHTML = ''; // Clear loading message
            }
            
            if (data.messages && data.messages.length > 0) {
                data.messages.forEach(msg => {
                    if (msg.message_id > lastMessageId) {
                        appendMessage(msg);
                        lastMessageId = msg.message_id;
                    }
                });
                
                scrollToBottom();
            } else if (isFirstLoad) {
                // Show "no messages" only if there are truly no messages
                chatContainer.innerHTML = '<p class="text-center text-muted py-4">No messages yet.</p>';
            }
        } else {
            console.error('Error from server:', data.error);
            if (lastMessageId === 0) {
                document.getElementById('chat-messages').innerHTML = '<p class="text-center text-danger">Error loading messages. Please refresh.</p>';
            }
        }
        isLoadingMessages = false;
    })
    .catch(error => {
        console.error('Error loading messages:', error);
        if (lastMessageId === 0) {
            document.getElementById('chat-messages').innerHTML = '<p class="text-center text-danger">Error loading messages. Please refresh.</p>';
        }
        isLoadingMessages = false;
    });
}

    // Append message to chat
    // Append message to chat
function appendMessage(msg) {
    const chatContainer = document.getElementById('chat-messages');
    if (!chatContainer) {
        console.error('Chat container not found');
        return;
    }
    
    const isSent = msg.user_id == currentUserId;
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `message-item ${isSent ? 'sent' : 'received'}`;
    
    const avatarImg = document.createElement('img');
    avatarImg.src = msg.profile_picture || '/Project_A2/assets/images/default-avatar.png';
    avatarImg.alt = 'Avatar';
    avatarImg.className = 'message-avatar';
    
    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';
    
    const nameDiv = document.createElement('div');
    nameDiv.className = 'message-name';
    nameDiv.textContent = msg.sender_name || 'Unknown';
    
    const bubbleDiv = document.createElement('div');
    bubbleDiv.className = 'message-bubble';
    bubbleDiv.innerHTML = escapeHtml(msg.message || '').replace(/\n/g, '<br>');
    
    const timeDiv = document.createElement('div');
    timeDiv.className = 'message-time';
    timeDiv.textContent = msg.time_ago || '';
    
    // Add read status for all messages
    const statusDiv = document.createElement('div');
    statusDiv.className = 'message-status';
    
    if (isSent) {
        // For sent messages: show check symbols based on read status
        if (msg.message_is_read == 1) {
            statusDiv.innerHTML = '<small class="text-primary"><i class="bi bi-check2-all"></i> Read</small>';
        } else {
            statusDiv.innerHTML = '<small class="text-muted"><i class="bi bi-check2"></i> Sent</small>';
        }
    } else {
        // For received messages: show read/unread status
        if (msg.message_is_read == 1) {
            statusDiv.innerHTML = '<small class="text-muted"><i class="bi bi-eye"></i> Read</small>';
        } else {
            statusDiv.innerHTML = '<small class="text-primary"><i class="bi bi-clock"></i> Unread</small>';
        }
    }
    
    contentDiv.appendChild(nameDiv);
    contentDiv.appendChild(bubbleDiv);
    contentDiv.appendChild(timeDiv);
    contentDiv.appendChild(statusDiv); // Add status after time
    
    messageDiv.appendChild(avatarImg);
    messageDiv.appendChild(contentDiv);
    
    chatContainer.appendChild(messageDiv);
}

    // Send message
    const chatForm = document.getElementById('chat-form');
    if (chatForm) {
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('Chat form submitted');
            
            const messageInput = document.getElementById('message-input');
            const message = messageInput.innerText.trim();
            
            if (!message) {
                console.log('Empty message');
                return false;
            }
            
            const sendBtn = document.getElementById('send-btn');
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            
            const formData = new FormData();
            formData.append('ticket_id', ticketId);
            formData.append('message', message);
            
            console.log('Sending message to server...');
            
            fetch('send_chat_message.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                console.log('Send response:', data);
                if (data.success) {
                    messageInput.innerText = '';
                    messageInput.focus();
                    messageInput.style.height = 'auto';
                    messageInput.style.minHeight = '40px';

                    // AUTO-UPDATE: Refresh page to show updated status
                    setTimeout(() => {
                        location.reload();
                    }, 500);
                    
                } else {
                    alert(data.error || 'Failed to send message');
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                alert('Failed to send message. Please check your connection.');
            })
            .finally(() => {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="bi bi-send-fill"></i>';
            });
            
            return false;
        });
        
        // Add Shift+Enter for new lines, Enter to send
        const messageInput = document.getElementById('message-input');
        if (messageInput) {
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    if (e.shiftKey) {
                        // Shift+Enter: Insert new line
                        document.execCommand('insertLineBreak');
                        e.preventDefault();
                    } else {
                        // Enter: Send message
                        e.preventDefault();
                        chatForm.dispatchEvent(new Event('submit'));
                    }
                }
            });
            
            // Auto-grow functionality
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 200) + 'px';
            });

            // Prevent any browser-specific resize behaviors
            messageInput.style.resize = 'none';
            messageInput.style.overflow = 'hidden';
        }
    }
    else {
        console.log('Chat form not found');
    }

    // Scroll to bottom
    function scrollToBottom() {
        const chatBody = document.getElementById('chat-body');
        if (chatBody) {
            chatBody.scrollTop = chatBody.scrollHeight;
        }
    }

    // Escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    // Auto-refresh messages every 3 seconds
    setInterval(loadMessages, 3000);

    // Initial load
    setTimeout(() => {
        if (ticketId) {
            console.log('Starting initial message load...');
            loadMessages();
        } else {
            console.error('Cannot load messages: ticketId is not set');
        }
    }, 100);
})();

function markMessagesAsRead() {
    const formData = new FormData();
    formData.append('ticket_id', ticketId);
    
    fetch('mark_messages_read.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('Messages marked as read:', data);
    })
    .catch(error => {
        console.error('Error marking messages as read:', error);
    });
}

// Call this function when the page loads
setTimeout(() => {
    markMessagesAsRead();
}, 500);
</script>

<?php include 'footer.php'; ?>
