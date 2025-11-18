<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/config.php';

// --- IMPORTANT: Include PHPMailer autoload and config (assuming these files exist) ---
require 'vendor/autoload.php';
require_once __DIR__ . '/includes/email_config.php'; 

// Start the session if not already started in config.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// NOTE: Assuming your `sendOTPEmail` function is defined and configured in `email_config.php`
// -------------------------------------------------------------------------------------

date_default_timezone_set('Asia/Manila');

// --- Global variables for state management ---
$error = null;
$success = null;

// Check for flash messages created by successful POST-REDIRECT-GET (PRG) actions
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

/* ---------------------------
    FETCH LOGO FROM DATABASE
---------------------------- */
global $pdo;
$logoImage = ""; 
try {
    $logoStmt = $pdo->query("SELECT brgy_logo FROM address_config LIMIT 1");
    $logoData = $logoStmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($logoData['brgy_logo'])) {
        $logoImage = "data:image/png;base64," . base64_encode($logoData['brgy_logo']);
    }
} catch (Exception $e) {
    error_log("Failed to fetch logo: " . $e->getMessage());
}


// --- STATE MANAGEMENT ---
$step = 1; // Default to Email Input

// Determine the current step based on session data
if (isset($_SESSION['reset_email_id'])) {
    if (isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true) {
        $step = 3; // OTP verified, go to Password Reset
    } else {
        $step = 2; // Email found, go to OTP Verification
    }
}

$email_id = $_SESSION['reset_email_id'] ?? null;
$user_email = $_SESSION['reset_user_email'] ?? ''; 

// --- DYNAMIC HEADER CONTENT ---
$header_title = "Password Reset";
$header_text = "Follow the steps to securely regain access to your account.";

if ($step === 1) {
    $header_title = "Find Your Account";
    $header_text = "Enter your account email below.";
} elseif ($step === 2) {
    $header_title = "Verify Security Code";
    $header_text = "Check your inbox for the one-time verification code.";
} elseif ($step === 3) {
    $header_title = "Set New Password";
    $header_text = "Create a new, strong password.";
}

// --- ACTION TO CLEAR SESSION (Used for "Restart Process" link) ---
if (isset($_GET['clear_session'])) {
    unset($_SESSION['reset_email_id']);
    unset($_SESSION['otp_verified']);
    unset($_SESSION['reset_user_email']);
    header("Location: forgot_password.php");
    exit; // ALWAYS exit after header calls
}

// ===================================================
// STEP 1 → Collect and Validate Email & Send OTP
// ===================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_email']) && isset($_POST['form_step']) && $_POST['form_step'] === '1_submit' && $step === 1) {
    $email = sanitize($_POST['email']);

    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT e.email_id, e.email, u.first_name, u.surname
                               FROM email e
                               JOIN account a ON e.email_id = a.email_id
                               JOIN users u ON a.user_id = u.user_id
                               WHERE e.email = ?");
        $stmt->execute([$email]);
        $email_row = $stmt->fetch();

        if (!$email_row) {
            $error = "The email address is not registered in our system.";
        } else {
            $email_id = $email_row['email_id'];
            $user_name = trim($email_row['first_name'] . ' ' . $email_row['surname']);
            
            $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes')); 

            // Clear old OTPs and insert the new one (using REPLACE INTO or DELETE+INSERT is safer)
            $pdo->prepare("DELETE FROM email_verifications WHERE email_id = ?")->execute([$email_id]);
            $pdo->prepare("
                INSERT INTO email_verifications (email_id, otp_code, otp_expires_at) 
                VALUES (?, ?, ?)
            ")->execute([$email_id, $otp_code, $expires_at]);
            
            // 🐛 FIX APPLIED: UNCOMMENTED THE EMAIL SENDING CALL
            if (function_exists('sendOTPEmail')) {
                sendOTPEmail($email, $user_name ?: 'User', $otp_code); 
            } else {
                 error_log("FATAL: sendOTPEmail function not found!");
            }
            // --------------------------------------------------
            
            $_SESSION['reset_email_id'] = $email_id;
            $_SESSION['reset_user_email'] = $email;
            $_SESSION['flash_success'] = "A 6-digit verification code has been sent to **" . htmlspecialchars($email) . "**.";
            
            header("Location: forgot_password.php");
            exit; 
        }
    } catch (Exception $e) {
        $error = "An error occurred while sending the OTP: " . $e->getMessage();
    }
}


