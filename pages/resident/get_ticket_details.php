<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotResident();

if (!isset($_GET['ticket_id'])) {
    exit('Invalid ticket ID');
}

$ticket_id = intval($_GET['ticket_id']);
$current_user_id = $_SESSION['user_id'];

// Get Ticket Info 
$stmt = $pdo->prepare("
    SELECT 
        st.*, 
        dt.doc_name AS document_name,
        dr.request_status AS request_status,
        CONCAT(u.first_name, ' ', u.surname) AS resident_name
    FROM support_tickets st
    LEFT JOIN document_requests dr ON st.request_id = dr.request_id
    LEFT JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    JOIN users u ON st.user_id = u.user_id
    WHERE st.ticket_id = ? AND st.user_id = ?
");
$stmt->execute([$ticket_id, $current_user_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    exit('Ticket not found or access denied');
}

// Get Receivers 
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

// ✅ Get Chat Messages
$stmt = $pdo->prepare("
    SELECT 
        cm.message_id,
        cm.user_id,
        cm.message,
        u.first_name,
        u.surname,
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
?>

<div class="row" style="height: 80vh;">
    <div class="col-lg-8 mb-4 h-100 d-flex flex-column">
        <div class="card flex-grow-1 d-flex flex-column position-relative" style="background-color: #f5f5f5;">
            <div class="card-header bg-white border-bottom" style="padding: 15px;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($ticket['ticket_subject'] ?? 'Support Ticket'); ?></h6>
                        <small class="text-muted">Ticket #<?php echo $ticket['ticket_id']; ?></small>
                    </div>
                    <span class="badge bg-<?php echo $statusColor; ?> fs-6">
                        <?php echo ucfirst(str_replace('_',' ',$ticket['ticket_status'])); ?>
                    </span>
                </div>
            </div>

            <div class="card-body flex-grow-1 overflow-auto" id="chat-body" style="padding: 20px; background-color: #f5f5f5;">
                <div id="chat-messages">
                    <p class="text-center text-muted">Loading messages...</p>
                </div>
            </div>

            <!-- Message Box -->
            <?php if ($ticket['ticket_status'] !== 'closed'): ?>
                <div class="card-footer bg-white p-3 border-top">
                    <form id="chat-form" class="d-flex gap-2 align-items-end">
                        <input type="hidden" id="ticket-id" value="<?php echo $ticket['ticket_id']; ?>">
                        <textarea id="message-input" class="form-control" rows="2" 
                                  placeholder="Type a message..." style="resize: none; border-radius: 20px;" required></textarea>
                        <button type="submit" class="btn btn-primary rounded-circle" id="send-btn" 
                                style="width: 45px; height: 45px; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="card-footer bg-light text-center text-muted border-top" style="padding: 15px;">
                    <i class="bi bi-lock-fill me-2"></i>This ticket has been closed. No further responses can be added.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT COLUMN: Ticket Details -->
    <div class="col-lg-4 mb-4 h-100">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Ticket Details</h5>
            </div>
            <div class="card-body overflow-auto" style="max-height: 100%;">
                <div class="mb-3">
                    <label class="text-muted small mb-1">TICKET NUMBER</label>
                    <p class="fw-bold">#<?php echo $ticket['ticket_id']; ?></p>
                </div>

                <div class="mb-3">
                    <label class="text-muted small mb-1">STATUS</label>
                    <p><span class="badge bg-<?php echo $statusColor; ?> fs-6"><?php echo ucfirst(str_replace('_',' ',$ticket['ticket_status'])); ?></span></p>
                </div>

                <div class="mb-3">
                    <label class="text-muted small mb-1">SUBJECT</label>
                    <p class="fw-semibold"><?php echo htmlspecialchars($ticket['ticket_subject'] ?? 'N/A'); ?></p>
                </div>

                <div class="mb-3">
                    <label class="text-muted small mb-1">DESCRIPTION</label>
                    <p style="white-space: pre-wrap;"><?php echo htmlspecialchars($ticket['ticket_description'] ?? 'N/A'); ?></p>
                </div>

                <div class="mb-3">
                    <label class="text-muted small mb-1">SUBMITTED BY</label>
                    <p><?php echo htmlspecialchars($ticket['resident_name']); ?></p>
                </div>

                <div class="mb-3">
                    <label class="text-muted small mb-1">CREATED</label>
                    <p><?php echo date('F j, Y g:i A', strtotime($ticket['ticket_created_at'])); ?></p>
                </div>

                <?php if ($ticket['document_name']): ?>
                    <hr>
                    <div class="mb-3">
                        <label class="text-muted small mb-1">RELATED REQUEST</label>
                        <p>#<?php echo $ticket['request_id']; ?> - <?php echo htmlspecialchars($ticket['document_name']); ?></p>
                    </div>
                    <div class="mb-3">
                        <label class="text-muted small mb-1">REQUEST STATUS</label>
                        <p><span class="badge bg-info"><?php echo ucfirst($ticket['request_status']); ?></span></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($receivers)): ?>
                    <hr>
                    <div class="mb-2">
                        <label class="text-muted small mb-2">ASSIGNED STAFF</label>
                        <?php foreach ($receivers as $receiver): ?>
                            <div class="d-flex align-items-center mb-2">
                                <div class="rounded-circle overflow-hidden border me-2" 
                                     style="width: 35px; height: 35px;">
                                    <img src="<?php echo getProfileImageSrc($receiver['profile_picture']); ?>" 
                                         alt="Profile" class="w-100 h-100" style="object-fit: cover;">
                                </div>
                                <div>
                                    <div class="fw-semibold" style="font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($receiver['full_name']); ?>
                                    </div>
                                    <small class="text-muted" style="font-size: 0.75rem;">
                                        <?php echo htmlspecialchars(ucfirst($receiver['role'])); ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
#chat-messages {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 10px 0;
}

.message-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    max-width: 75%;
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.message-item.sent {
    margin-left: auto;
    flex-direction: row-reverse;
}

.message-item.received {
    margin-right: auto;
}

.message-avatar {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    flex-shrink: 0;
}

.message-content {
    display: flex;
    flex-direction: column;
    max-width: 100%;
}

.message-item.sent .message-content {
    align-items: flex-end;
}

.message-item.received .message-content {
    align-items: flex-start;
}

.message-name {
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 3px;
    color: #555;
    padding: 0 5px;
}

.message-bubble {
    padding: 10px 14px;
    border-radius: 18px;
    word-wrap: break-word;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    position: relative;
}

.message-item.sent .message-bubble {
    background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%);
    color: white;
    border-bottom-right-radius: 4px;
}

