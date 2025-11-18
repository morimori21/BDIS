<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();

$ticket_id = intval($_GET['ticket_id'] ?? 0);
if (!$ticket_id) exit('');

$stmt = $pdo->prepare("
    SELECT cm.*, CONCAT(u.first_name,' ',u.surname) AS user_full_name, u.profile_picture, ur.role
    FROM chat_messages cm
    JOIN users u ON cm.sender_id = u.user_id
    LEFT JOIN user_roles ur ON u.user_id = ur.user_id
    WHERE cm.ticket_id = ?
    ORDER BY cm.sent_at ASC
");
$stmt->execute([$ticket_id]);
$responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getProfileImageSrc($img){
    if(empty($img)) return '/Project_A2/assets/images/default-avatar.png';
    return '/Project_A2/uploads/'.$img;
}

foreach($responses as $res):
    $isSecretary = in_array($res['role'], ['secretary','staff']);
    $alignClass = $isSecretary?'justify-content-start':'justify-content-end';
    $bubbleClass = $isSecretary?'bg-light text-dark':'bg-primary text-white';
    $textAlign = $isSecretary?'text-start':'text-end';
    $profileSrc = getProfileImageSrc($res['profile_picture']);
?>
<div class="d-flex mb-4 <?=$alignClass?>">
    <?php if($isSecretary): ?>
    <div class="me-2"><div class="rounded-circle overflow-hidden border border-secondary" style="width:45px;height:45px;"><img src="<?=$profileSrc?>" alt="Profile" class="w-100 h-100" style="object-fit:cover;"></div></div>
    <?php endif; ?>
    <div style="max-width:75%;" class="<?=$textAlign?>">
        <div class="fw-bold mb-1" style="font-size:.85rem;"><?=htmlspecialchars($res['user_full_name'])?></div>
        <div class="p-3 rounded-3 shadow-sm <?=$bubbleClass?>"><?=nl2br(htmlspecialchars($res['message']))?></div>
        <small class="text-muted d-block mt-1" style="font-size:.75rem;"><?=date('M j, Y g:i A',strtotime($res['sent_at']))?></small>
    </div>
    <?php if(!$isSecretary): ?>
    <div class="ms-2"><div class="rounded-circle overflow-hidden border border-secondary" style="width:45px;height:45px;"><img src="<?=$profileSrc?>" alt="Profile" class="w-100 h-100" style="object-fit:cover;"></div></div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
