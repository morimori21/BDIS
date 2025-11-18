<?php
// Load config and enforce access BEFORE any output
require_once '../../includes/config.php';
require_once __DIR__ . '/../../includes/email_config.php';
redirectIfNotLoggedIn();
if (!defined('PROFILE_ALLOW_ALL_RESIDENT_LIKE') || PROFILE_ALLOW_ALL_RESIDENT_LIKE !== true) {
    redirectIfNotResident();
}
?>

<?php
// Fetch current user
/* Obsolete block kept for reference
$stmt = $pdo->prepare("
    SELECT 
        user_id,
        first_name,
        middle_name,
        surname,
        suffix,
        email,
        street,
        contact_number,
        birthdate,
        sex,
        status,
        remarks,
        date_registered,
        profile_picture
           u.street,
           u.contact_number,
           u.birthdate,
           u.sex,
           u.status,
           u.remarks,
           u.date_registered,
           u.profile_picture,
           e.email AS email
        FROM users u
        LEFT JOIN account a ON a.user_id = u.user_id
        LEFT JOIN email e ON e.email_id = a.email_id
        WHERE u.user_id = ?
    WHERE user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
*/
// Fetch current user
 $stmt = $pdo->prepare("
    SELECT
        u.user_id,
        u.first_name,
        u.middle_name,
        u.surname,
        u.suffix,
        u.street,
        u.contact_number,
        u.birthdate,
        u.sex,
        u.status,
        u.remarks,
        u.date_registered,
        u.profile_picture,
        e.email AS email
    FROM users u
    LEFT JOIN account a ON a.user_id = u.user_id
    LEFT JOIN email e ON e.email_id = a.email_id
    WHERE u.user_id = ?
");
 
 $stmt->execute([$_SESSION['user_id']]);
 $user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch account linkage (email_id, password_id) for OTP validations/updates
$acctStmt = $pdo->prepare("SELECT email_id, password_id FROM account WHERE user_id = ? LIMIT 1");
$acctStmt->execute([$_SESSION['user_id']]);
$account = $acctStmt->fetch(PDO::FETCH_ASSOC) ?: ['email_id' => null, 'password_id' => null];

// Helpers for flash messages
function flash($key, $default = null) {
    $val = $_SESSION[$key] ?? $default;
    unset($_SESSION[$key]);
    return $val;
}

// Helper: title-case for display without mutating stored data
if (!function_exists('bd_title')) {
    function bd_title($s) {
        if ($s === null) return '';
        return mb_convert_case(trim((string)$s), MB_CASE_TITLE, 'UTF-8');
    }
}

// Compose full address for summary card: Street + Barangay + Municipality + Province
$__addr = getBarangayDetails();
$__addrParts = [
    trim((string)($user['street'] ?? '')),
    trim((string)($__addr['brgy_name'] ?? '')),
    trim((string)($__addr['municipality'] ?? '')),
    trim((string)($__addr['province'] ?? ''))
];
$__fullAddress = trim(implode(' ', array_filter($__addrParts)));

// Compute display variants used in summary
$__role = getUserRole();
$__displayStatus = (in_array($__role, ['secretary','admin'], true))
    ? 'Verified'
    : (string)($user['status'] ?? 'pending');
$__contactMasked = mask_phone($user['contact_number'] ?? '');
if ($__contactMasked === '' && !empty($user['contact_number'])) {
    $__contactMasked = (string)$user['contact_number'];
}

// Fallback display for Street: show user's street or municipality if empty
$__displayStreet = trim((string)($user['street'] ?? ''));
if ($__displayStreet === '') {
    $__displayStreet = trim((string)($__addr['municipality'] ?? ''));
}

// Normalize birthdate for HTML date input (YYYY-MM-DD)
$birthRaw = $user['birthdate'] ?? '';
$birthdateDisplay = '';
if (!empty($birthRaw)) {
    $ts = strtotime($birthRaw);
    if ($ts !== false) {
        $birthdateDisplay = date('Y-m-d', $ts);
    } else {
        // Try DateTime parsing
        try { $dt = new DateTime($birthRaw); $birthdateDisplay = $dt->format('Y-m-d'); } catch (Throwable $e) { $birthdateDisplay = ''; }
    }
}

// Normalize sex values to 'Male' or 'Female' for display
$sexRaw = trim((string)($user['sex'] ?? ''));
$sexNorm = '';
if ($sexRaw !== '') {
    $s = strtolower($sexRaw);
    if ($s === 'm' || $s === 'male') { $sexNorm = 'Male'; }
    elseif ($s === 'f' || $s === 'female') { $sexNorm = 'Female'; }
    else { $sexNorm = ucfirst($s); }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Start email change: verify current email + password, check availability, send OTP to NEW email
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'email_change_start') {
        header('Content-Type: application/json');
        if (!$account['email_id'] || !$account['password_id']) { echo json_encode(['ok'=>false,'message'=>'Account linkage missing.']); exit; }
        $current_email_input = trim($_POST['current_email'] ?? '');
        $new_email = trim($_POST['new_email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (strcasecmp($current_email_input, ($user['email'] ?? '')) !== 0) { echo json_encode(['ok'=>false,'message'=>'Incorrect current email.']); exit; }
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'message'=>'Enter a valid new email.']); exit; }
        if (strcasecmp($new_email, $user['email'] ?? '') === 0) { echo json_encode(['ok'=>false,'message'=>'New email must be different.']); exit; }
        // Verify password
        $pw = $pdo->prepare('SELECT passkey FROM password WHERE password_id = ? LIMIT 1');
        $pw->execute([$account['password_id']]);
        $row = $pw->fetch();
        if (!$row || !password_verify($password, $row['passkey'])) { echo json_encode(['ok'=>false,'message'=>'Incorrect password.']); exit; }
        // Ensure new email not taken
        $chk = $pdo->prepare('SELECT COUNT(*) FROM email WHERE email = ?');
        $chk->execute([$new_email]);
        if ($chk->fetchColumn() > 0) { echo json_encode(['ok'=>false,'message'=>'That email is already registered.']); exit; }
        // Generate OTP and store tied to current email_id, but send to NEW email
        $otp = generateOTP();
        $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
        $pdo->prepare('INSERT INTO email_verifications (email_id, otp_code, otp_expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))')->execute([$account['email_id'], $otp]);
        $_SESSION['pending_new_email'] = $new_email;
        $toName = trim(($user['first_name'] ?? 'Resident') . ' ' . ($user['surname'] ?? ''));
        sendOTPEmail($new_email, $toName, $otp, 'BDIS Email Change Verification', 'To confirm your email change, please use the code below:');
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Resend OTP to the NEW email for email change
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'email_change_resend') {
        header('Content-Type: application/json');
        if (!$account['email_id']) { echo json_encode(['ok'=>false,'message'=>'Account linkage missing.']); exit; }
        $new_email = $_SESSION['pending_new_email'] ?? '';
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'message'=>'No pending email to verify.']); exit; }
        $otp = generateOTP();
        $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
        $pdo->prepare('INSERT INTO email_verifications (email_id, otp_code, otp_expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))')->execute([$account['email_id'], $otp]);
        $toName = trim(($user['first_name'] ?? 'Resident') . ' ' . ($user['surname'] ?? ''));
        sendOTPEmail($new_email, $toName, $otp, 'BDIS Email Change Verification', 'To confirm your email change, please use the code below:');
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Confirm email change with OTP sent to NEW email; then send revert link to OLD email
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'email_change_confirm') {
        header('Content-Type: application/json');
        if (!$account['email_id']) { echo json_encode(['ok'=>false,'message'=>'Account linkage missing.']); exit; }
        $otp = trim($_POST['otp'] ?? '');
        $new_email = $_SESSION['pending_new_email'] ?? '';
        if (!preg_match('/^\d{6}$/', $otp)) { echo json_encode(['ok'=>false,'message'=>'Invalid code.']); exit; }
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'message'=>'No pending email to verify.']); exit; }
        $sel = $pdo->prepare('SELECT 1 FROM email_verifications WHERE email_id = ? AND otp_code = ? AND otp_expires_at > NOW()');
        $sel->execute([$account['email_id'], $otp]);
        if (!$sel->fetch()) { echo json_encode(['ok'=>false,'message'=>'Invalid or expired code.']); exit; }
        // Update email to new address
        $upd = $pdo->prepare('UPDATE email SET email = ? WHERE email_id = ?');
        $ok = $upd->execute([$new_email, $account['email_id']]);
        if ($ok) {
            $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
            // Prepare revert token table
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS email_revert_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    email_id INT NOT NULL,
                    old_email VARCHAR(255) NOT NULL,
                    new_email VARCHAR(255) NOT NULL,
                    token VARCHAR(128) NOT NULL,
                    created_at DATETIME NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Throwable $e) { /* ignore */ }
            $token = bin2hex(random_bytes(32));
            $ins = $pdo->prepare('INSERT INTO email_revert_tokens (user_id, email_id, old_email, new_email, token, created_at, expires_at) VALUES (?,?,?,?,?,NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))');
            $ins->execute([$_SESSION['user_id'], $account['email_id'], $user['email'] ?? '', $new_email, $token]);
            // Send revert mail to OLD email
            $base = rtrim((isset($_SERVER['HTTP_HOST']) ? ( ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== "off") ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] ) : ''), '/');
            $link = $base . '/Project_A2/revert_email.php?token=' . urlencode($token);
            $toName = trim(($user['first_name'] ?? 'Resident') . ' ' . ($user['surname'] ?? ''));
            $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mailer->isSMTP();
                $mailer->Host = SMTP_HOST; $mailer->SMTPAuth=true; $mailer->Username=SMTP_USERNAME; $mailer->Password=SMTP_PASSWORD; $mailer->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; $mailer->Port=SMTP_PORT;
                $mailer->setFrom(FROM_EMAIL, FROM_NAME);
                $mailer->addAddress($user['email'] ?? '', $toName);
                $mailer->isHTML(true);
                $mailer->Subject = 'BDIS Email Changed';
                $mailer->Body = '<p>Hello ' . htmlspecialchars($toName) . ',</p>' .
                    '<p>Your BDIS account email was changed to <strong>' . htmlspecialchars($new_email) . '</strong>.</p>' .
                    '<p>If this was not you, click the link below to revert immediately and then change your password:</p>' .
                    '<p><a href="' . htmlspecialchars($link) . '">Revert email change</a></p>' .
                    '<p>If you did this, you can ignore this message.</p>';
                $mailer->AltBody = "Your BDIS email was changed to $new_email. If this wasn't you, open: $link";
                $mailer->send();
            } catch (Throwable $e) { /* ignore send errors */ }
            unset($_SESSION['pending_new_email']);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'message'=>'Failed to update email.']);
        }
        exit;
    }
    // AJAX: Upload/Update profile picture from avatar click
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'avatar_upload') {
        header('Content-Type: application/json');
        try {
            if (!isset($_FILES['profile_picture']) || empty($_FILES['profile_picture']['name'])) {
                echo json_encode(['ok' => false, 'message' => 'No file uploaded.']);
                exit;
            }
            if (!is_uploaded_file($_FILES['profile_picture']['tmp_name'])) {
                echo json_encode(['ok' => false, 'message' => 'Invalid upload.']);
                exit;
            }
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            $type = mime_content_type($_FILES['profile_picture']['tmp_name']);
            if (!isset($allowed[$type])) {
                echo json_encode(['ok' => false, 'message' => 'Only JPG and PNG are allowed.']);
                exit;
            }
            $ext = $allowed[$type];
            $filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
            $dest = __DIR__ . '/../../uploads/' . $filename;
            if (!move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dest)) {
                echo json_encode(['ok' => false, 'message' => 'Failed to save file.']);
                exit;
            }
            // Persist on user record
            $upd = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
            $ok = $upd->execute([$filename, $_SESSION['user_id']]);
            if (!$ok) {
                echo json_encode(['ok' => false, 'message' => 'Failed to update profile.']);
                exit;
            }
            $url = '/Project_A2/uploads/' . $filename . '?v=' . time();
            echo json_encode(['ok' => true, 'url' => $url]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    // AJAX: Start password change (verify current password, send OTP)
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'password_start') {
        header('Content-Type: application/json');
        if (!$account['email_id'] || !$account['password_id']) { echo json_encode(['ok'=>false,'message'=>'Account linkage missing.']); exit; }
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (strlen($new) < 8 || $new !== $confirm) { echo json_encode(['ok'=>false,'message'=>'Passwords must match and be at least 8 characters.']); exit; }
        $pw = $pdo->prepare('SELECT passkey FROM password WHERE password_id = ? LIMIT 1');
        $pw->execute([$account['password_id']]);
        $row = $pw->fetch();
        if (!$row || !password_verify($current, $row['passkey'])) { echo json_encode(['ok'=>false,'message'=>'Incorrect current password.']); exit; }
        // Generate and send OTP (compute expiry inside DB for timezone consistency)
        $otp = generateOTP();
        $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
        $stmtIns = $pdo->prepare('INSERT INTO email_verifications (email_id, otp_code, otp_expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
        $stmtIns->execute([$account['email_id'], $otp]);
        $toName = trim(($user['first_name'] ?? 'Resident') . ' ' . ($user['surname'] ?? ''));
        sendOTPEmail($user['email'] ?? '', $toName, $otp, 'BDIS Password Change Verification', 'To confirm your password change, please use the code below:');
        echo json_encode(['ok'=>true]);
        exit;
    }

    // AJAX: Resend OTP for password change
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'password_resend') {
        header('Content-Type: application/json');
        if (!$account['email_id']) { echo json_encode(['ok'=>false,'message'=>'Account linkage missing.']); exit; }
        $otp = generateOTP();
        $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
        $stmtIns = $pdo->prepare('INSERT INTO email_verifications (email_id, otp_code, otp_expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
        $stmtIns->execute([$account['email_id'], $otp]);
        $toName = trim(($user['first_name'] ?? 'Resident') . ' ' . ($user['surname'] ?? ''));
        sendOTPEmail($user['email'] ?? '', $toName, $otp, 'BDIS Password Change Verification', 'To confirm your password change, please use the code below:');
        echo json_encode(['ok'=>true]);
        exit;
    }

    // AJAX: Confirm password change with OTP and update passkey
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'password_confirm') {
        header('Content-Type: application/json');
        if (!$account['email_id'] || !$account['password_id']) { echo json_encode(['ok'=>false,'message'=>'Account linkage missing.']); exit; }
        $otp = $_POST['otp'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if (strlen($new) < 8) { echo json_encode(['ok'=>false,'message'=>'Password too short.']); exit; }
        $sel = $pdo->prepare('SELECT 1 FROM email_verifications WHERE email_id = ? AND otp_code = ? AND otp_expires_at > NOW()');
        $sel->execute([$account['email_id'], $otp]);
        if (!$sel->fetch()) { echo json_encode(['ok'=>false,'message'=>'Invalid or expired code.']); exit; }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $ok = $pdo->prepare('UPDATE password SET passkey = ? WHERE password_id = ?')->execute([$hash, $account['password_id']]);
        if ($ok) {
            $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'message'=>'Failed to update password.']);
        }
        exit;
    }
    // Update basic editable fields only (street, contact, profile picture)
    if (isset($_POST['update_profile'])) {
        // Sanitize inputs
        $street = trim((string)($_POST['street'] ?? ''));
        $street = mb_substr($street, 0, 255, 'UTF-8');
        $contact_number = trim((string)($_POST['contact_number'] ?? ''));
        // Keep only digits for DB consistency; allow leading + by storing digits only
        $contact_number_digits = preg_replace('/\D+/', '', $contact_number);
        if ($contact_number_digits !== '' && strlen($contact_number_digits) > 20) {
            $contact_number_digits = substr($contact_number_digits, 0, 20);
        }

        $newProfilePic = $user['profile_picture'];
        try {
            if (!empty($_FILES['profile_picture']['name']) && is_uploaded_file($_FILES['profile_picture']['tmp_name'])) {
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
                $type = mime_content_type($_FILES['profile_picture']['tmp_name']);
                if (isset($allowed[$type])) {
                    $ext = $allowed[$type];
                    $filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
                    $dest = __DIR__ . '/../../uploads/' . $filename;
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dest)) {
                        $newProfilePic = $filename;
                    }
                }
            }

            $upd = $pdo->prepare("UPDATE users SET street = ?, contact_number = ?, profile_picture = ? WHERE user_id = ?");
            $ok = $upd->execute([$street, $contact_number_digits, $newProfilePic, $_SESSION['user_id']]);

            $_SESSION['profile_' . ($ok ? 'updated' : 'error')] = true;
            if (!$ok) {
                $_SESSION['profile_error_msg'] = 'Database update failed.';
            }
        } catch (Throwable $e) {
            $_SESSION['profile_error'] = true;
            $_SESSION['profile_error_msg'] = $e->getMessage();
        }
        header('Location: profile.php');
        exit;
    }

    // Change email with password confirmation (modal flow)
    if (isset($_POST['change_email_pw'])) {
        $current_email_input = trim($_POST['current_email'] ?? '');
        $new_email = trim($_POST['new_email'] ?? '');
        $confirm_email = trim($_POST['confirm_email'] ?? '');
        $password = $_POST['confirm_password'] ?? '';

        if (!$account['email_id'] || !$account['password_id']) {
            $_SESSION['email_change_error'] = 'Account linkage missing.';
            header('Location: profile.php'); exit;
        }
        if (strcasecmp($current_email_input, ($user['email'] ?? '')) !== 0) {
            $_SESSION['email_change_error'] = 'Current email does not match our records.';
            header('Location: profile.php'); exit;
        }
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL) || strcasecmp($new_email, $confirm_email) !== 0) {
            $_SESSION['email_change_error'] = 'Please enter a valid and matching email.';
            header('Location: profile.php'); exit;
        }
        if (strcasecmp($new_email, $user['email'] ?? '') === 0) {
            $_SESSION['email_change_error'] = 'New email must be different from current email.';
            header('Location: profile.php'); exit;
        }
        // Verify password
        $pw = $pdo->prepare('SELECT passkey FROM password WHERE password_id = ? LIMIT 1');
        $pw->execute([$account['password_id']]);
        $row = $pw->fetch();
        if (!$row || !password_verify($password, $row['passkey'])) {
            $_SESSION['email_change_error'] = 'Incorrect password.';
            header('Location: profile.php'); exit;
        }
        // Ensure email is not taken by someone else
        $chk = $pdo->prepare('SELECT COUNT(*) FROM email WHERE email = ? AND email_id <> ?');
        $chk->execute([$new_email, $account['email_id']]);
        if ($chk->fetchColumn() > 0) {
            $_SESSION['email_change_error'] = 'That email is already in use.';
            header('Location: profile.php'); exit;
        }
        // Update email
        $upd = $pdo->prepare('UPDATE email SET email = ? WHERE email_id = ?');
        $ok = $upd->execute([$new_email, $account['email_id']]);
        if ($ok) {
            $_SESSION['email_change_success'] = true;
        } else {
            $_SESSION['email_change_error'] = 'Failed to update email.';
        }
        header('Location: profile.php'); exit;
    }

    // Request: send OTP to current email to confirm email change
    if (isset($_POST['send_email_otp'])) {
        $new_email = trim($_POST['new_email'] ?? '');
        $confirm_email = trim($_POST['confirm_new_email'] ?? '');

        if (!$account['email_id']) { $_SESSION['profile_error'] = true; header('Location: profile.php'); exit; }
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL) || $new_email !== $confirm_email) {
            $_SESSION['email_change_error'] = 'Please provide a valid and matching new email.';
            header('Location: profile.php'); exit;
        }
        if (strcasecmp($new_email, $user['email'] ?? '') === 0) {
            $_SESSION['email_change_error'] = 'New email must be different from current email.';
            header('Location: profile.php'); exit;
        }
        // Ensure email not already taken
        $chk = $pdo->prepare('SELECT COUNT(*) FROM email WHERE email = ?');
        $chk->execute([$new_email]);
        if ($chk->fetchColumn() > 0) {
            $_SESSION['email_change_error'] = 'That email is already registered.';
            header('Location: profile.php'); exit;
        }

        // Generate OTP and store for this email_id (DB computes expiry to avoid timezone drift)
        $otp = generateOTP();
        $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
        $ins = $pdo->prepare('INSERT INTO email_verifications (email_id, otp_code, otp_expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
        $ins->execute([$account['email_id'], $otp]);

        // Send to current email on record
        $toName = trim(($user['first_name'] ?? 'Resident') . ' ' . ($user['surname'] ?? ''));
        sendOTPEmail($user['email'] ?? '', $toName, $otp, 'BDIS Email Change Verification', 'To confirm your email change, please use the code below:');

        $_SESSION['email_change_sent'] = true;
        $_SESSION['pending_new_email'] = $new_email;
        header('Location: profile.php'); exit;
    }

    // Confirm email change with OTP
    if (isset($_POST['confirm_email_change'])) {
        $otp = trim($_POST['email_otp'] ?? '');
        $new_email_confirm = trim($_POST['new_email_confirm'] ?? '');
        if (!$account['email_id']) { $_SESSION['profile_error'] = true; header('Location: profile.php'); exit; }
        if (empty($_SESSION['pending_new_email']) || strcasecmp($_SESSION['pending_new_email'], $new_email_confirm) !== 0) {
            $_SESSION['email_change_error'] = 'New email confirmation mismatch.';
            header('Location: profile.php'); exit;
        }
        $sel = $pdo->prepare('SELECT 1 FROM email_verifications WHERE email_id = ? AND otp_code = ? AND otp_expires_at > NOW()');
        $sel->execute([$account['email_id'], $otp]);
        if (!$sel->fetch()) {
            $_SESSION['email_change_error'] = 'Invalid or expired verification code.';
            header('Location: profile.php'); exit;
        }
        // Update email to the new address
        $upd = $pdo->prepare('UPDATE email SET email = ? WHERE email_id = ?');
        $ok = $upd->execute([$_SESSION['pending_new_email'], $account['email_id']]);
        if ($ok) {
            $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
            unset($_SESSION['pending_new_email']);
            $_SESSION['email_change_success'] = true;
        } else {
            $_SESSION['email_change_error'] = 'Failed to update email.';
        }
        header('Location: profile.php'); exit;
    }

    // Request: send OTP to current email for password change
    if (isset($_POST['send_password_otp'])) {
        if (!$account['email_id'] || !$account['password_id']) { $_SESSION['profile_error'] = true; header('Location: profile.php'); exit; }
        $otp = generateOTP();
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
        $pdo->prepare('INSERT INTO email_verifications (email_id, otp_code, otp_expires_at) VALUES (?,?,?)')->execute([$account['email_id'], $otp, $expires]);
        $toName = trim(($user['first_name'] ?? 'Resident') . ' ' . ($user['surname'] ?? ''));
        sendOTPEmail($user['email'] ?? '', $toName, $otp, 'BDIS Password Change Verification', 'To confirm your password change, please use the code below:');
        $_SESSION['password_change_sent'] = true;
        header('Location: profile.php'); exit;
    }

    // Confirm password change with OTP
    if (isset($_POST['confirm_password_change'])) {
        if (!$account['email_id'] || !$account['password_id']) { $_SESSION['profile_error'] = true; header('Location: profile.php'); exit; }
        $otp = trim($_POST['password_otp'] ?? '');
        $new_pass = $_POST['new_password'] ?? '';
        $conf_pass = $_POST['confirm_new_password'] ?? '';
        if ($new_pass !== $conf_pass || strlen($new_pass) < 8) {
            $_SESSION['password_change_error'] = 'Passwords must match and be at least 8 characters.';
            header('Location: profile.php'); exit;
        }
        $sel = $pdo->prepare('SELECT 1 FROM email_verifications WHERE email_id = ? AND otp_code = ? AND otp_expires_at > NOW()');
        $sel->execute([$account['email_id'], $otp]);
        if (!$sel->fetch()) {
            $_SESSION['password_change_error'] = 'Invalid or expired verification code.';
            header('Location: profile.php'); exit;
        }
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $ok = $pdo->prepare('UPDATE password SET passkey = ? WHERE password_id = ?')->execute([$hash, $account['password_id']]);
        if ($ok) {
            $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
            $_SESSION['password_change_success'] = true;
        } else {
            $_SESSION['password_change_error'] = 'Failed to update password.';
        }
        header('Location: profile.php'); exit;
    }
}
?>
<?php
// Use role-specific header when provided by wrapper; otherwise default to resident header
include defined('PROFILE_HEADER_PATH') ? PROFILE_HEADER_PATH : __DIR__ . '/header.php';
?>

