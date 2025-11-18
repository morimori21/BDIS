<?php
require_once __DIR__ . '/config.php';

if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT brgy_logo FROM address_config WHERE address_id = ?");
    $stmt->execute([$_GET['id']]);
    $row = $stmt->fetch();

    if ($row && !empty($row['brgy_logo'])) {
        header("Content-Type: image/png"); // or image/jpeg depending on what you store
        echo $row['brgy_logo'];
        exit;
    }
}

// fallback default image
header("Content-Type: image/png");
readfile(__DIR__ . '/assets/images/default_logo.png');
?>
