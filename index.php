<?php
require_once 'includes/config.php';

if (isLoggedIn()) {
    $stmt = $pdo->prepare("
    SELECT
        u.user_id,
        p.passkey,
        COALESCE(ur.role, 'user') AS role,
        u.status
    FROM
        users u
    JOIN
        account a ON u.user_id = a.user_id
    JOIN
        password p ON a.password_id = p.password_id
    LEFT JOIN
        user_roles ur ON u.user_id = ur.user_id
    WHERE
        u.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    $role = $user['role'] ?? 'user';
    switch ($role) {
        case 'admin':
            header('Location: /Project_A2/pages/admin/dashboard.php');
            break;
        case 'secretary':
            header('Location: /Project_A2/pages/secretary/dashboard.php');
            break;
        case 'captain':
            header('Location: /Project_A2/pages/captain/dashboard.php');
            break;
        case 'resident':
        case 'councilor':
        case 'sk_chairman':
        case 'treasurer':
            header('Location: /Project_A2/pages/resident/dashboard.php');
            break;
        default:
            header('Location: /Project_A2/login.php');
    }
    exit;
} else {
    header('Location: /Project_A2/login.php');
    exit;
}
?>
