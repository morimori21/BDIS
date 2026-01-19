<?php
// Shared Profile View (reused by resident/admin/captain/secretary wrappers)
// Always load config and email config from includes
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email_config.php';
redirectIfNotLoggedIn();
if (!defined('PROFILE_ALLOW_ALL_RESIDENT_LIKE') || PROFILE_ALLOW_ALL_RESIDENT_LIKE !== true) {
    redirectIfNotResident();
}
 $stmt = $pdo->prepare("\n    SELECT\n        u.user_id,\n        u.first_name,\n        u.middle_name,\n        u.surname,\n        u.suffix,\n        u.street,\n        u.contact_number,\n        u.birthdate,\n        u.sex,\n        u.status,\n        u.remarks,\n        u.date_registered,\n        u.profile_picture,\n        e.email AS email\n    FROM users u\n    LEFT JOIN account a ON a.user_id = u.user_id\n    LEFT JOIN email e ON e.email_id = a.email_id\n    WHERE u.user_id = ?\n");
 
 $stmt->execute([$_SESSION['user_id']]);
 $user1 = $stmt->fetch(PDO::FETCH_ASSOC);
 $profileImage1='';
if(!empty($user1['profile_picture'])){
    // Reading BLOB data directly from DB and encoding for display
    $profileImage1 = 'data:image/png;base64,' . base64_encode($user1['profile_picture']);
}
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

// Ensure required OTP storage table exists (used across flows)
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
        } catch (Throwable $e) {
            // best-effort; actual insert/select will surface errors if this fails
        }
    }
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
    trim((string)($user1['street'] ?? '')),
    trim((string)($__addr['brgy_name'] ?? '')),
    trim((string)($__addr['municipality'] ?? '')),
    trim((string)($__addr['province'] ?? ''))
];
$__fullAddress = trim(implode(' ', array_filter($__addrParts)));

// Compute display variants used in summary
$__role = getUserRole();
$canEditProfile = in_array($__role, ['secretary','admin','captain'], true);
$residentCanEditContact = (!$canEditProfile && $__role === 'resident');
$__displayStatus = (in_array($__role, ['secretary','admin'], true))
    ? 'Verified'
    : (string)($user1['status'] ?? 'pending');
$__contactMasked = mask_phone($user1['contact_number'] ?? '');
if ($__contactMasked === '' && !empty($user1['contact_number'])) {
    $__contactMasked = (string)$user1['contact_number'];
}

// Fallback display for Street: show user's street or municipality if empty
$__displayStreet = trim((string)($user1['street'] ?? ''));
if ($__displayStreet === '') {
    $__displayStreet = trim((string)($__addr['municipality'] ?? ''));
}