.message-item.received .message-bubble {
    background-color: white;
    color: #333;
    border: 1px solid #e0e0e0;
    border-bottom-left-radius: 4px;
}

.message-time {
    font-size: 0.7rem;
    color: #888;
    margin-top: 3px;
    padding: 0 5px;
}

.message-item.sent .message-time {
    color: #666;
}

.message-system {
    text-align: center;
    margin: 15px auto;
    max-width: 80%;
}

.message-system .system-bubble {
    display: inline-block;
    background-color: #e3f2fd;
    color: #1976d2;
    padding: 8px 16px;
    border-radius: 12px;
    font-size: 0.85rem;
    border: 1px solid #bbdefb;
}

#chat-body::-webkit-scrollbar {
    width: 6px;
}

#chat-body::-webkit-scrollbar-track {
    background: #f1f1f1;
}

#chat-body::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

#chat-body::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

@media print {
    .btn, .card-header, .form-control, .form-select, .card-footer { display: none !important; }
    .col-lg-4 { display: none !important; }
    .col-lg-8 { width: 100% !important; }
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
        if (isLoadingMessages) return;
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
                console.log('Messages loaded:', data); // Debug log
                if (data.success) {
                    const chatContainer = document.getElementById('chat-messages');
                    const isFirstLoad = lastMessageId === 0;
                    
                    if (isFirstLoad) {
                        chatContainer.innerHTML = '';
                    }
                    
                    if (data.messages && data.messages.length > 0) {
                        data.messages.forEach(msg => {
                            if (msg.message_id > lastMessageId) {
                                appendMessage(msg);
                                lastMessageId = msg.message_id;
                            }
                        });
                        
                        // Scroll to bottom on new messages
                        scrollToBottom();
                    } else if (isFirstLoad) {
                        chatContainer.innerHTML = '<p class="text-center text-muted py-4">No messages yet. Start the conversation!</p>';
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
    function appendMessage(msg) {
        const chatContainer = document.getElementById('chat-messages');
        if (!chatContainer) {
            console.error('Chat container not found');
            return;
        }
        
        const isSent = msg.user_id == currentUserId;
        
        const messageDiv = document.createElement('div');
        messageDiv.className = `message-item ${isSent ? 'sent' : 'received'}`;
        
        // Create avatar img
        const avatarImg = document.createElement('img');
        avatarImg.src = msg.profile_picture || '/Project_A2/assets/images/default-avatar.png';
        avatarImg.alt = 'Avatar';
        avatarImg.className = 'message-avatar';
        
        // Create content div
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        
        // Create name div
        const nameDiv = document.createElement('div');
        nameDiv.className = 'message-name';
        nameDiv.textContent = msg.sender_name || 'Unknown';
        
        // Create bubble div
        const bubbleDiv = document.createElement('div');
        bubbleDiv.className = 'message-bubble';
        bubbleDiv.innerHTML = escapeHtml(msg.message || '').replace(/\n/g, '<br>');
        
        // Create time div
        const timeDiv = document.createElement('div');
        timeDiv.className = 'message-time';
        timeDiv.textContent = msg.time_ago || '';
        
        // Assemble
        contentDiv.appendChild(nameDiv);
        contentDiv.appendChild(bubbleDiv);
        contentDiv.appendChild(timeDiv);
        
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
        
        console.log('Chat form submitted'); // Debug
        
        const messageInput = document.getElementById('message-input');
        const message = messageInput.value.trim();
        
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
        
        console.log('Sending message to server...'); // Debug
        
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
            console.log('Send response:', data); // Debug log
            if (data.success) {
                messageInput.value = '';
                messageInput.focus();
                // Immediately load new messages
                setTimeout(() => loadMessages(), 100);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Failed to Send',
                    text: data.error || 'Failed to send message'
                });
            }
        })
        .catch(error => {
            console.error('Error sending message:', error);
            Swal.fire({
                icon: 'error',
                title: 'Connection Error',
                text: 'Failed to send message. Please check your connection.'
            });
        })
        .finally(() => {
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="bi bi-send-fill"></i>';
        });
        
        return false;
    });
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

    // Initial load with a small delay to ensure DOM is ready
    setTimeout(() => {
        if (ticketId) {
            console.log('Starting initial message load...');
            loadMessages();
        } else {
            console.error('Cannot load messages: ticketId is not set');
        }
    }, 100);
})();
</script>