<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email_config.php';

// This page allows a forced password change without OTP after an email revert.
$userId = $_SESSION['force_pw_user_id'] ?? null;
$ok = false; $err = '';
if (!$userId) {
    // Fallback: do not allow if no session marker
    header('Location: /Project_A2/login.php');
    exit;
}

// Fetch account linkage
$stmt = $pdo->prepare('SELECT a.password_id FROM account a WHERE a.user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$acc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$acc || empty($acc['password_id'])) {
    $err = 'Account not found.';
}

// Handle AJAX skip request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['ajax'] ?? '') === 'skip')) {
  header('Content-Type: application/json');
  if (isset($_SESSION['force_pw_user_id'])) {
    unset($_SESSION['force_pw_user_id']);
    echo json_encode(['ok' => true]);
  } else {
    echo json_encode(['ok' => false, 'message' => 'No pending password change.']);
  }
  exit;
}

// Handle password change (supports both normal POST and AJAX JSON response)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $isAjax = (($_POST['ajax'] ?? '') === 'change_pw') || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
  $current = $_POST['current_password'] ?? '';
  $new = $_POST['new_password'] ?? '';
  $confirm = $_POST['confirm_password'] ?? '';
  $localErr = '';
  if (strlen($new) < 8 || $new !== $confirm) {
    $localErr = 'Passwords must match and be at least 8 characters.';
  } else {
    // Verify current password
    $pw = $pdo->prepare('SELECT passkey FROM password WHERE password_id = ? LIMIT 1');
    $pw->execute([$acc['password_id']]);
    $row = $pw->fetch();
    if (!$row || !password_verify($current, $row['passkey'])) {
      $localErr = 'Incorrect current password.';
    } else {
      $hash = password_hash($new, PASSWORD_DEFAULT);
      $upd = $pdo->prepare('UPDATE password SET passkey = ? WHERE password_id = ?');
      $ok = $upd->execute([$hash, $acc['password_id']]);
      if ($ok) {
        unset($_SESSION['force_pw_user_id']);
        if ($isAjax) {
          header('Content-Type: application/json');
          echo json_encode(['ok' => true]);
          exit;
        } else {
          session_write_close();
          header('Location: /Project_A2/login.php');
          exit;
        }
      } else {
        $localErr = 'Failed to update password.';
      }
    }
  }
  if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => $localErr ?: 'Unable to change password.']);
    exit;
  } else {
    $err = $localErr;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <h3 class="mb-3">Change Password</h3>
          <?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
          <form method="POST">
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
            <div class="d-flex justify-content-end gap-2">
              <a href="/Project_A2/login.php" class="btn btn-secondary">Cancel</a>
              <button type="submit" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
