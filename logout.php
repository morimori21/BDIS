<?php
require_once 'includes/config.php';
logActivity($_SESSION['user_id'], 'Logged out');
session_destroy();
header('Location: /Project_A2/login.php');
exit;
?>