// Normalize birthdate for HTML date input (YYYY-MM-DD)
$birthRaw = $user1['birthdate'] ?? '';
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
$sexRaw = trim((string)($user1['sex'] ?? ''));
$sexNorm = '';
if ($sexRaw !== '') {
    $s = strtolower($sexRaw);
    if ($s === 'm' || $s === 'male') { $sexNorm = 'Male'; }
    elseif ($s === 'f' || $s === 'female') { $sexNorm = 'Female'; }
    else { $sexNorm = ucfirst($s); }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Make sure OTP table exists before any insert/select
    ensure_email_verifications_table($pdo);
    // New Email Change Flow (two-step OTP: current email then new email)
    // Step 1: Send OTP to CURRENT email after validating current email input
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'email_change_send_current_otp') {
        header('Content-Type: application/json');
        if (!$account['email_id']) { echo json_encode(['ok'=>false,'message'=>'Account linkage missing.']); exit; }
        $current_email_input = trim($_POST['current_email'] ?? '');
        if (strcasecmp($current_email_input, ($user1['email'] ?? '')) !== 0) { echo json_encode(['ok'=>false,'message'=>'Incorrect current email.']); exit; }
        $otp = generateOTP();
        $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
        $pdo->prepare('INSERT INTO email_verifications (email_id, otp_code, otp_expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))')->execute([$account['email_id'], $otp]);
        $toName = trim(($user1['first_name'] ?? 'Resident') . ' ' . ($user1['surname'] ?? ''));
        $sent = sendOTPEmail($user1['email'] ?? '', $toName, $otp, 'BDIS Email Change Verification', 'Use this code to verify your current email:');
        echo json_encode(['ok'=>true, 'email_sent'=>(bool)$sent]);
        exit;
    }
    // Step 2: Verify OTP from CURRENT email
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'email_change_verify_current_otp') {
        header('Content-Type: application/json');
        if (!$account['email_id']) { echo json_encode(['ok'=>false,'message'=>'Account linkage missing.']); exit; }
        $otp = trim($_POST['otp'] ?? '');
        if (!preg_match('/^\d{6}$/', $otp)) { echo json_encode(['ok'=>false,'message'=>'Invalid code.']); exit; }
        $sel = $pdo->prepare('SELECT 1 FROM email_verifications WHERE email_id = ? AND otp_code = ? AND otp_expires_at > NOW()');
        $sel->execute([$account['email_id'], $otp]);
        if (!$sel->fetch()) { echo json_encode(['ok'=>false,'message'=>'Invalid or expired code.']); exit; }
        // clear used code and mark session as verified
        $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
        $_SESSION['email_change_current_verified'] = true;
        echo json_encode(['ok'=>true]);
        exit;
    }
    // Step 3: Send OTP to NEW email (after current verified); also store pending_new_email
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'email_change_send_new_otp') {
        header('Content-Type: application/json');
        if (!$account['email_id']) { echo json_encode(['ok'=>false,'message'=>'Account linkage missing.']); exit; }
        if (empty($_SESSION['email_change_current_verified'])) { echo json_encode(['ok'=>false,'message'=>'Current email not verified yet.']); exit; }
        $new_email = trim($_POST['new_email'] ?? '');
        $confirm_email = trim($_POST['confirm_email'] ?? '');
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL) || strcasecmp($new_email, $confirm_email) !== 0) { echo json_encode(['ok'=>false,'message'=>'Please provide a valid and matching new email.']); exit; }
        if (strcasecmp($new_email, $user1['email'] ?? '') === 0) { echo json_encode(['ok'=>false,'message'=>'New email must be different.']); exit; }
        $chk = $pdo->prepare('SELECT COUNT(*) FROM email WHERE email = ?');
        $chk->execute([$new_email]);
        if ($chk->fetchColumn() > 0) { echo json_encode(['ok'=>false,'message'=>'That email is already registered.']); exit; }
        $_SESSION['pending_new_email'] = $new_email;
        $otp = generateOTP();
        $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
        $pdo->prepare('INSERT INTO email_verifications (email_id, otp_code, otp_expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))')->execute([$account['email_id'], $otp]);
        $toName = trim(($user1['first_name'] ?? 'Resident') . ' ' . ($user1['surname'] ?? ''));
        $sent = sendOTPEmail($new_email, $toName, $otp, 'BDIS Email Change Verification', 'Use this code to verify your new email:');
        echo json_encode(['ok'=>true, 'email_sent'=>(bool)$sent]);
        exit;
    }
    // Step 4: Verify OTP sent to NEW email and finalize update
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'email_change_confirm_new_otp') {
        header('Content-Type: application/json');
        if (!$account['email_id']) { echo json_encode(['ok'=>false,'message'=>'Account linkage missing.']); exit; }
        if (empty($_SESSION['email_change_current_verified'])) { echo json_encode(['ok'=>false,'message'=>'Current email not verified yet.']); exit; }
        $otp = trim($_POST['otp'] ?? '');
        $new_email = $_SESSION['pending_new_email'] ?? '';
        if (!preg_match('/^\d{6}$/', $otp)) { echo json_encode(['ok'=>false,'message'=>'Invalid code.']); exit; }
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'message'=>'No pending email to verify.']); exit; }
        $sel = $pdo->prepare('SELECT 1 FROM email_verifications WHERE email_id = ? AND otp_code = ? AND otp_expires_at > NOW()');
        $sel->execute([$account['email_id'], $otp]);
        if (!$sel->fetch()) { echo json_encode(['ok'=>false,'message'=>'Invalid or expired code.']); exit; }
        $upd = $pdo->prepare('UPDATE email SET email = ? WHERE email_id = ?');
        $ok = $upd->execute([$new_email, $account['email_id']]);
        if ($ok) {
            $pdo->prepare('DELETE FROM email_verifications WHERE email_id = ?')->execute([$account['email_id']]);
            unset($_SESSION['pending_new_email'], $_SESSION['email_change_current_verified']);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'message'=>'Failed to update email.']);
        }
        exit;
    }
    // AJAX: Upload/Update profile picture from avatar click (MODIFIED FOR BLOB STORAGE)
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
            $allowedTypes = ['image/jpeg', 'image/png'];
            $fileType = mime_content_type($_FILES['profile_picture']['tmp_name']);
            if (!in_array($fileType, $allowedTypes)) {
                echo json_encode(['ok' => false, 'message' => 'Only JPG, JPEG and PNG are allowed.']);
                exit;
            }

            // Read the binary content of the uploaded file
            $blobData = file_get_contents($_FILES['profile_picture']['tmp_name']);
            if ($blobData === false) {
                echo json_encode(['ok' => false, 'message' => 'Failed to read file content.']);
                exit;
            }

            // Update the database with the binary BLOB data
            $upd = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
            // Note: PDO handles binary data correctly when prepared
            $ok = $upd->execute([$blobData, $_SESSION['user_id']]);

            if (!$ok) {
                echo json_encode(['ok' => false, 'message' => 'Failed to update profile (Database error).']);
                exit;
            }

            // The URL for the client-side must be the base64-encoded string (Data URL)
            $dataUrl = 'data:' . $fileType . ';base64,' . base64_encode($blobData);
            
            echo json_encode(['ok' => true, 'url' => $dataUrl]);

        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'message' => 'Server error: ' . $e->getMessage()]);
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
        $toName = trim(($user1['first_name'] ?? 'Resident') . ' ' . ($user1['surname'] ?? ''));
        sendOTPEmail($user1['email'] ?? '', $toName, $otp, 'BDIS Password Change Verification', 'To confirm your password change, please use the code below:');
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
        $toName = trim(($user1['first_name'] ?? 'Resident') . ' ' . ($user1['surname'] ?? ''));
        sendOTPEmail($user1['email'] ?? '', $toName, $otp, 'BDIS Password Change Verification', 'To confirm your password change, please use the code below:');
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
    // Update editable fields only for secretary/admin/captain
    if (isset($_POST['update_profile'])) {
        // If privileged roles, proceed with full edit flow
        if ($canEditProfile) {
            // Sanitize inputs (fallback to current values when not provided)
            $first_name   = mb_substr(trim((string)($_POST['first_name'] ?? ($user1['first_name'] ?? ''))), 0, 100, 'UTF-8');
            $middle_name  = mb_substr(trim((string)($_POST['middle_name'] ?? ($user1['middle_name'] ?? ''))), 0, 100, 'UTF-8');
            $surname      = mb_substr(trim((string)($_POST['surname'] ?? ($user1['surname'] ?? ''))), 0, 100, 'UTF-8');
            $suffix       = mb_substr(trim((string)($_POST['suffix'] ?? ($user1['suffix'] ?? ''))), 0, 50, 'UTF-8');

            // Birthdate normalize to YYYY-MM-DD or NULL
            $birth_in     = trim((string)($_POST['birthdate'] ?? ($user1['birthdate'] ?? '')));
            $birth_norm   = null;
            if ($birth_in !== '') {
                $ts = strtotime($birth_in);
                if ($ts !== false) { $birth_norm = date('Y-m-d', $ts); }
                else { try { $dt = new DateTime($birth_in); $birth_norm = $dt->format('Y-m-d'); } catch (Throwable $e) { $birth_norm = null; } }
            }

            // Sex normalize to Male/Female or NULL
            $sex_in = trim((string)($_POST['sex'] ?? ($user1['sex'] ?? '')));
            $sex_norm = null;
            if ($sex_in !== '') {
                $s = strtolower($sex_in);
                if ($s === 'male' || $s === 'm') $sex_norm = 'Male';
                elseif ($s === 'female' || $s === 'f') $sex_norm = 'Female';
                else $sex_norm = ucfirst($s);
            }

            $street = mb_substr(trim((string)($_POST['street'] ?? ($user1['street'] ?? ''))), 0, 255, 'UTF-8');
            $contact_number = trim((string)($_POST['contact_number'] ?? ($user1['contact_number'] ?? '')));
            $contact_number_digits = preg_replace('/\D+/', '', $contact_number);
            if ($contact_number_digits !== '' && strlen($contact_number_digits) > 20) {
                $contact_number_digits = substr($contact_number_digits, 0, 20);
            }

            // Required fields for secretary/admin/captain
            $errors = [];
            if ($first_name === '') { $errors[] = 'First name is required.'; }
            if ($surname === '') { $errors[] = 'Last name is required.'; }
            if ($birth_norm === null) { $errors[] = 'Birthdate is required.'; }
            if ($sex_norm === null || !in_array($sex_norm, ['Male','Female'], true)) { $errors[] = 'Sex is required.'; }
            if (!empty($errors)) {
                $_SESSION['profile_error'] = true;
                $_SESSION['profile_error_msg'] = implode(' ', $errors);
                header('Location: profile.php');
                exit;
            }

            // MODIFIED FOR BLOB STORAGE
            $newProfilePicData = $user1['profile_picture'];
            try {
                if (!empty($_FILES['profile_picture']['name']) && is_uploaded_file($_FILES['profile_picture']['tmp_name'])) {
                    $allowedTypes = ['image/jpeg', 'image/png'];
                    $tmpName = $_FILES['profile_picture']['tmp_name'];
                    $fileType = mime_content_type($tmpName);
                    
                    if (in_array($fileType, $allowedTypes)) {
                        $blobData = file_get_contents($tmpName);
                        if ($blobData !== false) {
                            $newProfilePicData = $blobData; // Store BLOB data
                        }
                    }
                }

                $upd = $pdo->prepare("UPDATE users
                    SET first_name = ?, middle_name = ?, surname = ?, suffix = ?,
                        birthdate = ?, sex = ?, street = ?, contact_number = ?, profile_picture = ?
                    WHERE user_id = ?");
                $ok = $upd->execute([
                    $first_name, $middle_name, $surname, $suffix,
                    $birth_norm, $sex_norm, $street, $contact_number_digits, $newProfilePicData, // Use BLOB data
                    $_SESSION['user_id']
                ]);

                $_SESSION['profile_' . ($ok ? 'updated' : 'error')] = true;
                if (!$ok) { $_SESSION['profile_error_msg'] = 'Database update failed.'; }
            } catch (Throwable $e) {
                $_SESSION['profile_error'] = true;
                $_SESSION['profile_error_msg'] = $e->getMessage();
            }
            header('Location: profile.php');
            exit;
        } else {
            // Resident-limited edit: only street and contact number
            if ($__role !== 'resident') {
                $_SESSION['profile_error'] = true;
                $_SESSION['profile_error_msg'] = 'You do not have permission to edit profile details.';
                header('Location: profile.php');
                exit;
            }
            $street = mb_substr(trim((string)($_POST['street'] ?? ($user1['street'] ?? ''))), 0, 255, 'UTF-8');
            $contact_number = trim((string)($_POST['contact_number'] ?? ($user1['contact_number'] ?? '')));
            $contact_number_digits = preg_replace('/\D+/', '', $contact_number);
            if ($contact_number_digits !== '' && strlen($contact_number_digits) > 20) {
                $contact_number_digits = substr($contact_number_digits, 0, 20);
            }
            try {
                $upd = $pdo->prepare("UPDATE users SET street = ?, contact_number = ? WHERE user_id = ?");
                $ok = $upd->execute([$street, $contact_number_digits, $_SESSION['user_id']]);
                $_SESSION['profile_' . ($ok ? 'updated' : 'error')] = true;
                if (!$ok) { $_SESSION['profile_error_msg'] = 'Database update failed.'; }
            } catch (Throwable $e) {
                $_SESSION['profile_error'] = true;
                $_SESSION['profile_error_msg'] = $e->getMessage();
            }
            header('Location: profile.php');
            exit;
        }
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
        if (strcasecmp($current_email_input, ($user1['email'] ?? '')) !== 0) {
            $_SESSION['email_change_error'] = 'Current email does not match our records.';
            header('Location: profile.php'); exit;
        }
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL) || strcasecmp($new_email, $confirm_email) !== 0) {
            $_SESSION['email_change_error'] = 'Please enter a valid and matching email.';
            header('Location: profile.php'); exit;
        }
        if (strcasecmp($new_email, $user1['email'] ?? '') === 0) {
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
        if (strcasecmp($new_email, $user1['email'] ?? '') === 0) {
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
        $toName = trim(($user1['first_name'] ?? 'Resident') . ' ' . ($user1['surname'] ?? ''));
        sendOTPEmail($user1['email'] ?? '', $toName, $otp, 'BDIS Email Change Verification', 'To confirm your email change, please use the code below:');

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
        $toName = trim(($user1['first_name'] ?? 'Resident') . ' ' . ($user1['surname'] ?? ''));
        sendOTPEmail($user1['email'] ?? '', $toName, $otp, 'BDIS Password Change Verification', 'To confirm your password change, please use the code below:');
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
// Use role-specific header when provided by wrapper; otherwise default to resident header in pages/resident
include (defined('PROFILE_HEADER_PATH') ? PROFILE_HEADER_PATH : (__DIR__ . '/pages/resident/header.php'));
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

<?php if (!empty($_GET['openPwd'])): ?>
<script>
window.addEventListener('DOMContentLoaded', function(){
    try {
        const el = document.getElementById('changePasswordModal');
        if (el && window.bootstrap) {
                (window.bootstrap.Modal.getOrCreateInstance(el)).show();
        }
    } catch(e) {}
});
</script>
<?php endif; ?>

<?php if (!empty($_SESSION['password_change_success'])): unset($_SESSION['password_change_success']); ?>
<script>
window.addEventListener('DOMContentLoaded', async function(){
    async function ensureSwal(){
        if (window.Swal) return true;
        try {
            await new Promise((resolve)=>{ const l=document.createElement('link'); l.rel='stylesheet'; l.href='https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css'; l.onload=resolve; l.onerror=resolve; document.head.appendChild(l); });
            await new Promise((resolve)=>{ const s=document.createElement('script'); s.src='https://cdn.jsdelivr.net/npm/sweetalert2@11'; s.onload=resolve; s.onerror=resolve; document.head.appendChild(s); });
        } catch(e){}
        return !!window.Swal;
    }
    const ok = await ensureSwal();
    if (ok) {
        Swal.fire({ icon: 'success', title: 'Password changed', text: 'Your password was updated successfully.', timer: 1800, showConfirmButton: false });
    }
});
</script>
<?php endif; ?>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Profile</h2>
    </div>

    <?php if (!empty($_SESSION['profile_updated'])): ?>
        <div id="profileUpdateSuccessAlert" class="alert alert-success alert-dismissible fade show" role="alert">
            Profile updated successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <script>
        window.addEventListener('DOMContentLoaded', function(){
            const el = document.getElementById('profileUpdateSuccessAlert');
            if (!el) return;
            setTimeout(() => {
                try { (window.bootstrap?.Alert.getOrCreateInstance(el) || new window.bootstrap.Alert(el)).close(); } catch(e) { el.classList.remove('show'); el.remove(); }
            }, 2500);
        });
        </script>
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
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-center mb-3">
                        <input type="file" id="avatarFile" name="profile_picture" accept="image/jpeg,image/png" class="d-none">
                        <input type="file" id="avatarCameraFile" accept="image/*" capture="user" class="d-none">
                        <div id="avatarClickArea" class="d-inline-block position-relative" style="cursor: pointer;" title="Click to change photo">
                            <?php if (!empty($user1['profile_picture'])): ?>
                                <img id="avatarImg" src="<?php echo $profileImage1; ?>" alt="Profile" class="rounded-circle" style="width: 140px; height: 140px; object-fit: cover;">
                            <?php else: ?>
                                <i class="bi bi-person-circle me-2" id="avatarPlaceholder" alt="Profile" style="width: 140px; height: 140px; object-fit: cover;"></i> <p>Profile</p>
                            <?php endif; ?>
                            <span id="avatarMenuActivator" class="position-absolute" style="right: -2px; bottom: -2px; width: 28px; height: 28px; background: #0d6efd; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 2px #fff;">
                                <i class="bi bi-camera-fill" style="color: #fff; font-size: 14px;"></i>
                            </span>
                            <div id="avatarMenu" class="shadow rounded border d-none" style="position:absolute; top:100%; left:50%; transform: translate(-50%, 8px); background:#fff; z-index:1050; min-width: 180px;">
                                <button type="button" id="avatarMenuUpload" class="dropdown-item w-100 text-start py-2">
                                    <i class="bi bi-image me-2"></i>Choose Photo
                                </button>
                            </div>
                        </div>
                    </div>
                    <h5 class="text-center mb-1"><?php echo htmlspecialchars(trim(bd_title($user1['first_name'] ?? '') . ' ' . bd_title($user1['middle_name'] ?? '') . ' ' . bd_title($user1['surname'] ?? ''))); ?></h5>
                    <div class="d-flex justify-content-center align-items-center gap-2 mb-3">
                        <span class="text-muted"><?php echo htmlspecialchars(mask_email($user1['email'] ?? '')); ?></span>
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
                            <div>
                                <?php
                                    $currentUrl = $_SERVER['REQUEST_URI'] ?? '/Project_A2/pages/resident/profile.php';
                                    $returnParam = '?return=' . urlencode($currentUrl);
                                ?>
                                <a class="btn btn-outline-secondary w-100" href="/Project_A2/change_password.php<?php echo $returnParam; ?>">
                                    Change Password
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
                                <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars(bd_title($user1['first_name'] ?? '')); ?>" disabled data-original="<?php echo htmlspecialchars(bd_title($user1['first_name'] ?? '')); ?>" <?php echo $canEditProfile ? 'required' : ''; ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name" value="<?php echo htmlspecialchars(bd_title($user1['middle_name'] ?? '')); ?>" disabled data-original="<?php echo htmlspecialchars(bd_title($user1['middle_name'] ?? '')); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="surname" value="<?php echo htmlspecialchars(bd_title($user1['surname'] ?? '')); ?>" disabled data-original="<?php echo htmlspecialchars(bd_title($user1['surname'] ?? '')); ?>" <?php echo $canEditProfile ? 'required' : ''; ?>>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Suffix</label>
                                <input type="text" class="form-control" name="suffix" value="<?php echo htmlspecialchars(bd_title($user1['suffix'] ?? '')); ?>" disabled data-original="<?php echo htmlspecialchars(bd_title($user1['suffix'] ?? '')); ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Birthdate</label>
                                <input type="date" class="form-control" name="birthdate" value="<?php echo htmlspecialchars($birthdateDisplay); ?>" disabled data-original="<?php echo htmlspecialchars($birthdateDisplay); ?>" <?php echo $canEditProfile ? 'required' : ''; ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sex</label>
                                <select class="form-select" name="sex" disabled data-original="<?php echo htmlspecialchars($sexNorm); ?>" <?php echo $canEditProfile ? 'required' : ''; ?>>
                                    <?php $sexVal = $sexNorm; ?>
                                    <option value="">Select</option>
                                    <option value="Male" <?php echo ($sexVal === 'Male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($sexVal === 'Female') ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label">Street</label>
                                <input type="text" class="form-control" id="streetInput" name="street" value="<?php echo htmlspecialchars($__displayStreet); ?>" disabled data-original="<?php echo htmlspecialchars($__displayStreet); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Contact Number</label>
                                <input type="text" class="form-control" id="contactInput" name="contact_number" value="<?php echo htmlspecialchars($__contactMasked); ?>" disabled data-raw="<?php echo htmlspecialchars($user1['contact_number'] ?? ''); ?>" data-masked="<?php echo htmlspecialchars($__contactMasked); ?>">
                            </div>

                            
                        </div>

                        <?php if ($canEditProfile || $residentCanEditContact): ?>
                        <div class="d-flex justify-content-end mt-4 gap-2">
                            <button type="button" class="btn btn-outline-secondary" id="cancelEditBtn" style="display:none;">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="saveChangesBtn" style="display:none;">Save</button>
                            <button type="button" class="btn btn-primary" id="editProfileBtn"><?php echo $canEditProfile ? 'Edit' : 'Edit'; ?></button>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include (defined('PROFILE_FOOTER_PATH') ? PROFILE_FOOTER_PATH : (__DIR__ . '/../../pages/resident/footer.php')); ?>
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
<div class="modal fade" id="changeEmailModal" tabindex="-1" aria-labelledby="changeEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="changeEmailModalLabel">Verify Current Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="changeEmailCurrentForm" data-current-email="<?php echo htmlspecialchars($user1['email'] ?? ''); ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Current Email</label>
                        <input type="email" class="form-control" name="current_email" placeholder="Enter current email" required>
                        <div class="form-text">On record: <?php echo htmlspecialchars(mask_email($user1['email'] ?? '')); ?></div>
                    </div>
                    <div class="small text-muted">We'll send a verification code to your current email.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Code</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="changeEmailNewModal" tabindex="-1" aria-labelledby="changeEmailNewModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="changeEmailNewModalLabel">New Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="changeEmailNewForm" data-current-email="<?php echo htmlspecialchars($user1['email'] ?? ''); ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">New Email</label>
                        <input type="email" class="form-control" name="new_email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Email</label>
                        <input type="email" class="form-control" name="confirm_email" required>
                    </div>
                    <div class="small text-muted">We'll send a verification code to your new email.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Code</button>
                </div>
            </form>
        </div>
    </div>
    </div>

<div class="modal fade" id="currentEmailOtpModal" tabindex="-1" aria-labelledby="currentEmailOtpModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="currentEmailOtpModalLabel">Enter Verification Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="currentEmailOtpForm">
                <div class="modal-body">
                    <div class="mb-2 small text-muted">A 6-digit code was sent to your current email.</div>
                    <div class="mb-3">
                        <label class="form-label">Code</label>
                        <input type="text" class="form-control" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required placeholder="Enter 6-digit code" title="Enter the 6-digit code">
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-link p-0" id="resendCurrentOtpBtn">Resend Code</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Verify Code</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="newEmailOtpModal" tabindex="-1" aria-labelledby="newEmailOtpModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newEmailOtpModalLabel">Enter Code Sent to New Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="newEmailOtpForm">
                <div class="modal-body">
                    <div class="mb-2 small text-muted">A 6-digit code was sent to your new email.</div>
                    <div class="mb-3">
                        <label class="form-label">Code</label>
                        <input type="text" class="form-control" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required placeholder="Enter 6-digit code" title="Enter the 6-digit code">
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-link p-0" id="resendNewOtpBtn">Resend Code</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Verify Code</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Change Email - New Two-Step Flow (Bootstrap modals + AJAX)
// Ensure SweetAlert2 is available for consistent UX
async function ensureSwalLoaded(){
    if (window.Swal) return true;
    // load CSS
    await new Promise((resolve)=>{
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css';
        link.onload = resolve; link.onerror = resolve;
        document.head.appendChild(link);
    });
    await new Promise((resolve)=>{
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
        s.onload = resolve; s.onerror = resolve;
        document.head.appendChild(s);
    });
    return !!window.Swal;
}
const changeEmailCurrentForm = document.getElementById('changeEmailCurrentForm');
const changeEmailNewForm = document.getElementById('changeEmailNewForm');
const currentEmailOtpModalEl = document.getElementById('currentEmailOtpModal');
const currentEmailOtpForm = document.getElementById('currentEmailOtpForm');
const resendCurrentOtpBtn = document.getElementById('resendCurrentOtpBtn');
const newEmailOtpModalEl = document.getElementById('newEmailOtpModal');
const newEmailOtpForm = document.getElementById('newEmailOtpForm');
const resendNewOtpBtn = document.getElementById('resendNewOtpBtn');

let cachedCurrentEmail = '';
let cachedNewEmail = '';

async function postEmailAjax(params) {
    const res = await fetch('profile.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(params).toString() });
    return res.json().catch(() => ({}));
}

changeEmailCurrentForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = changeEmailCurrentForm;
    const expected = (form.dataset.currentEmail || '').trim().toLowerCase();
    const typedCurrent = (form.querySelector('input[name="current_email"]')?.value || '').trim().toLowerCase();
    if (!typedCurrent || typedCurrent !== expected) {
        await ensureSwalLoaded();
        await Swal.fire({ icon: 'error', title: 'Incorrect email', text: 'The current email you entered does not match.' });
        return;
    }
    const modalEl = document.getElementById('changeEmailModal');
    const modalInstance = window.bootstrap?.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl);
    modalInstance.hide();

    const start = await postEmailAjax({ ajax: 'email_change_send_current_otp', current_email: typedCurrent });
    if (!start || !start.ok) {
        await ensureSwalLoaded();
        await Swal.fire({ icon: 'error', title: 'Cannot send code', text: (start && start.message) || 'Please try again.' });
        modalInstance.show();
        return;
    }
    // Cache current email for resends and show OTP modal
    cachedCurrentEmail = typedCurrent;
    const otpModal = window.bootstrap?.Modal.getInstance(currentEmailOtpModalEl) || new window.bootstrap.Modal(currentEmailOtpModalEl);
    otpModal.show();
});

// Resend code for current email
resendCurrentOtpBtn?.addEventListener('click', async () => {
    const resp = await postEmailAjax({ ajax: 'email_change_send_current_otp', current_email: cachedCurrentEmail });
    await ensureSwalLoaded();
    await Swal.fire({ icon: resp && resp.ok ? 'info' : 'error', title: resp && resp.ok ? 'Code resent' : 'Failed to resend', timer: 1200, showConfirmButton: false });
});

// Verify current email OTP
currentEmailOtpForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const code = (currentEmailOtpForm.querySelector('input[name="otp"]')?.value || '').trim();
    const verify = await postEmailAjax({ ajax: 'email_change_verify_current_otp', otp: code });
    if (verify && verify.ok) {
        const otpModal = window.bootstrap?.Modal.getInstance(currentEmailOtpModalEl) || new window.bootstrap.Modal(currentEmailOtpModalEl);
        otpModal.hide();
        const newModal = window.bootstrap?.Modal.getInstance(changeEmailNewModal) || new window.bootstrap.Modal(changeEmailNewModal);
        newModal.show();
    } else {
        await ensureSwalLoaded();
        await Swal.fire({ icon: 'error', title: 'Invalid/expired code', text: (verify && verify.message) || 'Please try again.' });
    }
});

changeEmailNewForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = changeEmailNewForm;
    const currentEmail = (form.dataset.currentEmail || '').trim().toLowerCase();
    const newEmail = (form.querySelector('input[name="new_email"]')?.value || '').trim();
    const confEmail = (form.querySelector('input[name="confirm_email"]')?.value || '').trim();
    if (!newEmail || newEmail.toLowerCase() !== confEmail.toLowerCase()) {
        await ensureSwalLoaded();
        await Swal.fire({ icon: 'error', title: 'Check new email', text: 'New email and confirm email must match.' });
        return;
    }
    if (newEmail.toLowerCase() === currentEmail) {
        await ensureSwalLoaded();
        await Swal.fire({ icon: 'error', title: 'New email invalid', text: 'New email must be different from current email.' });
        return;
    }
    const modalEl = document.getElementById('changeEmailNewModal');
    const modalInstance = window.bootstrap?.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl);
    modalInstance.hide();

    const send = await postEmailAjax({ ajax: 'email_change_send_new_otp', new_email: newEmail, confirm_email: confEmail });
    if (!send || !send.ok) {
        await ensureSwalLoaded();
        await Swal.fire({ icon: 'error', title: 'Cannot send code', text: (send && send.message) || 'Please try again.' });
        modalInstance.show();
        return;
    }
    // Cache new email for resends and show OTP modal
    cachedNewEmail = newEmail;
    const otpModal = window.bootstrap?.Modal.getInstance(newEmailOtpModalEl) || new window.bootstrap.Modal(newEmailOtpModalEl);
    otpModal.show();
});

