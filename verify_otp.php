<?php
error_reporting(E_ALL);
ini_set('display_errors', 1); // Ensure errors are visible for debugging

require_once 'includes/config.php';
require 'vendor/autoload.php';
require_once __DIR__ . '/includes/email_config.php';

// Start the session if not already started in config.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- SET TIMEZONE TO ASIA/MANILA (GMT +8) ---
date_default_timezone_set('Asia/Manila');

// --- DEFAULT PROFILE PICTURE BLOB SETUP ---
// Reads the binary data of the default avatar for BLOB insertion.
// NOTE: Create an 'images' folder in this directory and place your default image (e.g., default_resident.png) inside.
$default_avatar_path = __DIR__ . '/uploads/default_resident.jpg'; 
$default_avatar_blob = null;

if (file_exists($default_avatar_path)) {
    // Read the entire file content into a variable for BLOB storage
    $default_avatar_blob = file_get_contents($default_avatar_path);
} else {
    // If the default image is missing, the column must be able to accept NULL.
    error_log("CRITICAL ERROR: Default avatar image not found at " . $default_avatar_path);
}
// --- END DEFAULT PROFILE PICTURE BLOB SETUP ---

$email = sanitize($_REQUEST['email'] ?? '');
$error_message = '';
$success_message = '';
$redirect_to_login = false; // Flag for final redirection

// --- Check for necessary registration data in session ---
if (empty($email) || !isset($_SESSION['registration_data']) || $_SESSION['registration_data']['email'] !== $email) {
    $error_message = "Registration data missing or expired. Please register again.";
}

// Check for flash messages set by successful resend redirect
if (isset($_SESSION['flash_success'])) {
    $success_message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}


// --- OTP Resend Logic ---
if (isset($_GET['resend']) && $_GET['resend'] === 'true' && empty($error_message)) {
    try {
        global $pdo;

        // 1. Generate new OTP and expiration time (using GMT+8)
        $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // 2. Delete old OTPs and insert new one
        // Get email_id for this email
        $emailStmt = $pdo->prepare("SELECT email_id FROM email WHERE email = ?");
        $emailStmt->execute([$email]);
        $emailResult = $emailStmt->fetch();
        
        if ($emailResult) {
            $email_id = $emailResult['email_id'];
            $pdo->prepare("DELETE FROM email_verifications WHERE email_id = ?")->execute([$email_id]);
        } else {
            // Create new email entry if it doesn't exist
            $insertEmailStmt = $pdo->prepare("INSERT INTO email (email) VALUES (?)");
            $insertEmailStmt->execute([$email]);
            $email_id = $pdo->lastInsertId();
        }
        
        // Note: Using the updated column names for the new schema
        $stmt = $pdo->prepare("INSERT INTO email_verifications (email_id, otp_code, otp_expires_at)
                             VALUES (?, ?, ?)");
        $stmt->execute([$email_id, $otp_code, $expires_at]);

        // 3. Send email using shared email helper
        $toName = ($_SESSION['registration_data']['first_name'] ?? 'Resident') . ' ' . ($_SESSION['registration_data']['surname'] ?? '');
        
        // Assuming sendOTPEmail function exists and is configured in email_config.php
        if (function_exists('sendOTPEmail')) {
             sendOTPEmail($email, trim($toName), $otp_code);
        } else {
            error_log("FATAL: sendOTPEmail function not found!");
            // Optionally, throw an exception here if email is critical
        }
        
        // Success message for the user, then redirect to clean URL
        $_SESSION['flash_success'] = "A new verification code has been sent to **" . htmlspecialchars($email) . "**.";
        header("Location: verify_otp.php?email=" . urlencode($email));
        exit;

    } catch (Exception $e) {
        $error_message = "Failed to resend OTP: " . $e->getMessage();
    }
}

// --- OTP Submission Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error_message)) {
    $otp_code = sanitize($_POST['otp_code'] ?? '');
    $data = $_SESSION['registration_data'];
    
    try {
        global $pdo;
        
        // 1. Check OTP existence, validity, and expiration
        // First get the email_id for this email
        $emailStmt = $pdo->prepare("SELECT email_id FROM email WHERE email = ?");
        $emailStmt->execute([$email]);
        $emailResult = $emailStmt->fetch();
        
        if (!$emailResult) {
            throw new Exception("Email not found in system.");
        }
        
        $email_id = $emailResult['email_id'];
        
        $stmt = $pdo->prepare("
            SELECT * FROM email_verifications 
            WHERE email_id = ? AND otp_code = ? AND otp_expires_at > NOW()
        ");
        $stmt->execute([$email_id, $otp_code]);
        $verification_data = $stmt->fetch();

        if (!$verification_data) {
            // Check for expired code separately for better user feedback
            $stmt_expired = $pdo->prepare("SELECT * FROM email_verifications WHERE email_id = ? AND otp_code = ?");
            $stmt_expired->execute([$email_id, $otp_code]);
            if ($stmt_expired->fetch()) {
                 throw new Exception("Verification code has expired. Please request a new one.");
            }
            throw new Exception("Invalid verification code. Please try again.");
        }
        
        // --- START TRANSACTION (Registration Insertion) ---
        $pdo->beginTransaction();
        
        // 2. Get configured address_id for this barangay
        $addrStmt = $pdo->prepare("SELECT address_id FROM address_config LIMIT 1");
        $addrStmt->execute();
        $address_id = $addrStmt->fetchColumn();
        if (!$address_id) { $address_id = 1; }

        // 3. Insert into users table (Core Resident Data - using BLOB for profile picture)
        $user_fields = ['first_name', 'middle_name', 'surname', 'suffix', 
                         'contact_number', 'birthdate', 'sex', 'street', 'address_id', 'remarks', 'profile_picture'];
        $placeholders = implode(', ', array_fill(0, count($user_fields), '?'));
        $sql = "INSERT INTO users (" . implode(', ', $user_fields) . ") VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            $data['first_name'], $data['middle_name'], $data['surname'], 
            $data['suffix'], $data['contact_number'], $data['birthdate'], $data['sex'], $data['street'],
            $address_id,
            'New registration', // Default remarks
            $default_avatar_blob // INSERTING BLOB DATA FROM ABOVE
        ]);

        $user_id = $pdo->lastInsertId();

    // 4. Insert into password table first
        $stmt = $pdo->prepare("INSERT INTO password (passkey) VALUES (?)");
        $stmt->execute([$data['password']]);
        $password_id = $pdo->lastInsertId();

    // 5. Insert into account table to link email, user, and password
        $stmt = $pdo->prepare("INSERT INTO account (email_id, user_id, password_id) VALUES (?, ?, ?)");
        $stmt->execute([$email_id, $user_id, $password_id]);
        $account_id = $pdo->lastInsertId();

    // 6. Insert into id_verification (ID and Status Data)
        // Assuming verification_status is a column in id_verification
        $stmt = $pdo->prepare("INSERT INTO id_verification (user_id, id_type, front_img, back_img)
                             VALUES (?, ?, ?, ?)"); 

        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->bindParam(2, $data['id_type'], PDO::PARAM_STR);
        $stmt->bindParam(3, $data['front_id'], PDO::PARAM_LOB);
        $stmt->bindParam(4, $data['back_id'], PDO::PARAM_LOB);
        $stmt->execute();
        
    // 7. Assign default resident role
        $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (?, 'resident')");
        $stmt->execute([$user_id]);
        
    // 8. Commit Transaction
        $pdo->commit();

        // 9. Final Cleanup
        $pdo->prepare("DELETE FROM email_verifications WHERE email_id = ?")->execute([$email_id]);
        unset($_SESSION['registration_data']);
        
        $success_message = "Email verified successfully! Your account is now **Pending** for barangay approval.";
        $redirect_to_login = true;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack(); // Rollback on failure
        }
        $error_message = "Registration failed: " . $e->getMessage();
        
        // Clean up expired OTP for expired error case
        if (str_contains($e->getMessage(), "expired")) {
            $pdo->prepare("DELETE FROM email_verifications WHERE email_id = ?")->execute([$email_id]);
        }
    }
}