// ===================================================
// STEP 2 → Verify OTP 
// ===================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp']) && $step === 2) {
    if (!isset($_SESSION['reset_email_id'])) {
        $error = "Security error: Session data missing. Restarting process...";
        header("Location: forgot_password.php?clear_session=true");
        exit;
    }

    $otp_input_raw = trim($_POST['otp_code']); 
    $otp_input_int = (int)$otp_input_raw; 
    
    try {
        global $pdo;
        $stmt = $pdo->prepare("
            SELECT otp_code, otp_expires_at 
            FROM email_verifications 
            WHERE email_id = ?
            ORDER BY otp_expires_at DESC LIMIT 1
        ");
        $stmt->execute([$email_id]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = "No active OTP record found. Please request a new code.";
            unset($_SESSION['reset_email_id']); 
            unset($_SESSION['otp_verified']);
            $step = 1; 
        } else {
            $otp_db_raw = trim($row['otp_code']);
            $otp_db_int = (int)$otp_db_raw; 

            if ($otp_db_int !== $otp_input_int) { 
                $error = "Invalid verification code. Please try again.";
            } elseif (strtotime($row['otp_expires_at']) < time()) {
                $error = "OTP expired. Please request a new code.";
                $pdo->prepare("DELETE FROM email_verifications WHERE email_id = ?")->execute([$email_id]);
                unset($_SESSION['reset_email_id']); 
                unset($_SESSION['otp_verified']);
                $step = 1; 
            } else {
                $_SESSION['otp_verified'] = true;
                $_SESSION['flash_success'] = "OTP verified successfully. You can now set your new password.";
                
                $pdo->prepare("DELETE FROM email_verifications WHERE email_id = ?")->execute([$email_id]);
                
                header("Location: forgot_password.php");
                exit; 
            }
        }
    } catch (Exception $e) {
        $error = "Verification failed: " . $e->getMessage();
    }
}

