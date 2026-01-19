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

// Optional return path handling (to go back to role-specific profile)
$return = isset($_GET['return']) ? (string)$_GET['return'] : '';
// Basic safety: allow only same-site relative paths
if ($return && (strpos($return, '://') !== false || strpos($return, "\n") !== false || strpos($return, "\r") !== false)) {
    $return = '';
}
if ($return && substr($return, 0, 1) !== '/') {
    $return = '';
}

// Fetch account linkage for the logged-in user
$stmt = $pdo->prepare('SELECT a.password_id FROM account a WHERE a.user_id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$account || empty($account['password_id'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Change Password</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container py-5"><div class="alert alert-danger">Account not found.</div></div></body></html>';
    exit;
}

$err = '';
$ok = false;

// Ensure OTP table exists (shared with other flows)
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // Basic complexity checks similar to forgot_password.php
    if (strlen($new) < 8) {
        $err = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $new)) {
        $err = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $new)) {
        $err = 'Password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $new)) {
        $err = 'Password must contain at least one number.';
    } elseif (!preg_match('/[^A-Za-z0-9]/', $new)) {
        $err = 'Password must contain at least one special character.';
    } elseif ($new !== $confirm) {
        $err = 'Passwords do not match.';
    } else {
        // Verify current password
        $pw = $pdo->prepare('SELECT passkey FROM password WHERE password_id = ? LIMIT 1');
        $pw->execute([$account['password_id']]);
        $row = $pw->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($current, $row['passkey'])) {
            $err = 'Incorrect current password.';
        } else {
            // Prepare OTP to confirm password change using shared email_verifications table
            ensure_email_verifications_table($pdo);
            $link = $pdo->prepare('SELECT e.email_id, e.email FROM email e JOIN account a ON e.email_id=a.email_id WHERE a.user_id = ? LIMIT 1');
            $link->execute([$_SESSION['user_id']]);
            $accEmail = $link->fetch(PDO::FETCH_ASSOC);
            if (!$accEmail) {
                $err = 'Unable to find your email to send verification code.';
            } else {
                $otp = generateOTP();
                $expiresTs = time() + 600; // 10 minutes
                $expires = date('Y-m-d H:i:s', $expiresTs);
                // Remove any previous pending OTPs for this email
                $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$accEmail['email_id']]);
                $pdo->prepare('INSERT INTO email_verifications (email_id, otp_code, otp_expires_at) VALUES (?,?,?)')
                    ->execute([$accEmail['email_id'], $otp, $expires]);
                $toName = 'User';
                // Optional: fetch user name
                try { $u = $pdo->prepare('SELECT first_name, surname FROM users WHERE user_id = ?'); $u->execute([$_SESSION['user_id']]); if ($n=$u->fetch()) { $toName = trim(($n['first_name']??'').' '.($n['surname']??'')); } } catch (Throwable $e) {}
                sendOTPEmail($accEmail['email'], $toName, $otp, 'BDIS Password Change Verification', 'To confirm your password change, please use the code below:');

                // Stash pending change in session and redirect to OTP page
                $_SESSION['cp_email_id'] = (int)$accEmail['email_id'];
                $_SESSION['cp_email'] = (string)$accEmail['email'];
                $_SESSION['cp_new_hash'] = password_hash($new, PASSWORD_DEFAULT);
                $_SESSION['cp_password_id'] = (int)$account['password_id'];
                $_SESSION['cp_pending'] = true;
                // Store OTP in session to avoid conflicts with other flows deleting records
                $_SESSION['cp_otp_code'] = $otp;
                $_SESSION['cp_otp_expires'] = $expiresTs; // unix timestamp
                // Propagate return target across OTP step
                $_SESSION['cp_return'] = $return;
                $redir = '/Project_A2/change_password_otp.php' . ($return ? ('?return=' . urlencode($return)) : '');
                header('Location: ' . $redir);
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set New Password - BDIS</title>
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
    .form-label { font-weight: 600; color: #333; margin-bottom: 5px; }
    .form-control { border-radius: 12px; border: 2px solid #e5e7eb; padding: 12px; }
    .form-control:focus { border-color: #4f46e5; box-shadow: 0 0 0 0.15rem rgba(79, 70, 229, 0.25); }
    .btn-register { background: #4f46e5; color: white; padding: 12px; border-radius: 12px; border: none; font-weight: 600; font-size: 1.1rem; width: 100%; transition: background 0.2s; }
    .btn-register:hover { background: #4338ca; }
    .password-requirements { list-style: none; padding-left: 0; margin-top: 5px; font-size: 0.9em; display: none; }
    .password-requirements li { color: #888; margin-bottom: 3px; transition: color 0.3s, opacity 0.3s; }
    .password-requirements li i { margin-right: 5px; width: 15px; text-align: center; color: #dc3545; }
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
            <h3>Set New Password</h3>
            <p>Create a new, strong password.</p>
        </div>
        <div class="register-body">
            <?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
            <form method="POST" novalidate>
                <div class="mb-3">
                    <label class="form-label">Current Password</label>
                    <input type="password" class="form-control" name="current_password" required autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input id="newPassword" type="password" class="form-control" name="new_password" required autocomplete="new-password">
                    <ul id="pwReq" class="password-requirements mt-2">
                        <li id="len"><i class="fa fa-times-circle"></i> Minimum 8 characters</li>
                        <li id="up"><i class="fa fa-times-circle"></i> At least one uppercase letter (A-Z)</li>
                        <li id="lo"><i class="fa fa-times-circle"></i> At least one lowercase letter (a-z)</li>
                        <li id="nu"><i class="fa fa-times-circle"></i> At least one number (0-9)</li>
                        <li id="sp"><i class="fa fa-times-circle"></i> At least one special character (!@#$...)</li>
                    </ul>
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control" name="confirm_password" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn-register"><i class="fa fa-floppy-disk me-2"></i>Save New Password</button>
            </form>
            <div class="text-center mt-3">
                <?php $backHref = $return ?: '/Project_A2/pages/resident/profile.php'; ?>
                <a href="<?php echo htmlspecialchars($backHref); ?>" class="text-decoration-none">&larr; Back to Profile</a>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
  const input = document.getElementById('newPassword');
  const req = document.getElementById('pwReq');
  const checks = {
    len: v => v.length >= 8,
    up: v => /[A-Z]/.test(v),
    lo: v => /[a-z]/.test(v),
    nu: v => /[0-9]/.test(v),
    sp: v => /[^A-Za-z0-9]/.test(v),
  };
    function update(){
        const v = input.value || '';
        req.style.display = v.length ? 'block' : 'none';
    Object.keys(checks).forEach(id => {
      const ok = checks[id](v);
      const li = document.getElementById(id);
      const icon = li.querySelector('i');
      li.style.color = ok ? '#28a745' : '#888';
      icon.className = ok ? 'fa fa-check-circle' : 'fa fa-times-circle';
      icon.style.color = ok ? '#28a745' : '#dc3545';
    });
  }
    input.addEventListener('input', update);
    input.addEventListener('focus', () => { if ((input.value||'').length > 0) req.style.display = 'block'; });
    input.addEventListener('blur', () => { if ((input.value||'').length === 0) req.style.display = 'none'; });
})();
</script>
</body>
</html>
