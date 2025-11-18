<?php 
require_once 'includes/config.php';

if(isset($_SESSION['user_id'])){
    header('location: /Project_A2/index.php');
    exit;
}

$error = null;
$success = null;
$redirectUrl = null;

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
    LOGIN LOGIC
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitize($_POST['email']); 
    $password = $_POST['password'];

    $stmt = $pdo->prepare("
        SELECT 
            u.user_id, 
            p.passkey,
            COALESCE(ur.role, 'user') AS role, 
            u.status 
        FROM email e
        JOIN account a ON e.email_id = a.email_id
        JOIN users u ON a.user_id = u.user_id
        JOIN password p ON a.password_id = p.password_id
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        WHERE e.email = ?
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $password_ok = password_verify($password, $user['passkey']);
        $status_ok = ($user['status'] == 'verified');

        if ($password_ok && $status_ok) {

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role'] = $user['role'];

            if (function_exists('logActivity')) { 
                logActivity($user['user_id'], 'Logged in');
            }

            $success = 'Login successful. Redirecting to your dashboard...';
            $redirectUrl = '/Project_A2/index.php';

        } elseif (!$status_ok) {
            $error = "Account not yet verified. Please check your email.";
        } else {
            $error = "Invalid email or password.";
        }
    } else {
        $error = "Invalid email or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BDIS - Login</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #ffffff !important;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center; /* Centers vertically */
            justify-content: center; /* Centers horizontally */
            background-image: none; 
            padding: 20px 0; /* Add some vertical padding for phones */
        }

        /* Fading Overlay */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: rgba(255, 255, 255, 0.10); 
            z-index: 1;
            pointer-events: none;
        }

        /* NEW LOGO WATERMARK CONTAINER (Desktop background logo) */
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
        /* END NEW LOGO WATERMARK CONTAINER */

        .login-card {
            position: relative;
            z-index: 10;
            background: #ffffff;
            border-radius: 20px;
            /* Desktop Max Width */
            max-width: 430px; 
            width: 100%;
            padding-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            /* Ensure the card scrolls if content is too large, though unlikely here */
            overflow-y: auto; 
        }

        /* --- UPDATED LOGIN HEADER STYLES --- */
        .login-header {
            padding: 30px;
            text-align: center;
            background-color: #f7f7f9; 
            border-radius: 20px 20px 0 0;
            border-bottom: 1px solid #e0e0e5; 
        }
        
        .header-logo-container img {
            width: 60px; /* Small logo size */
            height: auto;
            margin-bottom: 10px;
            border-radius: 50%;
            border: 2px solid #4f46e5; /* Accent border */
        }

        .login-header h3 {
            font-weight: 700;
            margin-top: 5px; 
            margin-bottom: 5px;
            color: #333;
            font-size: 1.4rem; 
        }

        .login-header h6 {
            font-weight: 400;
            color: #666;
            font-size: 1rem;
        }
        /* --- END UPDATED LOGIN HEADER STYLES --- */

        .login-body {
            padding: 30px;
        }

        .form-control {
            border-radius: 12px;
            border: 2px solid #e5e7eb;
            padding: 12px;
        }

        .form-control:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 0.15rem rgba(79, 70, 229, 0.2);
        }

        .btn-login {
            background: #4f46e5;
            color: white;
            padding: 12px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .btn-login:hover {
            background: #4338ca;
        }

        .forgot-password-link,
        .register-link {
            text-align: center;
            margin-top: 10px;
        }

        .forgot-password-link a,
        .register-link a {
            color: #4f46e5;
            font-weight: 600;
            text-decoration: none;
        }

        .forgot-password-link a:hover,
        .register-link a:hover {
            text-decoration: underline;
        }

        .alert {
            border-radius: 12px;
        }
        
        /* -------------------------------------
           MEDIA QUERY: MOBILE RESPONSIVENESS FIXES
        ------------------------------------- */
        @media (max-width: 500px) {
            /* 1. Card Width Fix: Take up most of the screen width with fixed padding */
            .login-card {
                max-width: 100%; /* Important: Remove desktop max-width constraint */
                width: 90%; 
                margin: 0 auto; /* Center the 90% width card */
            }
            
            /* 2. Padding Reduction for smaller phones */
            .login-header, .login-body {
                padding: 20px;
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
    
    <div class="login-card">
        <div class="login-header">
            <?php if (!empty($logoImage)): ?>
                <div class="header-logo-container">
                    <img src="<?php echo $logoImage; ?>" alt="Barangay Logo">
                </div>
            <?php endif; ?>
            <h3>Barangay Document Issuance System</h3>
            <h6>Please Enter Your Email and Password</h6>
        </div>
        <div class="login-body">

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <meta http-equiv="refresh" content="1;url=<?php echo $redirectUrl; ?>">
            <?php endif; ?>

            <form method="POST">
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" name="email" id="email" required>
                    <label for="email">Email</label>
                </div>

                <div class="form-floating mb-3 position-relative">
                    <input type="password" class="form-control" name="password" id="password" required>
                    <label for="password">Password</label>

                    <button type="button"
                            class="btn btn-sm text-secondary position-absolute top-50 end-0 translate-middle-y me-2"
                            id="togglePassword">
                    </button>
                </div>

                <div class="forgot-password-link">
                    <a href="/Project_A2/forgot_password.php">Forgot Password?</a>
                </div>

                <button type="submit" class="btn btn-login w-100 mt-3">
                    <i class="fas fa-sign-in-alt me-2"></i> Sign In
                </button>

            </form>

            <div class="register-link mt-3">
                <p>Don't have an account? <a href="/Project_A2/register.php">Create Account</a></p>
            </div>

        </div>
    </div>

<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const password = document.getElementById('password');
    const icon = this.querySelector('i');

    if (password.type === "password") {
        password.type = "text";
        icon.classList.replace("fa-eye", "fa-eye-slash");
    } else {
        password.type = "password";
        icon.classList.replace("fa-eye-slash", "fa-eye");
    }
});
</script>

</body>
</html>