// --- DYNAMIC HEADER CONTENT ---
$header_title = "Email Verification";
$header_text = "Please enter the 6-digit code sent to your email to complete registration.";
if ($redirect_to_login) {
    $header_title = "Registration Complete";
    $header_text = "Your account is awaiting approval.";
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
    /* --- UNIFIED LOGIN/FORGOT PASSWORD THEME STYLES --- */
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

    .register-body {
        padding: 30px;
    }
    .form-control {
        border-radius: 12px; /* Matching rounded corners */
        border: 2px solid #e5e7eb;
        padding: 12px;
    }
    .form-control:focus {
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
        border-radius: 12px; /* Matching rounded corners */
        border: none;
        font-weight: 600;
        font-size: 1.1rem;
        width: 100%;
        transition: background 0.2s;
        text-decoration: none; /* For the link version */
        display: block; /* For the link version */
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
    .text-sm {
        font-size: 0.9rem;
    }

    /* MOBILE RESPONSIVENESS */
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
            <h3><?php echo $header_title; ?></h3>
            <p><?php echo $header_text; ?></p>
        </div>
        
        <div class="register-body">
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>

            <?php if ($redirect_to_login): ?>
                <div class="text-center mt-3">
                    <p class="text-success fw-bold fs-5 mb-4">Registration Successful! 🎉</p>
                    <a href="login.php" class="btn-register"><i class="fas fa-sign-in-alt me-2"></i>Go to Login</a>
                </div>
            <?php elseif (!empty($email) && isset($_SESSION['registration_data']) && $_SESSION['registration_data']['email'] === $email): ?>
                
                <div class="text-center mb-4">
                    <p class="text-muted">Verification code sent to:</p>
                    <p class="fw-bold fs-5 text-primary"><?php echo htmlspecialchars($email); ?></p>
                    <p class="text-sm text-muted">Please enter the 6-digit code to finalize your account.</p>
                </div>
                
                <form action="verify_otp.php?email=<?php echo urlencode($email); ?>" method="POST">
                    <div class="mb-4">
                        <input class="form-control otp-input" type="text" name="otp_code" maxlength="6" pattern="\d{6}" placeholder="------" required autofocus>
                    </div>
                    <button type="submit" class="btn-register mb-3">
                        <i class="fas fa-check-circle me-2"></i>Verify Account
                    </button>
                </form>

                <div class="text-center mt-3">
                    <p class="text-muted">
                        Didn't receive the code? 
                        <a href="verify_otp.php?email=<?php echo urlencode($email); ?>&resend=true" class="text-decoration-none fw-semibold">
                            <i class="fas fa-redo-alt me-1"></i>Resend Code
                        </a>
                    </p>
                </div>
                <div class="text-center mt-4">
                    <a href="register.php" class="text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Cancel Registration</a>
                </div>

            <?php else: ?>
                <div class="alert alert-info">
                    You must start the registration process first. Click <a href="register.php" class="text-decoration-none fw-semibold">here</a> to register.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>