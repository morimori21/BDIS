<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'bdis');
define('DB_USER', 'root'); // Default XAMPP user
define('DB_PASS', ''); // Default XAMPP password

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}


// Start session only if one doesn't exist
// if (session_status() === PHP_SESSION_NONE) {
    
// }
if (session_status() === PHP_SESSION_NONE) {
    session_start();

}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}



function sanitize($data) {
    // Also strip out any potential NUL characters which can cause issues with BLOB binding
    return htmlspecialchars(trim(str_replace("\0", '', $data)), ENT_QUOTES, 'UTF-8');
}
function getUserRole() {
    return $_SESSION['role'] ?? null;
}

// Mask helpers used in profile views and elsewhere
if (!function_exists('mask_phone')) {
    function mask_phone($phone) {
        $s = preg_replace('/\D+/', '', (string)$phone);
        if ($s === '') return '';
        $len = strlen($s);
        if ($len <= 4) return str_repeat('*', $len);
        return str_repeat('*', max(0, $len - 4)) . substr($s, -4);
    }
}

if (!function_exists('mask_email')) {
    function mask_email($email) {
        $email = trim((string)$email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $l = strlen($local);
        if ($l <= 1) {
            $maskedLocal = '*';
        } elseif ($l === 2) {
            $maskedLocal = substr($local, 0, 1) . '*';
        } else {
            $maskedLocal = substr($local, 0, 1) . str_repeat('*', $l - 2) . substr($local, -1);
        }
        return $maskedLocal . '@' . $domain;
    }
}

function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        header('Location: /Project_A2/login.php');
        exit;
    }
}

function redirectIfNotRole($role) {
    if (getUserRole() !== $role) {
        header('Location: /Project_A2/unauthorized.php');
        exit;
    }
}

// Helper function to check if user has resident-level access
function hasResidentAccess() {
    $role = getUserRole();
    $resident_roles = ['resident', 'councilor', 'sk_chairman', 'treasurer'];
    return in_array($role, $resident_roles);
}

function redirectIfNotResident() {
    if (!hasResidentAccess()) {
        header('Location: /Project_A2/unauthorized.php');
        exit;
    }
}

function logActivity($actor_id, $action, $details = []) {
    global $pdo;

    // 🧩 Fetch actor info
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.surname, ur.role
        FROM users u
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$actor_id]);
    $actor = $stmt->fetch();

    $actor_name = trim(($actor['first_name'] ?? 'Unknown') . ' ' . ($actor['surname'] ?? ''));
    $actor_role = ucfirst($actor['role'] ?? 'Unknown Role');

    $action_clean = strtolower(trim($action));
    $action_sentence = '';
    $log_detail = '';

    // 🧠 Smart detection: role change
    if (str_contains($action_clean, 'change role') || str_contains($action_clean, 'changed role')) {
        $target_id = $details['target_user_id'] ?? null;
        $new_role = ucfirst($details['new_role'] ?? 'Unknown');
        $old_role = ucfirst($details['old_role'] ?? 'Unknown');

        $target_name = 'Unknown User';
        if ($target_id) {
            $tstmt = $pdo->prepare("
                SELECT first_name, surname 
                FROM users 
                WHERE user_id = ? 
                LIMIT 1
            ");
            $tstmt->execute([$target_id]);
            if ($tuser = $tstmt->fetch()) {
                $target_name = trim(($tuser['first_name'] ?? '') . ' ' . ($tuser['surname'] ?? ''));
            }
        }

        $action_sentence = "$actor_name changed the role of $target_name from $old_role to $new_role.";
        $log_detail = ", changed the role of $target_name from $old_role to $new_role";
        $action = "Changed user role";
    }

    // 🧩 Simpler actions
    elseif (preg_match('/\b(log ?in|logged ?in)\b/i', $action_clean)) {
        $action_sentence = "$actor_name logged into the system.";
        $log_detail = "logged into the system";
        $action = "Logged in";
    }
    elseif (preg_match('/\b(log ?out|logged ?out)\b/i', $action_clean)) {
        $action_sentence = "$actor_name logged out of the system.";
        $log_detail = "logged out of the system";
        $action = "Logged out";
    }
    elseif (in_array($action_clean, ['approve user', 'reject user'])) {
        switch ($action_clean) {
            case 'approve user':
                $target_name = fetchUserName($pdo, $details['target_user_id'] ?? null);
                $action_sentence = "$actor_name approved the registration of $target_name.";
                $log_detail = "approved the registration of $target_name";
                $action = "Approved user";
                break;

            case 'reject user':
                $target_name = fetchUserName($pdo, $details['target_user_id'] ?? null);
                $remarks = $details['remarks'] ?? null;

                if ($remarks) {
                    $action_sentence = "$actor_name rejected the registration of $target_name. Reason: \"$remarks\".";
                    $log_detail = "rejected the registration of $target_name. Reason: \"$remarks\"";
                } else {
                    $action_sentence = "$actor_name rejected the registration of $target_name.";
                    $log_detail = "rejected the registration of $target_name";
                }
                $action = "Rejected user";
                break;
        }
    }

    // 🧩 Fallback
    else {
        $action_sentence = "$actor_name performed the action: " . ucfirst($action) . ".";
        $log_detail = "performed the action: " . ucfirst($action);
        $action = ucfirst($action);
    }

    // 🪵 Store clean detail
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, action_details, action_time)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$actor_id, $action, $log_detail]);
}