// Resend code for new email
resendNewOtpBtn?.addEventListener('click', async () => {
    const resp = await postEmailAjax({ ajax: 'email_change_send_new_otp', new_email: cachedNewEmail, confirm_email: cachedNewEmail });
    await ensureSwalLoaded();
    await Swal.fire({ icon: resp && resp.ok ? 'info' : 'error', title: resp && resp.ok ? 'Code resent' : 'Failed to resend', timer: 1200, showConfirmButton: false });
});

// Verify new email OTP and finalize
newEmailOtpForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const code = (newEmailOtpForm.querySelector('input[name="otp"]')?.value || '').trim();
    const conf = await postEmailAjax({ ajax: 'email_change_confirm_new_otp', otp: code });
    if (conf && conf.ok) {
        await ensureSwalLoaded();
        await Swal.fire({ icon: 'success', title: 'Successfully changed email', timer: 1800, showConfirmButton: false });
        location.reload();
    } else {
        await ensureSwalLoaded();
        await Swal.fire({ icon: 'error', title: 'Invalid/expired code', text: (conf && conf.message) || 'Please try again.' });
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
    async function ensureCropperLoaded(){
        if (window.Cropper) return true;
        // load CSS
        await new Promise((resolve)=>{
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css';
            link.onload = resolve; link.onerror = resolve; // continue even if cache
            document.head.appendChild(link);
        });
        // load JS
        await new Promise((resolve)=>{
            const s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js';
            s.onload = resolve; s.onerror = resolve;
            document.head.appendChild(s);
        });
        return !!window.Cropper;
    }

    async function cropAndUpload(file){
        if (!file) return;
        await ensureSwalLoaded();
        if (!/^image\/(jpeg|png)$/.test(file.type)) {
            await Swal.fire({ icon: 'error', title: 'Invalid file', text: 'Please choose a JPG, JPEG or PNG image.' });
            return;
        }
        await ensureCropperLoaded();
        const dataUrl = await new Promise((resolve)=>{
            const r = new FileReader();
            r.onload = ()=> resolve(r.result);
            r.readAsDataURL(file);
        });
        const { value: confirmed } = await Swal.fire({
            title: 'Crop your photo',
            html: '<div style="max-width:420px;margin:0 auto"><img id="cropImg" style="max-width:100%; display:block;" /></div>',
            didOpen: () => {
                const img = document.getElementById('cropImg');
                img.src = dataUrl;
                const cropper = new window.Cropper(img, {
                    aspectRatio: 1,
                    viewMode: 1,
                    movable: true,
                    zoomable: true,
                    scalable: false,
                    responsive: true,
                    minCropBoxWidth: 100,
                    minCropBoxHeight: 100,
                });
                // attach for later retrieval
                img._cropper = cropper;
            },
            showCancelButton: true,
            confirmButtonText: 'Use Photo',
            focusConfirm: false,
            preConfirm: () => {
                const img = document.getElementById('cropImg');
                const cropper = img && img._cropper;
                if (!cropper) return false;
                const canvas = cropper.getCroppedCanvas({ width: 600, height: 600, imageSmoothingQuality: 'high' });
                if (!canvas) { Swal.showValidationMessage('Unable to crop image.'); return false; }
                // convert to Blob
                const mime = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
                return new Promise((resolve) => canvas.toBlob((blob)=> resolve(blob), mime, 0.92));
            }
        });
        if (!confirmed) return; // cancelled
        const blob = confirmed; // SweetAlert preConfirm resolves with Blob
        if (!blob) return;
        const ext = blob.type === 'image/png' ? 'png' : 'jpg';
        // The Cropper library returns the cropped image as a Blob, we wrap it into a File object for FormData.
        const croppedFile = new File([blob], 'avatar.'+ext, { type: blob.type }); 

        const fd = new FormData();
        fd.append('ajax', 'avatar_upload');
        fd.append('profile_picture', croppedFile);
        try {
            const res = await fetch('profile.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data && data.ok) {
                // data.url is now the base64 Data URL string from the PHP side
                if (imgEl) {
                    imgEl.src = data.url;
                } else if (placeholder) {
                    // This block ensures the placeholder icon is replaced with the new image
                    const img = document.createElement('img');
                    img.id = 'avatarImg';
                    img.className = 'rounded-circle';
                    img.style.width = '140px';
                    img.style.height = '140px';
                    img.style.objectFit = 'cover';
                    img.src = data.url;
                    // The old placeholder element has the ID 'avatarPlaceholder'
                    document.getElementById('avatarPlaceholder').replaceWith(img); 
                }
                location.reload();
                await Swal.fire({ icon: 'success', title: 'Profile photo updated', timer: 1500, showConfirmButton: false });
            } else {
                await Swal.fire({ icon: 'error', title: 'Upload failed', text: (data && data.message) || 'Please try again.' });
            }
        } catch (e) {
            await Swal.fire({ icon: 'error', title: 'Network error', text: 'Please try again.' });
        }
    }

    // Floating menu behavior
    const avatarMenu = document.getElementById('avatarMenu');
    const avatarMenuUpload = document.getElementById('avatarMenuUpload');
    const avatarMenuCancel = document.getElementById('avatarMenuCancel'); // Removed from HTML, keeping for safety

    function positionMenu(){
        if (!avatarMenu || !clickArea) return;
        // Re-parent to body to avoid clipping/overflow issues
        if (avatarMenu.parentElement !== document.body) {
            document.body.appendChild(avatarMenu);
            avatarMenu.style.position = 'absolute';
            avatarMenu.style.zIndex = '2000';
        }
        const r = clickArea.getBoundingClientRect();
        const top = Math.round(r.bottom + window.scrollY + 8);
        const left = Math.round(r.left + window.scrollX + (r.width / 2));
        avatarMenu.style.top = top + 'px';
        avatarMenu.style.left = left + 'px';
        avatarMenu.style.transform = 'translateX(-50%)';
    }

    function showAvatarMenu(){
        positionMenu();
        if (avatarMenu) {
            avatarMenu.classList.remove('d-none');
            avatarMenu.style.display = 'block';
        }
    }
    function hideAvatarMenu(){
        if (avatarMenu) {
            avatarMenu.classList.add('d-none');
            avatarMenu.style.display = 'none';
        }
    }

    clickArea.addEventListener('click', (e) => {
        // Toggle menu; if already open and click outside the menu but within area, toggle close
        if (e.target.closest('#avatarMenuActivator')) return; // handled by separate listener
        if (avatarMenu?.classList.contains('d-none')) showAvatarMenu();
        else hideAvatarMenu();
    });
    // Ensure the small camera button always opens the menu
    document.getElementById('avatarMenuActivator')?.addEventListener('click', (e) => {
        e.stopPropagation();
        showAvatarMenu();
    });
    document.addEventListener('click', (e) => {
        if (!clickArea.contains(e.target) && !(avatarMenu && avatarMenu.contains(e.target))) hideAvatarMenu();
    });
    window.addEventListener('scroll', hideAvatarMenu, { passive: true });
    window.addEventListener('resize', hideAvatarMenu);
    avatarMenuUpload?.addEventListener('click', () => { hideAvatarMenu(); fileInput.click(); });
    avatarMenuCancel?.addEventListener('click', hideAvatarMenu);

    fileInput.addEventListener('change', async () => {
        const f = fileInput.files && fileInput.files[0];
        await cropAndUpload(f);
        fileInput.value = '';
    });

    camInput?.addEventListener('change', async () => {
        const f = camInput.files && camInput.files[0];
        await cropAndUpload(f);
        camInput.value = '';
    });
})();
</script>