<?php if ($msg = flash('email_change_success')): ?>
<script>
window.addEventListener('DOMContentLoaded', function(){
    if (window.Swal) {
        Swal.fire({ icon: 'success', title: 'Email updated', text: 'Your email was changed successfully.', timer: 1800, showConfirmButton: false });
    }
});
</script>
<?php endif; ?>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Profile</h2>
    </div>

    <?php if (!empty($_SESSION['profile_updated'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Profile updated successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['profile_updated']); endif; ?>

    <?php if (!empty($_SESSION['profile_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            Unable to update profile. Please try again.
            <?php if (!empty($_SESSION['profile_error_msg'])): ?>
                <div class="small mt-2">Details: <?php echo htmlspecialchars((string)$_SESSION['profile_error_msg']); ?></div>
                <?php unset($_SESSION['profile_error_msg']); ?>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['profile_error']); endif; ?>

    <div class="row g-4">
        <!-- Summary -->
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-center mb-3">
                        <input type="file" id="avatarFile" name="profile_picture" accept="image/jpeg,image/png" class="d-none">
                        <input type="file" id="avatarCameraFile" accept="image/*" capture="user" class="d-none">
                        <div id="avatarClickArea" class="d-inline-block position-relative" style="cursor: pointer;" title="Click to change photo">
                            <?php if (!empty($user['profile_picture'])): ?>
                                <img id="avatarImg" src="/Project_A2/uploads/<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile" class="rounded-circle" style="width: 140px; height: 140px; object-fit: cover;">
                            <?php else: ?>
                                <div id="avatarPlaceholder" class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center" style="width: 140px; height: 140px; color: #6c757d; font-size: 18px; border: 1px solid #dee2e6;">
                                    No Photo
                                </div>
                            <?php endif; ?>
                            <span class="position-absolute" style="right: -2px; bottom: -2px; width: 28px; height: 28px; background: #0d6efd; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 2px #fff;">
                                <i class="bi bi-camera-fill" style="color: #fff; font-size: 14px;"></i>
                            </span>
                        </div>
                    </div>
                    <h5 class="text-center mb-1"><?php echo htmlspecialchars(trim(bd_title($user['first_name'] ?? '') . ' ' . bd_title($user['middle_name'] ?? '') . ' ' . bd_title($user['surname'] ?? ''))); ?></h5>
                    <div class="d-flex justify-content-center align-items-center gap-2 mb-3">
                        <span class="text-muted"><?php echo htmlspecialchars(mask_email($user['email'] ?? '')); ?></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#changeEmailModal" title="Edit email">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </div>

                    <div class="small">
                        <div class="row align-items-start py-2 border-top">
                            <div class="col-5">
                                <span>Contact Number</span>
                            </div>
                            <div class="col-7 text-end">
                                <span class="text-muted"><?php echo htmlspecialchars($__contactMasked); ?></span>
                            </div>
                        </div>
                        <div class="row align-items-start py-2">
                            <div class="col-5">
                                <span>Address</span>
                            </div>
                            <div class="col-7">
                                <span class="text-muted d-block"><?php echo htmlspecialchars($__fullAddress ?: 'N/A'); ?></span>
                            </div>
                        </div>
                        <div class="row align-items-start py-2 border-bottom">
                            <div class="col-5">
                                <span>Status</span>
                            </div>
                            <div class="col-7 text-end">
                                <span class="text-muted text-capitalize"><?php echo htmlspecialchars($__displayStatus); ?></span>
                            </div>
                        </div>
                        <div class="pt-3">
                            <label class="form-label small mb-1">Change Password</label>
                            <div>
                                <button type="button" class="btn btn-outline-secondary w-100" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                    Change Password
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Form -->
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header bg-light">
                    <strong>Profile Details</strong>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="profileEditForm">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars(bd_title($user['first_name'] ?? '')); ?>" disabled>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name" value="<?php echo htmlspecialchars(bd_title($user['middle_name'] ?? '')); ?>" disabled>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="surname" value="<?php echo htmlspecialchars(bd_title($user['surname'] ?? '')); ?>" disabled>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Suffix</label>
                                <input type="text" class="form-control" name="suffix" value="<?php echo htmlspecialchars(bd_title($user['suffix'] ?? '')); ?>" disabled>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Birthdate</label>
                                <input type="date" class="form-control" name="birthdate" value="<?php echo htmlspecialchars($birthdateDisplay); ?>" disabled>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sex</label>
                                <select class="form-select" name="sex" disabled>
                                    <?php $sexVal = $sexNorm; ?>
                                    <option value="">Select</option>
                                    <option value="Male" <?php echo ($sexVal === 'Male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($sexVal === 'Female') ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label">Street</label>
                                <input type="text" class="form-control" id="streetInput" name="street" value="<?php echo htmlspecialchars($__displayStreet); ?>" readonly data-original="<?php echo htmlspecialchars($__displayStreet); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Contact Number</label>
                                <input type="text" class="form-control" id="contactInput" name="contact_number" value="<?php echo htmlspecialchars($__contactMasked); ?>" readonly data-raw="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>" data-masked="<?php echo htmlspecialchars($__contactMasked); ?>">
                            </div>

                            
                        </div>

                        <div class="d-flex justify-content-end mt-4 gap-2">
                            <button type="button" class="btn btn-outline-secondary" id="cancelEditBtn" style="display:none;">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="saveChangesBtn" style="display:none;">Save</button>
                            <button type="button" class="btn btn-primary" id="editProfileBtn">Edit</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="changePasswordModalLabel">Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="changePasswordForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" minlength="8" required autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" name="confirm_password" minlength="8" required autocomplete="new-password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Change Email Modal -->
<div class="modal fade" id="changeEmailModal" tabindex="-1" aria-labelledby="changeEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="changeEmailModalLabel">Change Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="changeEmailForm" data-current-email="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                <input type="hidden" name="change_email_pw" value="1">
                <input type="hidden" name="confirm_password" id="emailConfirmPassword">
                <div class="modal-body">
                    <?php if ($msg = flash('email_change_success')): ?><div class="alert alert-success mb-3">Email updated successfully.</div><?php endif; ?>
                    <?php if ($msg = flash('email_change_error')): ?><div class="alert alert-danger mb-3"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Current Email</label>
                        <input type="email" class="form-control" name="current_email" placeholder="Enter current email" required>
                        <div class="form-text">On record: <?php echo htmlspecialchars(mask_email($user['email'] ?? '')); ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Email</label>
                        <input type="email" class="form-control" name="new_email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Email</label>
                        <input type="email" class="form-control" name="confirm_email" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveEmailBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('changeEmailForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const form = this;
    const expected = (form.dataset.currentEmail || '').trim().toLowerCase();
    const typedCurrent = (form.querySelector('input[name="current_email"]')?.value || '').trim().toLowerCase();
    const newEmail = (form.querySelector('input[name="new_email"]')?.value || '').trim();
    const confEmail = (form.querySelector('input[name="confirm_email"]')?.value || '').trim();
    if (!typedCurrent || typedCurrent !== expected) {
        await Swal.fire({ icon: 'error', title: 'Incorrect email', text: 'The current email you entered does not match.' });
        return;
    }
    if (!newEmail || newEmail.toLowerCase() !== confEmail.toLowerCase()) {
        await Swal.fire({ icon: 'error', title: 'Check new email', text: 'New email and confirm email must match.' });
        return;
    }
    // Hide underlying Bootstrap modal to avoid focus trap blocking typing
    const modalEl = document.getElementById('changeEmailModal');
    const modalInstance = window.bootstrap?.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl);
    modalInstance.hide();

    const { value: password } = await Swal.fire({
        title: 'Confirm Email Change',
        text: 'Enter your password to continue.',
        input: 'password',
        inputAttributes: { autocapitalize: 'off', autocomplete: 'current-password' },
        showCancelButton: true,
        confirmButtonText: 'Confirm'
    });
    if (!password) { modalInstance.show(); return; }
    // Start email change (send OTP to NEW email)
    const startRes = await fetch('profile.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({ ajax:'email_change_start', current_email: typedCurrent, new_email: newEmail, password }).toString() });
    const start = await startRes.json().catch(()=>({}));
    if (!start || !start.ok) { await Swal.fire({ icon:'error', title:'Cannot start change', text:(start && start.message) || 'Please try again.' }); return; }

    // OTP prompt loop
    while (true) {
        const result = await Swal.fire({
            title: 'Enter OTP',
            text: 'We sent a 6-digit code to your new email.',
            input: 'text',
            inputAttributes: { maxlength: 6, pattern: '\\d{6}', inputmode: 'numeric' },
            showDenyButton: true,
            denyButtonText: 'Resend OTP',
            showCancelButton: true,
            confirmButtonText: 'Verify',
            preConfirm: (val) => val && /^\d{6}$/.test(val) ? val : Swal.showValidationMessage('Please enter a valid 6-digit code')
        });
        if (result.isDenied) {
            await fetch('profile.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({ ajax:'email_change_resend' }).toString() });
            await Swal.fire({ icon:'info', title:'Code resent', timer:1200, showConfirmButton:false });
            continue;
        }
        if (!result.isConfirmed) return;
        const confRes = await fetch('profile.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({ ajax:'email_change_confirm', otp: result.value }).toString() });
        const conf = await confRes.json().catch(()=>({}));
        if (conf && conf.ok) {
            await Swal.fire({ icon:'success', title:'Email updated', text:'We sent a security notice to your old email.', timer:1800, showConfirmButton:false });
            location.reload();
            return;
        } else {
            await Swal.fire({ icon:'error', title:'Invalid/expired code', text:(conf && conf.message) || 'Please try again.' });
        }
    }
});

// Change Password Flow with OTP + Resend
const pwForm = document.getElementById('changePasswordForm');
async function postAjax(params) {
    const res = await fetch('profile.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams(params).toString()
    });
    return res.json();
}

async function promptForOtpAndConfirm(newPassword) {
    while (true) {
        const result = await Swal.fire({
            title: 'Enter OTP',
            text: 'We sent a 6-digit code to your email.',
            input: 'text',
            inputAttributes: { maxlength: 6, pattern: '\\d{6}', inputmode: 'numeric' },
            showDenyButton: true,
            denyButtonText: 'Resend OTP',
            showCancelButton: true,
            confirmButtonText: 'Verify',
            preConfirm: (val) => val && /^\d{6}$/.test(val) ? val : Swal.showValidationMessage('Please enter a valid 6-digit code')
        });
        if (result.isDenied) {
            await postAjax({ ajax: 'password_resend' });
            await Swal.fire({ icon: 'info', title: 'Code resent', timer: 1200, showConfirmButton: false });
            continue;
        }
        if (!result.isConfirmed) return false; // cancelled
        const resp = await postAjax({ ajax: 'password_confirm', otp: result.value, new_password: newPassword });
        if (resp && resp.ok) {
            await Swal.fire({ icon: 'success', title: 'Password changed successfully', timer: 1800, showConfirmButton: false });
            location.reload();
            return true;
        } else {
            await Swal.fire({ icon: 'error', title: 'Invalid/expired code', text: (resp && resp.message) || 'Please try again.' });
        }
    }
}

pwForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const modalEl = document.getElementById('changePasswordModal');
    const modalInstance = window.bootstrap?.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl);
    const fd = new FormData(pwForm);
    const current_password = fd.get('current_password');
    const new_password = fd.get('new_password');
    const confirm_password = fd.get('confirm_password');
    if (!new_password || new_password.length < 8 || new_password !== confirm_password) {
        Swal.fire({ icon: 'error', title: 'Check your password', text: 'Passwords must match and be at least 8 characters.' });
        return;
    }
    modalInstance.hide();
    const start = await postAjax({ ajax: 'password_start', current_password, new_password, confirm_password });
    if (start && start.ok) {
        await promptForOtpAndConfirm(new_password);
    } else {
        await Swal.fire({ icon: 'error', title: 'Cannot start change', text: (start && start.message) || 'Please check your current password and try again.' });
        modalInstance.show();
    }
});
</script>

<script>
// Avatar click-to-upload (with Camera option on mobile)
(function(){
    const fileInput = document.getElementById('avatarFile');
    const camInput = document.getElementById('avatarCameraFile');
    const clickArea = document.getElementById('avatarClickArea');
    const imgEl = document.getElementById('avatarImg');
    const placeholder = document.getElementById('avatarPlaceholder');
    if (!fileInput || !clickArea) return;
    async function uploadSelected(file) {
        if (!file) return;
        if (!/^image\/(jpeg|png)$/.test(file.type)) {
            Swal.fire({ icon: 'error', title: 'Invalid file', text: 'Please choose a JPG or PNG image.' });
            return;
        }
        const fd = new FormData();
        fd.append('ajax', 'avatar_upload');
        fd.append('profile_picture', file);
        try {
            const res = await fetch('profile.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data && data.ok) {
                if (imgEl) {
                    imgEl.src = data.url;
                } else if (placeholder) {
                    const img = document.createElement('img');
                    img.id = 'avatarImg';
                    img.className = 'rounded-circle';
                    img.style.width = '140px';
                    img.style.height = '140px';
                    img.style.objectFit = 'cover';
                    img.src = data.url;
                    placeholder.replaceWith(img);
                }
                Swal.fire({ icon: 'success', title: 'Profile photo updated', timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Upload failed', text: (data && data.message) || 'Please try again.' });
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'Network error', text: 'Please try again.' });
        }
    }

    clickArea.addEventListener('click', async () => {
        // Offer camera option on devices that support it; fallback to regular file chooser
        const result = await Swal.fire({
            title: 'Change Profile Photo',
            showDenyButton: true,
            showCancelButton: true,
            confirmButtonText: 'Use Camera',
            denyButtonText: 'Choose Photo'
        });
        if (result.isConfirmed && camInput) {
            camInput.click();
        } else if (result.isDenied) {
            fileInput.click();
        }
    });

    fileInput.addEventListener('change', async () => {
        const f = fileInput.files && fileInput.files[0];
        await uploadSelected(f);
        fileInput.value = '';
    });

    camInput?.addEventListener('change', async () => {
        const f = camInput.files && camInput.files[0];
        await uploadSelected(f);
        camInput.value = '';
    });
})();
</script>

<script>
// Edit/Save/Cancel toggle for Street and Contact
(function(){
    const form = document.getElementById('profileEditForm');
    const editBtn = document.getElementById('editProfileBtn');
    const saveBtn = document.getElementById('saveChangesBtn');
    const cancelBtn = document.getElementById('cancelEditBtn');
    const street = document.getElementById('streetInput');
    const contact = document.getElementById('contactInput');
    if (!form || !editBtn || !saveBtn || !cancelBtn || !street || !contact) return;

    function enterEdit(){
        street.readOnly = false;
        contact.readOnly = false;
        // Swap masked to raw for editing
        const raw = contact.dataset.raw || '';
        if (raw) contact.value = raw;
        editBtn.style.display = 'none';
        saveBtn.style.display = '';
        cancelBtn.style.display = '';
        street.focus();
    }
    function exitEdit(reset){
        street.readOnly = true;
        contact.readOnly = true;
        if (reset){
            street.value = street.dataset.original || '';
            contact.value = contact.dataset.masked || '';
        } else {
            // keep current value but remask for display
            contact.value = contact.dataset.masked || '';
        }
        editBtn.style.display = '';
        saveBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
    }

    editBtn.addEventListener('click', enterEdit);
    cancelBtn.addEventListener('click', () => exitEdit(true));
    // Do NOT disable fields before submit; disabled inputs are not posted.
    // After redirect, fields default back to read-only state.
})();
</script>