/**
 * Fetches required barangay address details for auto-filling forms.
 * Returns an array with default values if fetching fails.
 */
function getBarangayDetails() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT brgy_name, municipality, province, brgy_logo, city_logo FROM address_config LIMIT 1");
        $barangay = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($barangay) {
            // Convert BLOB to base64 for display
            $brgy_logo_src = '/Project_A2/assets/images/default_logo.png'; // Default logo
            if (!empty($barangay['brgy_logo'])) {
                $brgy_logo_src = 'data:image/png;base64,' . base64_encode($barangay['brgy_logo']);
            }
            
            $city_logo_src = '/Project_A2/assets/images/default_logo.png'; // Default logo
            if (!empty($barangay['city_logo'])) {
                $city_logo_src = 'data:image/png;base64,' . base64_encode($barangay['city_logo']);
            }

            return [
                'brgy_name'    => $barangay['brgy_name'] ?? 'Barangay Demo',
                'municipality' => $barangay['municipality'] ?? 'Default City',
                'province'     => $barangay['province'] ?? 'Default Province',
                'captain_name' => 'Barangay Captain',
                'brgy_logo_src'=> $brgy_logo_src,
                'brgy_logo' => $barangay['brgy_logo'] ?? null,
                'city_logo' => $barangay['city_logo'] ?? null,
                'city_logo_src' => $city_logo_src
            ];
        }
    } catch (PDOException $e) {
        // Fall through to defaults
    }

    return [
        'brgy_name'    => 'Barangay Demo',
        'municipality' => 'Default City',
        'province'     => 'Default Province',
        'captain_name' => 'Barangay Captain',
        'brgy_logo_src'=> '/Project_A2/assets/images/default_logo.png',
        'brgy_logo' => null,
        'city_logo' => null,
        'city_logo_src' => '/Project_A2/assets/images/default_logo.png'
    ];
}


function getbrgyName($role = '') {
    // Get barangay details
    $barangay = getBarangayDetails();
    $barangayName = $barangay['brgy_name'] ?? 'BDIS';

    // Optional role text
    $roleText = $role ? ucfirst($role) . ' - ' : '';

    // Build the HTML with just the barangay name
    $html = '<div class="d-flex align-items-center">';
    $html .= '<div>';
    $html .= '<div class="fw-bold">' . htmlspecialchars($barangayName) . '</div>';
    if ($role) {
        $html .= '<small class="text-muted">' . $roleText . 'Portal</small>';
    }
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

function getNavbarBrand($role = '') {
    // ... (This function remains unchanged as it is not used in the register flow)
    $barangay = getBarangayDetails();
    $barangayName = $barangay['brgy_name'] ?? 'BDIS';
    $municipality = $barangay['municipality'] ?? '';
    $province = $barangay['province'] ?? '';
    $logoPath = $barangay['brgy_logo_src'] ?? '';
    
    $roleText = $role ? ucfirst($role) . ' - ' : '';
    
    // Build the location text
    $locationParts = array_filter([$barangayName, $municipality, $province]);
    $locationText = implode(', ', $locationParts);
    
    $html = '<div class="d-flex align-items-center">';
    
    $html .= '<div>';
    $html .= '<div class="fw-bold">' . $locationText . '</div>';
    if ($role) {
        $html .= '<small class="text-muted">' . $roleText . 'Portal</small>';
    }
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

function Logo($context = 'default') {
    $barangay = getBarangayDetails();
    $logoSrc = $barangay['brgy_logo_src'] ?? '/Project_A2/assets/images/default_logo.png';
    
    if ($context === 'sidebar') {
        return '<img src="' . $logoSrc . '" alt="Logo">';
    }
    
    // For other uses (like navbar), use smaller size
    return '<img src="' . $logoSrc . '" alt="Logo" style="height: 80px; width: auto;">';
}



if (!function_exists('formatNumberShort')) {
    function formatNumberShort($number) {
        if ($number >= 1000000000) {
            return round($number / 1000000000, 1) . 'B';
        } elseif ($number >= 1000000) {
            return round($number / 1000000, 1) . 'M';
        } elseif ($number >= 1000) {
            return round($number / 1000, 1) . 'k';
        } else {
            return $number;
        }
    }
}
?>

