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
        $id_type = $_POST['id_type'];
        
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
        
   
        if ($plain_password !== $confirm_password) {
            throw new Exception("Password and Confirm Password do not match.");
        }
        $password = password_hash($plain_password, PASSWORD_DEFAULT);


        if (!preg_match('/^09[0-9]{9}$/', $contact_number)) {
            throw new Exception("Contact number must start with '09' and be exactly 11 numeric digits.");
        }
        

        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM email WHERE email = ?");
        $stmt_check->execute([$email]);
        if ($stmt_check->fetchColumn() > 0) {
            throw new Exception("The email address is already registered.");
        }



        $front_img = null;
        $back_img = null;


        if (!isset($_FILES['front_id_photo']) || $_FILES['front_id_photo']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception("Please upload the front side of your valid ID.");
        }
        if ($_FILES['front_id_photo']['error'] === UPLOAD_ERR_OK && is_uploaded_file($_FILES['front_id_photo']['tmp_name'])) {
            $front_img = file_get_contents($_FILES['front_id_photo']['tmp_name']);
        } else {
            throw new Exception("Front ID upload failed: Error code " . $_FILES['front_id_photo']['error']);
        }

    
        if (isset($_FILES['back_id_photo']) && $_FILES['back_id_photo']['error'] === UPLOAD_ERR_OK && is_uploaded_file($_FILES['back_id_photo']['tmp_name'])) {
            $back_img = file_get_contents($_FILES['back_id_photo']['tmp_name']);
        }


        $_SESSION['registration_data'] = [
            'email' => $email,
            'password' => $password,
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
            'back_id' => $back_img,

        ];


        $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        

        $emailStmt = $pdo->prepare("SELECT email_id FROM email WHERE email = ?");
        $emailStmt->execute([$email]);
        $emailResult = $emailStmt->fetch();
        
        if ($emailResult) {
            $email_id = $emailResult['email_id'];
      
            $pdo->prepare("DELETE FROM email_verifications WHERE email_id = ?")->execute([$email_id]);
        } else {
            $insertEmailStmt = $pdo->prepare("INSERT INTO email (email) VALUES (?)");
            $insertEmailStmt->execute([$email]);
            $email_id = $pdo->lastInsertId();
        }
        

        $stmt = $pdo->prepare("INSERT INTO email_verifications (email_id, otp_code, otp_expires_at)
                             VALUES (?, ?, ?)");
        $stmt->execute([$email_id, $otp_code, $expires_at]);

   
        $toName = $first_name . ' ' . $surname;
        sendOTPEmail($email, trim($toName), $otp_code);

 
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
            background: #f0f2f5 !important;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 0;
            overflow-y: auto;
        }

        #logo-watermark {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 0; 
        }

        #logo-watermark img {
            width: 1200px; 
            height: auto;
            opacity: 0.25;
        }


        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: rgba(255, 255, 255, 0.40); 
            z-index: 1;
            pointer-events: none;
        }


        .register-container {
            position: relative;
            z-index: 10;
            max-width: 900px;
            width: 100%;
        }

        .register-card {
            background: #ffffff; 
            border-radius: 15px;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
            padding: 0;
        }

        /* Hide Edge/IE native password reveal button to avoid duplicate toggles */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none;
        }
        /* Some Chromium variants expose a credentials autofill button; hide if present */
        input[type="password"]::-webkit-credentials-auto-fill-button {
            visibility: hidden;
            display: none;
            pointer-events: none;
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


        .text-decoration-none {
            color: #4f46e5 !important;
        }
        

        #mobile-logo-container {
            display: none;
            margin-bottom: 10px;
        }
        
        #mobile-logo-container img {
            width: 80px; 
            height: auto;
            border-radius: 50%;
        }

        @media (max-width: 768px) {
            #logo-watermark {
                display: none; 
            }


            #mobile-logo-container {
                display: block; 
                text-align: center; 
            }
        
            .register-card {
                margin: 10px;
            }
        }

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
                
                <div class="row">
                    <!-- PASSWORD -->
                    <div class="col-md-6">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" required>
                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>

                        <ul id="passwordRequirements" class="password-requirements mt-2">
                            <li id="length"><i class="fas fa-times-circle"></i> Minimum 8 characters</li>
                            <li id="uppercase"><i class="fas fa-times-circle"></i> At least one uppercase letter</li>
                            <li id="lowercase"><i class="fas fa-times-circle"></i> At least one lowercase letter</li>
                            <li id="number"><i class="fas fa-times-circle"></i> At least one number</li>
                            <li id="special"><i class="fas fa-times-circle"></i> At least one special character</li>
                        </ul>
                    </div>

                    <!-- CONFIRM PASSWORD -->
                    <div class="col-md-6">
                        <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>

                        <!-- LIVE WARNING -->
                        <p id="matchWarning" class="text-danger mt-2" style="display:none; font-size: 0.9rem;">
                            ⚠ Passwords do not match.
                        </p>
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

                <div id="id_upload_section" class="row g-3 mb-4" style="display: none;">
                    <div class="col-md-6">
                        <label for="front_id_photo" class="form-label">Front Side of Valid ID <span class="text-danger">*</span></label>
                        <input class="form-control" type="file" id="front_id_photo" name="front_id_photo" accept="image/*" required>
                    </div>
                    <div class="col-md-6">
                        <label for="back_id_photo" class="form-label">Back Side of Valid ID (Optional)</label>
                        <input class="form-control" type="file" id="back_id_photo" name="back_id_photo" accept="image/*">
                    </div>
                </div>


   <div class="mb-3">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="termsCheck">
        <label class="form-check-label" for="termsCheck">
            I agree to the <a href="#" class="text-decoration-none">Terms and Conditions</a>.
        </label>
    </div>

    <!-- Hidden Warning -->
    <p id="termsWarning" class="text-danger mt-1" style="display:none; font-size: 0.9rem;">
        ⚠ Please agree to the Terms and Conditions before proceeding.
    </p>
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

