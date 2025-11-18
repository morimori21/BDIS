<?php
require_once 'includes/config.php';

// Accept a JSON payload or raw token via POST and redirect to login with token prefilled
// Example POST body: {"token":"..."}
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST only']);
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);
$token = null;
if (is_array($data) && !empty($data['token'])) {
    $token = $data['token'];
} else {
    // maybe raw token sent
    $token = trim($body);
}

if (!$token) {
    echo json_encode(['error' => 'Token missing']);
    exit;
}

// Validate token quickly
$tstmt = $pdo->prepare("SELECT token, expires_at, used FROM qr_login_tokens WHERE token = ? LIMIT 1");
$tstmt->execute([$token]);
$row = $tstmt->fetch();
if (!$row) {
    echo json_encode(['error' => 'Invalid token']);
    exit;
}
if ($row['used'] || strtotime($row['expires_at']) < time()) {
    echo json_encode(['error' => 'Expired or used token']);
    exit;
}

// Redirect to login with token (user agent or scanner should follow redirect)
// We'll return a small HTML with auto-POST to login.php containing the token
?><!doctype html>
<html>
<head><meta charset="utf-8"><title>QR Auth</title></head>
<body>
<form id="f" method="POST" action="/Project_A2/login.php">
    <input type="hidden" name="qr_token" value="<?php echo htmlspecialchars($token); ?>">
    <noscript><button type="submit">Continue</button></noscript>
</form>
<script>document.getElementById('f').submit();</script>
</body>
</html>