<script>
// Edit/Save/Cancel toggle for profile fields (role-gated)
(function(){
    const form = document.getElementById('profileEditForm');
    const editBtn = document.getElementById('editProfileBtn');
    const saveBtn = document.getElementById('saveChangesBtn');
    const cancelBtn = document.getElementById('cancelEditBtn');
    const street = document.getElementById('streetInput');
    const contact = document.getElementById('contactInput');
    if (!form || !editBtn || !saveBtn || !cancelBtn || !street || !contact) return;

    const CAN_EDIT_ALL = <?php echo $canEditProfile ? 'true' : 'false'; ?>;

    // Additional fields to toggle
    const firstName  = form.querySelector('input[name="first_name"]');
    const middleName = form.querySelector('input[name="middle_name"]');
    const lastName   = form.querySelector('input[name="surname"]');
    const suffix     = form.querySelector('input[name="suffix"]');
    const birthdate  = form.querySelector('input[name="birthdate"]');
    const sexSel     = form.querySelector('select[name="sex"]');

    let watchersAttached = false;

    function digitsOnly(v){ return (v || '').replace(/\D+/g,''); }

    function hasChanges(){
        // Compare against data-original attributes (trim for safety)
        const f = (firstName?.value || '').trim();
        const f0 = (firstName?.dataset.original || '').trim();
        if (firstName && f !== f0) return true;

        const m = (middleName?.value || '').trim();
        const m0 = (middleName?.dataset.original || '').trim();
        if (middleName && m !== m0) return true;

        const l = (lastName?.value || '').trim();
        const l0 = (lastName?.dataset.original || '').trim();
        if (lastName && l !== l0) return true;

        const sfx = (suffix?.value || '').trim();
        const sfx0 = (suffix?.dataset.original || '').trim();
        if (suffix && sfx !== sfx0) return true;

        const bd = (birthdate?.value || '').trim();
        const bd0 = (birthdate?.dataset.original || '').trim();
        if (birthdate && bd !== bd0) return true;

        const sx = (sexSel?.value || '').trim();
        const sx0 = (sexSel?.dataset.original || '').trim();
        if (sexSel && sx !== sx0) return true;

        const st = (street?.value || '').trim();
        const st0 = (street?.dataset.original || '').trim();
        if (street && st !== st0) return true;

        const c = digitsOnly(contact?.value || '');
        const c0 = digitsOnly(contact?.dataset.raw || contact?.dataset.original || '');
        if (contact && c !== c0) return true;

        return false;
    }

    function updateSaveState(){
        // Only matters while Save is visible (edit mode), but safe always
        const changed = hasChanges();
        if (saveBtn) saveBtn.disabled = !changed;
    }

    function attachWatchersOnce(){
        if (watchersAttached) return;
        [firstName, middleName, lastName, suffix, birthdate, street, contact].forEach(inp => inp && inp.addEventListener('input', updateSaveState));
        sexSel?.addEventListener('change', updateSaveState);
        watchersAttached = true;
    }

    function enterEdit(){
        street.disabled = false;
        contact.disabled = false;
        // Swap masked to raw for editing
        const raw = contact.dataset.raw || '';
        if (raw) contact.value = raw;
        // Enable name/birth/sex fields only for privileged roles
        if (CAN_EDIT_ALL) {
            if (firstName) firstName.disabled = false;
            if (middleName) middleName.disabled = false;
            if (lastName) lastName.disabled = false;
            if (suffix) suffix.disabled = false;
            if (birthdate) birthdate.disabled = false;
            if (sexSel) sexSel.disabled = false;
        }
        editBtn.style.display = 'none';
        saveBtn.style.display = '';
        cancelBtn.style.display = '';
        (firstName || street).focus();
        // Ensure Save starts disabled until a change occurs
        attachWatchersOnce();
        updateSaveState();
    }
    function exitEdit(reset){
        street.disabled = true;
        contact.disabled = true;
        if (reset){
            street.value = street.dataset.original || '';
            contact.value = contact.dataset.masked || '';
            // Reset name/birth/sex fields to their original values
            if (CAN_EDIT_ALL) {
                if (firstName) firstName.value = (firstName.dataset.original || '');
                if (middleName) middleName.value = (middleName.dataset.original || '');
                if (lastName) lastName.value = (lastName.dataset.original || '');
                if (suffix) suffix.value = (suffix.dataset.original || '');
                if (birthdate) birthdate.value = (birthdate.dataset.original || '');
                if (sexSel) sexSel.value = (sexSel.dataset.original || '');
            }
        } else {
            // keep current value but remask for display
            contact.value = contact.dataset.masked || '';
        }
        // Disable fields after exiting edit mode
        if (CAN_EDIT_ALL) {
            if (firstName) firstName.disabled = true;
            if (middleName) middleName.disabled = true;
            if (lastName) lastName.disabled = true;
            if (suffix) suffix.disabled = true;
            if (birthdate) birthdate.disabled = true;
            if (sexSel) sexSel.disabled = true;
        }
        editBtn.style.display = '';
        saveBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
        // Reset save state
        if (saveBtn) saveBtn.disabled = true;
    }

    editBtn.addEventListener('click', enterEdit);
    cancelBtn.addEventListener('click', () => exitEdit(true));
    // Do NOT disable fields before submit; disabled inputs are not posted.
    // After redirect, fields default back to read-only state.

    // Guard submission when no changes present
    form.addEventListener('submit', function(e){
        if (saveBtn && saveBtn.style.display !== 'none' && saveBtn.disabled) {
            e.preventDefault();
        }
    });
})();
</script>