<?php
require_once 'includes/config.php';

if (isLoggedIn()) {
    $role = getUserRole();
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
