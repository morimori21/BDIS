<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email_config.php';

$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']));
if ($isAjax && $_POST['ajax'] === 'skip_pw_change') {
  header('Content-Type: application/json');
  if (isset($_SESSION['force_pw_user_id'])) {
    unset($_SESSION['force_pw_user_id']);
    echo json_encode(['ok' => true]);
  } else {
    echo json_encode(['ok' => false, 'message' => 'No pending action to skip.']);
  }
  exit;
}

$token = $_GET['token'] ?? '';
$ok = false;
$msg = 'Invalid or expired link.';
$userId = null;
try {
    if ($token) {
        $stmt = $pdo->prepare('SELECT * FROM email_revert_tokens WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $expired = strtotime($row['expires_at'] ?? '1970-01-01') < time();
            if (!$expired && empty($row['used_at'])) {
                // Revert email
                $upd = $pdo->prepare('UPDATE email SET email = ? WHERE email_id = ?');
                $ok = $upd->execute([$row['old_email'], $row['email_id']]);
                if ($ok) {
                    $pdo->prepare('UPDATE email_revert_tokens SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
                    $msg = 'Email reverted successfully.';
                    $userId = (int)$row['user_id'];
                    $_SESSION['force_pw_user_id'] = $userId; // Allow forced change password page
                  // Broadcast a global logout for this user so other windows (even in different profiles) detect it via polling
                  try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS user_session_flags (
                      user_id INT PRIMARY KEY,
                      logout_broadcast_at DATETIME NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $up = $pdo->prepare('INSERT INTO user_session_flags (user_id, logout_broadcast_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE logout_broadcast_at = NOW()');
                    $up->execute([$userId]);
                  } catch (Throwable $ie) { /* ignore */ }
                } else {
                    $msg = 'Failed to revert email.';
                }
            } else {
                $msg = 'This link is no longer valid.';
            }
        }
    }
} catch (Throwable $e) {
    $msg = 'An error occurred.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Reverted</title>
<link href="/Project_A2/assets/css/style.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6">
      <div class="card shadow-sm">
        <div class="card-body text-center">
          <h3 class="mb-3">Email Reverted</h3>
          <p class="text-muted mb-4"><?php echo htmlspecialchars($msg); ?></p>
          <?php if ($ok && $userId): ?>
            <div class="d-flex justify-content-center gap-2">
              <button id="btnChangeNow" class="btn btn-primary">Change Password Now</button>
              <button id="btnSkip" class="btn btn-outline-secondary">Skip For Now</button>
            </div>
            <!-- Modal: Change Password Inline -->
            <div class="modal fade" id="pwModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Change Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div id="pwAlert" class="alert alert-danger d-none"></div>
                    <form id="pwForm">
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
                    </form>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="pwSaveBtn" class="btn btn-primary">Save</button>
                  </div>
                </div>
              </div>
            </div>
          <?php else: ?>
            <a href="/Project_A2/login.php" class="btn btn-secondary">Back to Login</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($ok && $userId): ?>
<script>
(() => {
  const btnChange = document.getElementById('btnChangeNow');
  const btnSkip = document.getElementById('btnSkip');
  const modalEl = document.getElementById('pwModal');
  const modal = new bootstrap.Modal(modalEl);
  const pwForm = document.getElementById('pwForm');
  const pwSaveBtn = document.getElementById('pwSaveBtn');
  const pwAlert = document.getElementById('pwAlert');
  const cardBody = document.querySelector('.card-body');

  btnChange?.addEventListener('click', () => {
    pwAlert.classList.add('d-none');
    pwAlert.textContent = '';
    pwForm.reset();
    modal.show();
  });

  pwSaveBtn?.addEventListener('click', async () => {
    const fd = new FormData(pwForm);
    const payload = new URLSearchParams({
      ajax: 'change_pw',
      current_password: fd.get('current_password') || '',
      new_password: fd.get('new_password') || '',
      confirm_password: fd.get('confirm_password') || ''
    });
    const res = await fetch('/Project_A2/force_change_password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString()
    });
    let data = {};
    try { data = await res.json(); } catch (e) {}
    if (data && data.ok) {
      modal.hide();
      cardBody.innerHTML = `
        <h3 class="mb-3">Password Updated</h3>
        <p class="text-muted mb-4">Your password has been changed successfully.</p>
        <a href="/Project_A2/login.php" class="btn btn-primary">Back to Login</a>
      `;
      // Destroy session on server and broadcast logout to other tabs
      try { await fetch('/Project_A2/logout.php', { method: 'GET', credentials: 'include' }); } catch (e) {}
      try { localStorage.setItem('bdis-logout', String(Date.now())); } catch (e) {}
      // Update UI as fallback and attempt to close this tab
      setTimeout(() => { try { window.open('', '_self'); window.close(); } catch (e) {} }, 200);
    } else {
      pwAlert.textContent = (data && data.message) || 'Unable to change password. Please try again.';
      pwAlert.classList.remove('d-none');
    }
  });

  btnSkip?.addEventListener('click', async () => {
    const res = await fetch(location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ ajax: 'skip_pw_change' }).toString()
    });
    let data = {};
    try { data = await res.json(); } catch (e) {}
    if (data && data.ok) {
      cardBody.innerHTML = `
        <h3 class="mb-3">All Set</h3>
        <p class="text-muted mb-4">You can change your password later from your profile settings.</p>
        <a href="/Project_A2/login.php" class="btn btn-secondary">Back to Login</a>
      `;
      // Destroy session on server and broadcast logout to other tabs
      try { await fetch('/Project_A2/logout.php', { method: 'GET', credentials: 'include' }); } catch (e) {}
      try { localStorage.setItem('bdis-logout', String(Date.now())); } catch (e) {}
      // Update UI as fallback and attempt to close this tab
      setTimeout(() => { try { window.open('', '_self'); window.close(); } catch (e) {} }, 200);
    } else {
      // If failed to skip, keep buttons but show a small warning
      const warn = document.createElement('div');
      warn.className = 'alert alert-warning mt-3';
      warn.textContent = (data && data.message) || 'Unable to skip right now. Please try again.';
      cardBody.appendChild(warn);
    }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
