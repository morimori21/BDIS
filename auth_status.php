<?php
require_once __DIR__ . '/includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'reason' => 'not-logged-in']);
    exit;
}

$userId = $_SESSION['user_id'];
$loginTime = $_SESSION['login_time'] ?? null; // 'Y-m-d H:i:s'
// If login_time is missing (existing session before feature rollout), initialize it now
if (!$loginTime) {
    $_SESSION['login_time'] = date('Y-m-d H:i:s');
    $loginTime = $_SESSION['login_time'];
}

try {
    // Ensure table exists (no-op if already present)
    $GLOBALS['pdo']->exec("CREATE TABLE IF NOT EXISTS user_session_flags (
        user_id INT PRIMARY KEY,
        logout_broadcast_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $GLOBALS['pdo']->prepare('SELECT logout_broadcast_at FROM user_session_flags WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $logoutAt = $row['logout_broadcast_at'] ?? null;
    $shouldLogout = false;
    if ($logoutAt && $loginTime) {
        // If a broadcast happened after this session logged in, we should logout
        $shouldLogout = (strtotime($logoutAt) > strtotime($loginTime));
    }

    echo json_encode([
        'ok' => true,
        'logout' => $shouldLogout,
        'logout_at' => $logoutAt,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => true, 'logout' => false]);
}
