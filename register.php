<?php 
// CRITICAL: Must be at the very top before any output

require_once 'includes/config.php';
require 'vendor/autoload.php'; // PHPMailer
require_once __DIR__ . '/includes/email_config.php';
if(isset($_SESSION['user_id'])){
    header('location: /Project_A2/index.php');
    exit; // Added exit for safety
}
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- SET TIMEZONE TO ASIA/MANILA (GMT +8) ---
date_default_timezone_set('Asia/Manila');

// Fetch Barangay details for address auto-fill
$barangay_address = getBarangayDetails();

$error_message = ''; // Initialize error message

// Function to safely retrieve POST data for form persistence
function get_post_value($key) {
    return htmlspecialchars($_POST[$key] ?? '');
}

/* ---------------------------
    FETCH LOGO FROM DATABASE
---------------------------- */
global $pdo;
$logoStmt = $pdo->query("SELECT brgy_logo FROM address_config LIMIT 1");
$logoData = $logoStmt->fetch(PDO::FETCH_ASSOC);

$logoImage = "";
if (!empty($logoData['brgy_logo'])) {
    $logoImage = "data:image/png;base64," . base64_encode($logoData['brgy_logo']);
}
/* ---------------------------
    END FETCH LOGO
---------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // --- Basic User Info Retrieval & Sanitization ---
        $first_name = sanitize($_POST['first_name']);
        $middle_name = sanitize($_POST['middle_name'] ?? '');
        $surname = sanitize($_POST['surname']);
        $suffix = sanitize($_POST['suffix'] ?? '');
        $email = sanitize($_POST['email']);
        $plain_password = $_POST['password']; 
        $confirm_password = $_POST['confirm_password'];
        $street = sanitize($_POST['street']);
        $contact_number = sanitize($_POST['contact_number']);
        $birthdate = $_POST['birthdate'];
        $sex = sanitize($_POST['sex']);
        $id_type = sanitize($_POST['id_type']);
        
        // --- Validation ---
        
        // 1. Password Strength Validation (Must match JavaScript logic)
        // Check password length (at least 8 chars)
        if (strlen($plain_password) < 8) {
            throw new Exception("Password must be at least 8 characters long.");
        }
        // Check for uppercase
        if (!preg_match('/[A-Z]/', $plain_password)) {
            throw new Exception("Password must contain at least one uppercase letter.");
        }
        // Check for lowercase
        if (!preg_match('/[a-z]/', $plain_password)) {
            throw new Exception("Password must contain at least one lowercase letter.");
        }
        // Check for number
        if (!preg_match('/[0-9]/', $plain_password)) {
            throw new Exception("Password must contain at least one number.");
        }
        // Check for special character (optional, but good for security)
        if (!preg_match('/[^A-Za-z0-9]/', $plain_password)) {
            throw new Exception("Password must contain at least one special character.");
        }
        
        if (empty($first_name) || empty($surname) || empty($email) || empty($plain_password) || empty($confirm_password) || empty($street) || empty($contact_number) || empty($birthdate) || empty($sex) || empty($id_type)) {
            throw new Exception("Please fill in all required fields.");
        }
        
        // 2. Password Match Validation
        if ($plain_password !== $confirm_password) {
            throw new Exception("Password and Confirm Password do not match.");
        }
        $password = password_hash($plain_password, PASSWORD_DEFAULT);

        // 3. Contact Number Validation (11-digit check)
        if (!preg_match('/^09[0-9]{9}$/', $contact_number)) {
            throw new Exception("Contact number must start with '09' and be exactly 11 numeric digits.");
        }
        
        // 4. Email Uniqueness Check
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM email WHERE email = ?");
        $stmt_check->execute([$email]);
        if ($stmt_check->fetchColumn() > 0) {
            throw new Exception("The email address is already registered.");
        }


        // --- Handle ID Uploads for Front and Back Separately ---
        $front_img = null;
        $back_img = null;

        // Process Front ID (MANDATORY)
        if (!isset($_FILES['front_id_photo']) || $_FILES['front_id_photo']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception("Please upload the front side of your valid ID.");
        }
        if ($_FILES['front_id_photo']['error'] === UPLOAD_ERR_OK && is_uploaded_file($_FILES['front_id_photo']['tmp_name'])) {
            $front_img = file_get_contents($_FILES['front_id_photo']['tmp_name']);
        } else {
            throw new Exception("Front ID upload failed: Error code " . $_FILES['front_id_photo']['error']);
        }

        // Process Back ID (OPTIONAL)
        if (isset($_FILES['back_id_photo']) && $_FILES['back_id_photo']['error'] === UPLOAD_ERR_OK && is_uploaded_file($_FILES['back_id_photo']['tmp_name'])) {
            $back_img = file_get_contents($_FILES['back_id_photo']['tmp_name']);
        }

        // --- Store Data in Session (Excluding Role) ---
        $_SESSION['registration_data'] = [
            'email' => $email,
            'password' => $password, // Hashed password
            'first_name' => $first_name,
            'middle_name' => $middle_name,
            'surname' => $surname,
            'suffix' => $suffix,
            'contact_number' => $contact_number,
            'birthdate' => $birthdate,
            'sex' => $sex,
            'street' => $street,
            'id_type' => $id_type,
            'front_id' => $front_img,
            'back_id' => $back_img, // Can be NULL
            // IMPORTANT: No 'role' field is stored here.
        ];

        // --- OTP Generation and Sending ---
        $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        // 1. Get or create email_id for this email
        $emailStmt = $pdo->prepare("SELECT email_id FROM email WHERE email = ?");
        $emailStmt->execute([$email]);
        $emailResult = $emailStmt->fetch();
        
        if ($emailResult) {
            $email_id = $emailResult['email_id'];
            // Delete existing OTP for this email
            $pdo->prepare("DELETE FROM email_verifications WHERE email_id = ?")->execute([$email_id]);
        } else {
            // Create new email entry if it doesn't exist
            $insertEmailStmt = $pdo->prepare("INSERT INTO email (email) VALUES (?)");
            $insertEmailStmt->execute([$email]);
            $email_id = $pdo->lastInsertId();
        }
        
        // Store OTP in database
        $stmt = $pdo->prepare("INSERT INTO email_verifications (email_id, otp_code, otp_expires_at)
                             VALUES (?, ?, ?)");
        $stmt->execute([$email_id, $otp_code, $expires_at]);

        // 2. Send email via shared email helper
        $toName = $first_name . ' ' . $surname;
        sendOTPEmail($email, trim($toName), $otp_code);

        // --- Redirect to OTP verification page ---
        header("Location: verify_otp.php?email=" . urlencode($email));
        exit;

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Registration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        /* Base White Theme */
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5 !important; /* Light gray background */
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 0;
            overflow-y: auto;
        }

        /* LOGO WATERMARK CONTAINER (Desktop background logo) */
        #logo-watermark {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 0; /* Behind everything */
        }

        #logo-watermark img {
            width: 1200px; /* Large size */
            height: auto;
            opacity: 0.25; /* Fading applied directly to the image */
        }

        /* Fading Overlay (Ensures forms/text are readable) */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: rgba(255, 255, 255, 0.40); /* Light white overlay */
            z-index: 1; /* Above logo, below card */
            pointer-events: none;
        }

        /* Card/Form Styling */
        .register-container {
            position: relative;
            z-index: 10;
            max-width: 900px;
            width: 100%;
        }

        .register-card {
            background: #ffffff; /* White card background */
            border-radius: 15px;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
            padding: 0;
        }

        .register-header {
            padding: 25px 35px 15px;
            text-align: center;
            border-bottom: 1px solid #eee;
        }

        .register-header h3 {
            font-weight: 700;
            color: #333;
        }

        .register-body {
            padding: 35px;
        }

        /* Input Styling */
        .form-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ddd;
            padding: 10px 15px;
        }

        .form-control:focus, .form-select:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 0.15rem rgba(79, 70, 229, 0.25);
        }
        
        .section-title {
            font-weight: 700;
            color: #4f46e5;
            margin-top: 15px;
            margin-bottom: 8px;
            font-size: 1.1em;
            border-bottom: 2px solid #e0e0f0;
            padding-bottom: 5px;
        }

        /* Password Instruction Styling */
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

        /* Button Styling */
        .btn-register {
            background: #4f46e5;
            color: white;
            padding: 12px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            font-size: 1.1rem;
            width: 100%;
            transition: background 0.2s;
        }

        .btn-register:hover {
            background: #4338ca;
        }

        /* Link Styling */
        .text-decoration-none {
            color: #4f46e5 !important;
        }
        
        /* NEW: Styling for the Mobile Logo Container (Hidden on Desktop) */
        #mobile-logo-container {
            display: none; /* Hidden by default (desktop view) */
            margin-bottom: 10px;
        }
        
        #mobile-logo-container img {
            width: 80px; /* Set a small size for mobile */
            height: auto;
            border-radius: 50%;
        }

        /* -------------------------------------
           MEDIA QUERY: Styles for Mobile View 
        ------------------------------------- */
        @media (max-width: 768px) {
            /* 1. Hide the Large Watermark on small screens */
            #logo-watermark {
                display: none; 
            }

            /* 2. Show and Center the Mobile Logo */
            #mobile-logo-container {
                display: block; 
                text-align: center; /* Center the image element */
            }
            
            /* Adjust card padding for smaller screens */
            .register-card {
                margin: 10px;
            }
        }
        /* END MEDIA QUERY */
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
            <div id="mobile-logo-container">
                <?php if (!empty($logoImage)): ?>
                    <img src="<?php echo $logoImage; ?>" alt="Barangay Logo">
                <?php endif; ?>
            </div>
            <h3>Resident Registration</h3>
            <p class="text-muted">Register to access Barangay services.</p>
        </div>
        <div class="register-body">
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <form action="register.php" method="POST" enctype="multipart/form-data">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required value="<?php echo get_post_value('first_name'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="middle_name" class="form-label">Middle Name</label>
                        <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo get_post_value('middle_name'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="surname" class="form-label">Surname <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="surname" name="surname" required value="<?php echo get_post_value('surname'); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="suffix" class="form-label">Suffix</label>
                        <select class="form-select" id="suffix" name="suffix">
                            <option value="" selected>N/A</option>
                            <option value="Jr." <?php if(get_post_value('suffix') == 'Jr.') echo 'selected'; ?>>Jr.</option>
                            <option value="Sr." <?php if(get_post_value('suffix') == 'Sr.') echo 'selected'; ?>>Sr.</option>
                            <option value="III" <?php if(get_post_value('suffix') == 'III') echo 'selected'; ?>>III</option>
                            <option value="IV" <?php if(get_post_value('suffix') == 'IV') echo 'selected'; ?>>IV</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" required value="<?php echo get_post_value('email'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="contact_number" class="form-label">Contact Number (e.g., 09xxxxxxxxx) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="contact_number" name="contact_number" maxlength="11" pattern="09[0-9]{9}" required value="<?php echo get_post_value('contact_number'); ?>">
                    </div>
                </div>
                
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <ul id="passwordRequirements" class="password-requirements">
                            <li id="length"><i class="fas fa-times-circle"></i> Minimum 8 characters</li>
                            <li id="uppercase"><i class="fas fa-times-circle"></i> At least one **uppercase** letter (A-Z)</li>
                            <li id="lowercase"><i class="fas fa-times-circle"></i> At least one **lowercase** letter (a-z)</li>
                            <li id="number"><i class="fas fa-times-circle"></i> At least one **number** (0-9)</li>
                            <li id="special"><i class="fas fa-times-circle"></i> At least one **special character** (!@#$...)</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>

                <p class="section-title">Address Details</p>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label for="street" class="form-label">Street / Purok <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="street" name="street" required value="<?php echo get_post_value('street'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="barangay" class="form-label">Barangay</label>
                        <input type="text" class="form-control bg-light text-muted" id="barangay" name="barangay" value="<?php echo htmlspecialchars($barangay_address['brgy_name']); ?>" readonly>
                    </div>
                    <div class="col-md-3">
                        <label for="city" class="form-label">City/Municipality</label>
                        <input type="text" class="form-control bg-light text-muted" id="city" name="city" value="<?php echo htmlspecialchars($barangay_address['municipality']); ?>" readonly>
                    </div>
                    <div class="col-md-3">
                        <label for="province" class="form-label">Province</label>
                        <input type="text" class="form-control bg-light text-muted" id="province" name="province" value="<?php echo htmlspecialchars($barangay_address['province']); ?>" readonly>
                    </div>
                </div>

                <p class="section-title">Personal Information & ID</p>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label for="birthdate" class="form-label">Birthdate <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="birthdate" name="birthdate" required value="<?php echo get_post_value('birthdate'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="sex" class="form-label">Sex <span class="text-danger">*</span></label>
                        <select class="form-select" id="sex" name="sex" required>
                            <option value="" disabled selected>Select Sex</option>
                            <option value="Male" <?php if(get_post_value('sex') == 'Male') echo 'selected'; ?>>Male</option>
                            <option value="Female" <?php if(get_post_value('sex') == 'Female') echo 'selected'; ?>>Female</option>
                            <option value="Other" <?php if(get_post_value('sex') == 'Other') echo 'selected'; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="id_type" class="form-label">Type of Valid ID <span class="text-danger">*</span></label>
                        <select class="form-select" id="id_type" name="id_type" required>
                            <option value="" disabled selected>Select ID Type</option>
                            <option value="Passport" <?php if(get_post_value('id_type') == 'Passport') echo 'selected'; ?>>Passport</option>
                            <option value="Driver's License" <?php if(get_post_value('id_type') == "Driver's License") echo 'selected'; ?>>Driver's License</option>
                            <option value="Postal ID" <?php if(get_post_value('id_type') == 'Postal ID') echo 'selected'; ?>>Postal ID</option>
                            <option value="Voter's ID" <?php if(get_post_value('id_type') == "Voter's ID") echo 'selected'; ?>>Voter's ID</option>
                            <option value="SSS/UMID" <?php if(get_post_value('id_type') == 'SSS/UMID') echo 'selected'; ?>>SSS/UMID</option>
                            <option value="PRC ID" <?php if(get_post_value('id_type') == 'PRC ID') echo 'selected'; ?>>PRC ID</option>
                            <option value="Other Government ID" <?php if(get_post_value('id_type') == 'Other Government ID') echo 'selected'; ?>>Other Government ID</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="front_id_photo" class="form-label">Front Side of Valid ID <span class="text-danger">*</span></label>
                        <input class="form-control" type="file" id="front_id_photo" name="front_id_photo" accept="image/*" required>
                    </div>
                    <div class="col-md-6">
                        <label for="back_id_photo" class="form-label">Back Side of Valid ID (Optional)</label>
                        <input class="form-control" type="file" id="back_id_photo" name="back_id_photo" accept="image/*">
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn-register">Register & Verify Email</button>
                </div>
                
                <p class="text-center mt-3 mb-0 text-muted">
                    Already have an account? <a href="login.php" class="text-decoration-none fw-semibold">Login here</a>
                </p>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    const passwordRequirements = document.getElementById('passwordRequirements'); 
    
    // Define the requirements and their corresponding list items
    const requirements = {
        length: { regex: /.{8,}/, element: document.getElementById('length') },
        uppercase: { regex: /[A-Z]/, element: document.getElementById('uppercase') },
        lowercase: { regex: /[a-z]/, element: document.getElementById('lowercase') },
        number: { regex: /[0-9]/, element: document.getElementById('number') },
        special: { regex: /[^A-Za-z0-9]/, element: document.getElementById('special') }
    };

    passwordInput.addEventListener('keyup', function() {
        const password = passwordInput.value;

        // Logic to show/hide the UL container
        if (password.length > 0) {
            passwordRequirements.style.display = 'block';
        } else {
            passwordRequirements.style.display = 'none';
        }

        // Logic to update the list item status (disappearing act)
        for (const key in requirements) {
            const req = requirements[key];
            const isMet = req.regex.test(password);
            
            // If the requirement is met, HIDE the list item
            if (isMet) {
                req.element.style.display = 'none';
            } else {
                // If the requirement is NOT met, SHOW the list item
                req.element.style.display = 'list-item';
            }
        }
    });
});
</script>
</body>
</html> 