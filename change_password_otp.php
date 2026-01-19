<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email_config.php';

redirectIfNotLoggedIn();

// Fetch logo watermark (same as login)
try {
    $logoStmt = $pdo->query("SELECT brgy_logo FROM address_config LIMIT 1");
    $logoData = $logoStmt->fetch(PDO::FETCH_ASSOC);
    $logoImage = !empty($logoData['brgy_logo']) ? ("data:image/png;base64," . base64_encode($logoData['brgy_logo'])) : '';
} catch (Throwable $e) { $logoImage = ''; }

// Guard: must have a pending change
if (empty($_SESSION['cp_pending']) || empty($_SESSION['cp_email_id']) || empty($_SESSION['cp_new_hash']) || empty($_SESSION['cp_password_id'])) {
    $ret = isset($_GET['return']) ? (string)$_GET['return'] : '';
    if ($ret && (strpos($ret, '://') !== false || strpos($ret, "\n") !== false || strpos($ret, "\r") !== false)) { $ret = ''; }
    if ($ret && substr($ret, 0, 1) !== '/') { $ret = ''; }
    $redir = '/Project_A2/change_password.php' . ($ret ? ('?return=' . urlencode($ret)) : '');
    header('Location: ' . $redir);
    exit;
}

$cp_email_id = (int)$_SESSION['cp_email_id'];
$user_email = (string)($_SESSION['cp_email'] ?? '');
$return = (string)($_SESSION['cp_return'] ?? ($_GET['return'] ?? ''));
if ($return && (strpos($return, '://') !== false || strpos($return, "\n") !== false || strpos($return, "\r") !== false)) { $return = ''; }
if ($return && substr($return, 0, 1) !== '/') { $return = ''; }
$err = '';

// Ensure shared email_verifications table exists
if (!function_exists('ensure_email_verifications_table')) {
    function ensure_email_verifications_table(PDO $pdo) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email_id INT NOT NULL,
                otp_code VARCHAR(16) NOT NULL,
                otp_expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (email_id),
                INDEX (otp_expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { /* ignore */ }
    }
}
ensure_email_verifications_table($pdo);

function mask_email_local($email) {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
    [$local,$domain] = explode('@',$email,2);
    $l = strlen($local);
    if ($l <= 1) { $mask = '*'; }
    elseif ($l == 2) { $mask = substr($local,0,1) . '*'; }
    else { $mask = substr($local,0,1) . str_repeat('*', $l-2) . substr($local,-1); }
    return $mask.'@'.$domain;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp_code'] ?? '');
    if (!preg_match('/^[0-9]{6}$/', $otp)) {
        $err = 'Please enter a valid 6-digit code.';
    } else {
        $valid = false;
        // Prefer session-stored OTP (avoids interference with other flows like email change)
        if (!empty($_SESSION['cp_otp_code']) && !empty($_SESSION['cp_otp_expires'])) {
            if (time() < (int)$_SESSION['cp_otp_expires'] && hash_equals($_SESSION['cp_otp_code'], $otp)) {
                $valid = true;
            }
        }
        // Fallback to shared table if session not valid
        if (!$valid) {
            $sel = $pdo->prepare('SELECT 1 FROM email_verifications WHERE email_id = ? AND otp_code = ? AND otp_expires_at > NOW()');
            $sel->execute([$cp_email_id, $otp]);
            if ($sel->fetch()) { $valid = true; }
        }
        if ($valid) {
            $upd = $pdo->prepare('UPDATE password SET passkey = ? WHERE password_id = ?');
            $ok = $upd->execute([$_SESSION['cp_new_hash'], (int)$_SESSION['cp_password_id']]);
            if ($ok) {
                $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$cp_email_id]);
                $dest = $return ?: '/Project_A2/pages/resident/profile.php';
                unset($_SESSION['cp_pending'], $_SESSION['cp_email_id'], $_SESSION['cp_new_hash'], $_SESSION['cp_password_id'], $_SESSION['cp_email'], $_SESSION['cp_otp_code'], $_SESSION['cp_otp_expires'], $_SESSION['cp_return']);
                $_SESSION['password_change_success'] = true;
                header('Location: ' . $dest);
                exit;
            } else {
                $err = 'Failed to update password.';
            }
        } else {
            $err = 'Invalid or expired code.';
        }
    }
}

