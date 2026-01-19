<?php
require_once 'header.php';

$notifStmt = $pdo->prepare("
    SELECT notif_id, notif_type, notif_topic, notif_entity_id, notif_created_at, notif_is_read
    FROM notifications
    WHERE user_id = ?
    ORDER BY notif_created_at DESC
");
$notifStmt->execute([$_SESSION['user_id']]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

$unique = [];
$deduped = [];
foreach ($notifications as $n) {
    $key = ($n['notif_type'] ?? '') . '|' . ($n['notif_topic'] ?? '') . '|' . ($n['notif_entity_id'] ?? '') . '|' . date('Y-m-d H:i', strtotime($n['notif_created_at']));
    if (!isset($unique[$key])) {
        $unique[$key] = true;
        $deduped[] = $n;
    }
}
$notifications = $deduped;

$stmt = $pdo->prepare("
    SELECT first_name, middle_name, surname, profile_picture
    FROM users
    WHERE user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$headerUser = $stmt->fetch(PDO::FETCH_ASSOC);

function getNotificationUrl($notif_type, $notif_entity_id, $role) {
    switch($notif_type) {
        case 'document':
        case 'action':
            return $role === 'resident'
                ? "/Project_A2/pages/resident/request_history.php?id=" . $notif_entity_id
                : "/Project_A2/pages/secretary/document_management.php?id=" . $notif_entity_id;

        case 'chat':
            return $role === 'resident'
                ? "/Project_A2/pages/resident/view_ticket.php?ticket_id=" . $notif_entity_id
                : "/Project_A2/pages/secretary/support_tickets.php?ticket_id=" . $notif_entity_id;
        default:
            return "#";
    }
}

$unreadCount = 0;
foreach ($notifications as $n) {
    if (!$n['notif_is_read']) $unreadCount++;
}

?>

<div class="main-content expanded">
<style>
:root{--primary-color:#0d6efd;--success-color:#198754;--danger-color:#dc3545;--warning-color:#ffc107;--info-color:#0dcaf0;--light-bg:#f8f9fa;--border-color:#dee2e6;--text-muted:#6c757d;}

.notification-header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;border-radius:12px;padding:25px;margin-bottom:25px;box-shadow:0 4px 15px rgba(0,0,0,0.1);}
.notification-card{border:none;border-radius:12px;box-shadow:0 2px 20px rgba(0,0,0,0.08);overflow:hidden;margin-bottom:20px;}
.notification-item{padding:20px;border-bottom:1px solid var(--border-color);transition:all 0.3s ease;cursor:pointer;position:relative;}
.notification-item:last-child{border-bottom:none;}
.notification-item:hover{background-color:#f8f9ff;transform:translateX(5px);}
.notification-item.unread{background-color:#f0f7ff;border-left:4px solid var(--primary-color);}
.notification-item.read{background-color:white;border-left:4px solid transparent;}
.notification-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin-right:15px;flex-shrink:0;}
.notification-icon.document{background:linear-gradient(135deg,#667eea,#764ba2);}
.notification-icon.chat{background:linear-gradient(135deg,#f093fb,#f5576c);}
.notification-icon.action{background:linear-gradient(135deg,#4facfe,#00f2fe);}
.notification-icon.default{background:linear-gradient(135deg,#43e97b,#38f9d7);}
.notification-content{flex:1;}
.notification-title{font-weight:600;font-size:1rem;color:#2c3e50;margin-bottom:5px;line-height:1.4;}
.notification-time{font-size:0.85rem;color:var(--text-muted);display:flex;align-items:center;gap:5px;}
.notification-badge{font-size:0.75rem;padding:4px 8px;border-radius:20px;font-weight:500;}
.empty-state{text-align:center;padding:60px 20px;color:var(--text-muted);}
.empty-state i{font-size:4rem;margin-bottom:20px;opacity:0.5;}
.empty-state h5{margin-bottom:10px;color:var(--text-muted);}
.mark-all-btn{background:linear-gradient(135deg,#667eea,#764ba2);border:none;border-radius:8px;padding:10px 20px;color:white;font-weight:500;transition:all 0.3s ease;}
.mark-all-btn:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(102,126,234,0.4);}
.mark-all-btn:disabled{background:#6c757d;transform:none;box-shadow:none;}
.notification-indicator{width:8px;height:8px;background-color:var(--primary-color);border-radius:50%;flex-shrink:0;margin-top:8px;}
@media (max-width:768px){
.main-content{margin-top:70px;padding:15px;}
.notification-header{padding:20px;margin-bottom:20px;}
.notification-item{padding:15px;}
.notification-icon{width:40px;height:40px;margin-right:12px;}
.notification-title{font-size:0.95rem;}
}
@media (max-width:576px){
.notification-header h4{font-size:1.25rem;}
.notification-item{padding:12px;}
.d-flex.align-items-start{flex-direction:column;}
.notification-time{margin-top:8px;}
}
@keyframes pulse{0%{transform:scale(1);}50%{transform:scale(1.05);}100%{transform:scale(1);}}
.notification-item.unread{animation:pulse 2s infinite;}
.notification-item.unread:hover{animation:none;}
</style>
<div class="container-fluid py-4">  
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="row align-items-center">
                    <div class="col">
                        <h4 class="mb-0">Notifications</h4>
                    </div>

                    <?php if($unreadCount > 0): ?>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-outline-success" id="markAllBtn" onclick="markAllAsRead()">
                            <i class="bi bi-check-lg"></i> Mark All as Read
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- NOTIFICATION LIST -->
            <div class="card">
                <div class="card-body p-0">

                    <?php if(count($notifications) > 0): ?>
                        <div class="list-group list-group-flush">

                            <?php foreach($notifications as $notif): 
                                $notifUrl = getNotificationUrl($notif['notif_type'], $notif['notif_entity_id'], $role);
                            ?>

                            <div class="list-group-item px-0 py-2 border-0">
                                <div class="notif-item <?= $notif['notif_is_read'] ? '' : 'unread' ?>" onclick="handleNotificationClick(<?= $notif['notif_id'] ?>, '<?= $notifUrl ?>', this)">
                                    <div class="d-flex align-items-start justify-content-between">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-1">
                                                <strong class="me-2"><?= htmlspecialchars($notif['notif_topic']) ?></strong>
                                                <?php if(!$notif['notif_is_read']): ?>
                                                    <span class="badge bg-primary">New</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted"><i class="bi bi-clock"></i> <?= date('M d, Y \\a\\t h:i A', strtotime($notif['notif_created_at'])) ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>

                        </div>

                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-bell-slash"></i>
                            <h5>No notifications yet</h5>
                            <p class="mb-0">We'll notify you when something important happens.</p>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>
</div>

<script>
function handleNotificationClick(notifId,notifUrl,element){

fetch('/Project_A2/includes/mark_notif_read.php',
{method:'POST',headers:{'Content-Type':'application/json'},
body:JSON.stringify({notif_id:notifId})})

.then(res=>res.json())
.then(data=>{

    if(data.success){
        element.classList.remove('unread');
        const badge=element.querySelector('.badge');
            if(badge)badge.remove();
                updateUnreadCount();
            if(notifUrl&&notifUrl!=='#')
                {setTimeout(()=>{window.location.href=notifUrl;},300);}
                }
            })
.catch(error=>{
    console.error('Error:',error);
    if(notifUrl&&notifUrl!=='#'){window.location.href=notifUrl;}
    });
    }

function markAllAsRead(){
        const unreadItems=document.querySelectorAll('.notif-item.unread');
        const unreadIds=[];
             unreadItems.forEach(item=>{
        const onclickAttr=item.getAttribute('onclick');
        const match=onclickAttr.match(/handleNotificationClick\((\d+),/);
             if(match)
                {unreadIds.push(parseInt(match[1]));}
                });
        if(unreadIds.length===0)return;
                fetch('/Project_A2/includes/mark_all_notif_read.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({notif_ids:unreadIds,user_id:<?php echo $_SESSION['user_id'];?>})})
                .then(res=>res.json())
                .then(data=>{
            if(data.success){
                unreadItems.forEach(item=>{
                item.classList.remove('unread');
                const badge=item.querySelector('.badge');
                if(badge)badge.remove();
                });
                updateUnreadCount();
                const btn=document.getElementById('markAllBtn');
                if(btn){btn.style.display='none';}
                if(window.Swal){Swal.fire({icon:'success',title:'Success!',text:'All notifications marked as read',toast:true,position:'top-end',showConfirmButton:false,timer:2000,timerProgressBar:true});}
                }
                })
                .catch(error=>{console.error('Error:',error);});
                }

function updateUnreadCount(){
    const unreadCount=document.querySelectorAll('.notif-item.unread').length;
    const counterElement=null;
    const markAllBtn=document.getElementById('markAllBtn');
        if(counterElement){
        if(unreadCount>0){counterElement.textContent=`You have ${unreadCount} unread notification${unreadCount>1?'s':''}`;}
             else{counterElement.textContent='All caught up!';}
        }

        if(markAllBtn){markAllBtn.disabled=unreadCount===0;}
         }
        document.addEventListener('DOMContentLoaded',function(){

        const notificationItems=document.querySelectorAll('.notif-item');
            notificationItems.forEach(item=>{
            item.addEventListener('mouseenter',function(){this.style.transform='translateX(5px)';});
            item.addEventListener('mouseleave',function(){this.style.transform='translateX(0)';});
        });
    });
</script>