//PASSWORD STRENGTH VALIDATION
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    const passwordRequirements = document.getElementById('passwordRequirements'); 
    
    const requirements = {
        length: { regex: /.{8,}/, element: document.getElementById('length') },
        uppercase: { regex: /[A-Z]/, element: document.getElementById('uppercase') },
        lowercase: { regex: /[a-z]/, element: document.getElementById('lowercase') },
        number: { regex: /[0-9]/, element: document.getElementById('number') },
        special: { regex: /[^A-Za-z0-9]/, element: document.getElementById('special') }
    };

    passwordInput.addEventListener('keyup', function() {
        const password = passwordInput.value;

        if (password.length > 0) {
            passwordRequirements.style.display = 'block';
        } else {
            passwordRequirements.style.display = 'none';
        }
        for (const key in requirements) {
            const req = requirements[key];
            const isMet = req.regex.test(password);

            if (isMet) {
                req.element.style.display = 'none';
            } else {
                req.element.style.display = 'list-item';
            }
        }
    });
});


// ID TYPE SELECTION AND UPLOAD DISPLAY
document.getElementById('id_type').addEventListener('change', function () {
    let section = document.getElementById('id_upload_section');

    if (this.value !== "") {
        section.style.display = "flex"; // or "block"
        document.getElementById('front_id_photo').required = true;
    } else {
        section.style.display = "none";
        document.getElementById('front_id_photo').required = false;
    }
});


//SHOW PASSWORD TOGGLE
function attachToggle(buttonId, inputId) {
    const btn = document.getElementById(buttonId);
    const input = document.getElementById(inputId);

    btn.addEventListener('click', () => {
        const isHidden = input.type === "password";
        input.type = isHidden ? "text" : "password";
        btn.querySelector("i").classList.toggle("fa-eye");
        btn.querySelector("i").classList.toggle("fa-eye-slash");
    });
}

attachToggle("togglePassword", "password");
attachToggle("toggleConfirmPassword", "confirm_password");

const pass = document.getElementById("password");
const cpass = document.getElementById("confirm_password");
const warning = document.getElementById("matchWarning");

function checkMatch() {
    if (cpass.value.length === 0) {
        warning.style.display = "none";
        return;
    }
    if (pass.value !== cpass.value) {
        warning.style.display = "block"; 
    } else {
        warning.style.display = "none"; 
    }
}

//PASSWORD MATCH CHECK
pass.addEventListener("input", checkMatch);
cpass.addEventListener("input", checkMatch);


// TERMS AND CONDITIONS
document.querySelector("form").addEventListener("submit", function(e) {
    const termsCheck = document.getElementById('termsCheck');
    const warning = document.getElementById('termsWarning');

    if (!termsCheck.checked) {
        e.preventDefault(); 
        warning.style.display = 'block'; 
    } else {
        warning.style.display = 'none'; 
    }
});
</script>
</body>
</html> 