// Optional resend
if (isset($_GET['resend']) && $_GET['resend'] === '1') {
    $otp = generateOTP();
    $expiresTs = time() + 600;
    $expires = date('Y-m-d H:i:s', $expiresTs);
    $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$cp_email_id]);
    $pdo->prepare('INSERT INTO email_verifications (email_id, otp_code, otp_expires_at) VALUES (?,?,?)')
        ->execute([$cp_email_id, $otp, $expires]);
    $_SESSION['cp_otp_code'] = $otp;
    $_SESSION['cp_otp_expires'] = $expiresTs;
    $toName = 'User';
    try { $u = $pdo->prepare('SELECT first_name, surname FROM users WHERE user_id = ?'); $u->execute([$_SESSION['user_id']]); if ($n=$u->fetch()) { $toName = trim(($n['first_name']??'').' '.($n['surname']??'')); } } catch (Throwable $e) {}
    sendOTPEmail($user_email, $toName, $otp, 'BDIS Password Change Verification', 'To confirm your password change, please use the code below:');
    $q = $return ? ('?return=' . urlencode($return)) : '';
    header('Location: change_password_otp.php' . $q);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Security Code - BDIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background: #ffffff !important; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px 0; background-image: none; }
    /* Fading Overlay same as login */
    body::before { content: ""; position: fixed; inset: 0; background: rgba(255,255,255,0.10); z-index: 1; pointer-events: none; }
    /* Logo watermark same as login */
    #logo-watermark { position: fixed; top: 0; left: 0; width: 100%; height: 100%; display: flex; justify-content: center; align-items: center; z-index: 0; }
    #logo-watermark img { width: 1200px; height: auto; opacity: 0.25; }
    .register-container { position: relative; z-index: 10; max-width: 430px; width: 100%; margin: 0 auto; }
    .register-card { position: relative; z-index: 10; background: #ffffff; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.12); padding: 0; border: 1px solid #e0e0e0; }
    .register-header { padding: 30px; text-align: center; background-color: #f9f9f9; border-radius: 20px 20px 0 0; border-bottom: 1px solid #e0e0e5; color: #333; }
    .register-header h3 { font-weight: 700; margin-bottom: 5px; color: #333; }
    .register-header p { font-weight: 400; color: #666; font-size: 1rem; margin: 0; }
    .register-body { padding: 30px; }
    .otp-input { text-align: center; font-size: 2rem; letter-spacing: 5px; padding: 15px; }
    .btn-register { background: #4f46e5; color: white; padding: 12px; border-radius: 12px; border: none; font-weight: 600; font-size: 1.1rem; width: 100%; transition: background 0.2s; }
    .btn-register:hover { background: #4338ca; }
    </style>
</head>
<body>
<?php if (!empty($logoImage)): ?>
    <div id="logo-watermark">
        <img src="<?php echo $logoImage; ?>" alt="Barangay Logo Watermark">
    </div>
<?php endif; ?>
<div class="register-container">
    <div class="register-card">
        <div class="register-header">
            <h3>Verify Security Code</h3>
            <p>Check your inbox for the one-time verification code.</p>
        </div>
        <div class="register-body">
            <?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
            <div class="alert alert-success">
                A 6-digit verification code has been sent to <strong><?php echo htmlspecialchars(mask_email_local($user_email)); ?></strong>.
            </div>
            <form method="POST">
                <div class="mb-4">
                    <label for="otp_code" class="form-label text-center w-100">Enter 6-digit Code <span class="text-danger">*</span></label>
                    <input class="form-control otp-input" type="text" id="otp_code" name="otp_code" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" placeholder="------" required autofocus>
                </div>
                <button type="submit" class="btn-register mb-3">
                    <i class="fas fa-check-circle me-2"></i>Verify Code
                </button>
            </form>
            <div class="text-center mt-3">
                <?php $qs = $return ? ('?resend=1&return=' . urlencode($return)) : '?resend=1'; ?>
                <a href="change_password_otp.php<?php echo $qs; ?>" class="text-decoration-none">Resend Code</a>
            </div>
            <div class="text-center mt-3">
                <?php $backHref = '/Project_A2/change_password.php' . ($return ? ('?return=' . urlencode($return)) : ''); ?>
                <a href="<?php echo htmlspecialchars($backHref); ?>" class="text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Back</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