// ===================================================
// STEP 3 → Reset Password
// ===================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password']) && $step === 3) {
    if (!isset($_SESSION['otp_verified'])) {
        $error = "Security error: Session verification lost. Please restart.";
        $step = 1; 
    } else {
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];

        // Password validation logic
        if (strlen($newPassword) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif (!preg_match('/[A-Z]/', $newPassword)) {
            $error = "Password must contain at least one uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $newPassword)) {
            $error = "Password must contain at least one lowercase letter.";
        } elseif (!preg_match('/[0-9]/', $newPassword)) {
            $error = "Password must contain at least one number.";
        } elseif (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            $error = "Password must contain at least one special character.";
        } elseif ($newPassword !== $confirmPassword) {
            $error = "Passwords do not match.";
        } else {
            try {
                global $pdo;
                $stmt = $pdo->prepare("SELECT a.password_id FROM account a WHERE a.email_id = ?");
                $stmt->execute([$email_id]);
                $account = $stmt->fetch();

                if ($account) {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    
                    $pdo->prepare("
                        UPDATE password SET passkey = ? WHERE password_id = ?
                    ")->execute([$hashedPassword, $account['password_id']]);

                    unset($_SESSION['reset_email_id']);
                    unset($_SESSION['otp_verified']);
                    unset($_SESSION['reset_user_email']);

                    $_SESSION['flash_success_login'] = "Your password has been successfully reset! You can now log in.";
                    header("Location: /Project_A2/login.php");
                    exit;

                } else {
                    $error = "Account link not found. Please contact support.";
                }
            } catch (Exception $e) {
                $error = "Failed to update password: " . $e->getMessage();
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
<title><?php echo $header_title; ?> - BDIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

<style>
    /* --- COPIED LOGIN THEME STYLES --- */
    body {
        font-family: 'Inter', sans-serif;
        background: #ffffff !important; 
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center; 
        justify-content: center; 
        padding: 20px 0; 
    }
    .register-container {
        position: relative;
        z-index: 10;
        max-width: 430px; /* Matching login card max-width */
        width: 100%;
        margin: 0 auto;
    }
    .register-card {
        position: relative;
        z-index: 10;
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.12);
        padding: 0;
        border: 1px solid #e0e0e0; /* Subtle border for definition */
    }
    
    /* Login Header Style */
    .register-header {
        padding: 30px;
        text-align: center;
        background-color: #f9f9f9; /* Light, clean gray header background */
        border-radius: 20px 20px 0 0;
        border-bottom: 1px solid #e0e0e5; 
        color: #333; /* Dark text for light background */
    }
    .register-header h3 {
        font-weight: 700;
        margin-bottom: 5px;
        color: #333;
    }
    .register-header p {
        font-weight: 400;
        color: #666;
        font-size: 1rem;
        margin: 0;
    }

    /* Styles for the logo inside the header */
    .header-logo-container {
        margin-bottom: 10px; /* Space below the logo */
    }
    .header-logo-container img {
        width: 60px; /* Small logo size */
        height: auto;
        border-radius: 50%; /* Circular logo */
        border: 2px solid #4f46e5; /* Accent border */
    }

    .register-body {
        padding: 30px;
    }
    .form-label {
        font-weight: 600;
        color: #333;
        margin-bottom: 5px;
    }
    .form-control, .form-select {
        border-radius: 12px; /* Matching login rounded corners */
        border: 2px solid #e5e7eb;
        padding: 12px;
    }
    .form-control:focus, .form-select:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 0.15rem rgba(79, 70, 229, 0.25);
    }
    /* OTP specific style adjustment */
    .otp-input {
        text-align: center;
        font-size: 2rem;
        letter-spacing: 5px;
        padding: 15px;
    }
    .btn-register {
        background: #4f46e5; /* Accent Color */
        color: white;
        padding: 12px;
        border-radius: 12px; /* Matching login rounded corners */
        border: none;
        font-weight: 600;
        font-size: 1.1rem;
        width: 100%;
        transition: background 0.2s;
    }
    .btn-register:hover {
        background: #4338ca;
    }
    .text-decoration-none {
        color: #4f46e5 !important;
        font-weight: 600;
    }
    .text-decoration-none:hover {
        text-decoration: underline !important;
    }
    .alert {
        border-radius: 12px;
    }

    /* Password Requirements List Style */
    .password-requirements {
        list-style: none;
        padding-left: 0;
        margin-top: 5px;
        font-size: 0.9em;
        display: none; 
    }
    .password-requirements li {
        color: #888;
        margin-bottom: 3px;
        transition: color 0.3s, opacity 0.3s;
        display: list-item;
    }
    .password-requirements li i {
        margin-right: 5px;
        width: 15px;
        text-align: center;
        color: #dc3545;
    }
    /* --- MOBILE RESPONSIVENESS (Matching login.php) --- */
    @media (max-width: 500px) {
        .register-card {
            max-width: 100%; 
            width: 90%; 
            margin: 0 auto; 
        }
        .register-header, .register-body {
            padding: 20px;
        }
    }
</style>
</head>
<body>

<div class="register-container">
    <div class="register-card">
        
        <div class="register-header">
            <?php if (!empty($logoImage)): ?>
                <div class="header-logo-container">
                    <img src="<?php echo $logoImage; ?>" alt="Barangay Logo">
                </div>
            <?php endif; ?>
            <h3><?php echo $header_title; ?></h3>
            <p><?php echo $header_text; ?></p>
        </div>
        
        <div class="register-body">
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <form method="POST">
                    <input type="hidden" name="form_step" value="1_submit"> 
                    <div class="form-floating mb-4">
                        <input class="form-control" type="email" id="email" name="email" placeholder="e.g., yourname@example.com" required autofocus>
                        <label for="email">Email Address</label>
                    </div>
                    <button type="submit" name="submit_email" class="btn-register mb-3">
                        <i class="fas fa-paper-plane me-2"></i>Send Verification Code
                    </button>
                </form>

            <?php elseif ($step === 2): ?>
                <div class="text-center mb-4">
                    <p class="text-muted">Code sent to: **<?php echo htmlspecialchars($user_email); ?>**</p>
                </div>
                
                <form method="POST">
                    <div class="mb-4">
                        <label for="otp_code" class="form-label text-center w-100">Enter 6-digit Code <span class="text-danger">*</span></label>
                        <input class="form-control otp-input" type="text" id="otp_code" name="otp_code" maxlength="6" pattern="\d{6}" placeholder="------" required autofocus>
                    </div>
                    <button type="submit" name="verify_otp" class="btn-register mb-3">
                        <i class="fas fa-check-circle me-2"></i>Verify Code
                    </button>
                </form>

                <div class="text-center mt-3">
                    <p class="text-muted">
                        Incorrect Email or Didn't receive the code? 
                        <a href="?clear_session=true" class="text-decoration-none">Restart Process</a>
                    </p>
                </div>

            <?php elseif ($step === 3): ?>
                <form method="POST">
                    <div class="form-floating mb-3">
                         <input class="form-control" type="password" id="new_password" name="new_password" required autofocus>
                        <label for="new_password">New Password</label>
                        <ul id="passwordRequirements" class="password-requirements">
                            <li id="length"><i class="fas fa-times-circle"></i> Minimum 8 characters</li>
                            <li id="uppercase"><i class="fas fa-times-circle"></i> At least one **uppercase** letter (A-Z)</li>
                            <li id="lowercase"><i class="fas fa-times-circle"></i> At least one **lowercase** letter (a-z)</li>
                            <li id="number"><i class="fas fa-times-circle"></i> At least one **number** (0-9)</li>
                            <li id="special"><i class="fas fa-times-circle"></i> At least one **special character** (!@#$...)</li>
                        </ul>
                    </div>
                    <div class="form-floating mb-4">
                         <input class="form-control" type="password" id="confirm_password" name="confirm_password" required>
                        <label for="confirm_password">Confirm New Password</label>
                    </div>
                    <button type="submit" name="reset_password" class="btn-register mb-3">
                        <i class="fas fa-save me-2"></i>Save New Password
                    </button>
                </form>

            <?php endif; ?>
            
            <div class="text-center mt-3">
                <a href="/Project_A2/login.php" class="text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('new_password');
    const passwordRequirements = document.getElementById('passwordRequirements'); 
    
    // Only run password strength checker if the element exists (i.e., we are on Step 3)
    if (passwordInput && passwordRequirements) {
        const requirements = {
            length: { regex: /.{8,}/, element: document.getElementById('length') },
            uppercase: { regex: /[A-Z]/, element: document.getElementById('uppercase') },
            lowercase: { regex: /[a-z]/, element: document.getElementById('lowercase') },
            number: { regex: /[0-9]/, element: document.getElementById('number') },
            special: { regex: /[^A-Za-z0-9]/, element: document.getElementById('special') }
        };

        const updatePasswordCheck = () => {
            const password = passwordInput.value;

            if (password.length > 0) {
                passwordRequirements.style.display = 'block';
            } else {
                passwordRequirements.style.display = 'none';
            }

            for (const key in requirements) {
                const req = requirements[key];
                const isMet = req.regex.test(password);
                
                const icon = req.element.querySelector('i');
                
                if (isMet) {
                    req.element.style.color = '#198754'; // Green for met
                    icon.classList.remove('fa-times-circle');
                    icon.classList.add('fa-check-circle');
                    icon.style.color = '#198754';
                } else {
                    req.element.style.color = '#888'; // Grey for unmet
                    icon.classList.remove('fa-check-circle');
                    icon.classList.add('fa-times-circle');
                    icon.style.color = '#dc3545';
                }
            }
        };

        passwordInput.addEventListener('input', updatePasswordCheck);
        updatePasswordCheck();
    }
});
</script>
</body>
</html>