<?php
// Secretary Profile wrapper reusing resident profile implementation
require_once '../../includes/config.php';
require_once __DIR__ . '/../../includes/email_config.php';
redirectIfNotLoggedIn();

// Allow both secretary and admin (same rule as secretary/header.php)
$userRole = getUserRole();
if ($userRole !== 'secretary' && $userRole !== 'admin') {
    header('Location: /Project_A2/unauthorized.php');
    exit;
}

// Bypass resident-only guard in included profile
if (!defined('PROFILE_ALLOW_ALL_RESIDENT_LIKE')) {
    define('PROFILE_ALLOW_ALL_RESIDENT_LIKE', true);
}
// Ensure the resident profile uses the secretary header
if (!defined('PROFILE_HEADER_PATH')) {
    define('PROFILE_HEADER_PATH', __DIR__ . '/header.php');
}

// Render using resident profile implementation; stop after include to avoid parsing leftovers
include __DIR__ . '/../resident/profile.php';
__halt_compiler();
<?php include 'header.php'; ?>

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
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['profile_error']); endif; ?>

    <div class="row g-4">
        <!-- Summary -->
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-center mb-3">
                        <?php if (!empty($user['profile_picture'])): ?>
                            <img src="/Project_A2/uploads/<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile" class="rounded-circle" style="width: 140px; height: 140px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center" style="width: 140px; height: 140px; color: #6c757d; font-size: 18px; border: 1px solid #dee2e6;">
                                No Photo
                            </div>
                        <?php endif; ?>
                    </div>
                    <h5 class="text-center mb-1"><?php echo htmlspecialchars(trim(bd_title($user['first_name'] ?? '') . ' ' . bd_title($user['middle_name'] ?? '') . ' ' . bd_title($user['surname'] ?? ''))); ?></h5>
                    <div class="d-flex justify-content-center align-items-center gap-2 mb-3">
                        <span class="text-muted"><?php echo htmlspecialchars($user['email'] ?? ''); ?></span>
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
                                <span class="text-muted"><?php echo htmlspecialchars(($user['contact_number'] ?? 'N/A')); ?></span>
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
                                <span class="text-muted text-capitalize"><?php echo htmlspecialchars(($user['status'] ?? 'pending')); ?></span>
                            </div>
                        </div>
                        <div class="pt-3">
                            <label class="form-label small mb-1">Change Password</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="password" class="form-control" value="**************" disabled style="max-width: 220px;">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#changePasswordModal" title="Edit password">
                                    <i class="bi bi-pencil"></i>
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
                    <form method="POST" enctype="multipart/form-data">
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
                                <input type="date" class="form-control" name="birthdate" value="<?php echo htmlspecialchars($user['birthdate'] ?? ''); ?>" disabled>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sex</label>
                                <select class="form-select" name="sex" disabled>
                                    <?php $sexVal = $user['sex'] ?? ''; ?>
                                    <option value="">Select</option>
                                    <option value="Male" <?php echo ($sexVal === 'Male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($sexVal === 'Female') ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label">Street</label>
                                <input type="text" class="form-control" name="street" value="<?php echo htmlspecialchars($user['street'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Contact Number</label>
                                <input type="text" class="form-control" name="contact_number" value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>">
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Profile Picture (JPG/PNG)</label>
                                <input type="file" class="form-control" name="profile_picture" accept="image/jpeg,image/png">
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary">Save Changes</button>
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
            <form method="POST" id="changeEmailForm">
                <input type="hidden" name="change_email_pw" value="1">
                <input type="hidden" name="confirm_password" id="emailConfirmPassword">
                <div class="modal-body">
                    <?php if ($msg = flash('email_change_success')): ?><div class="alert alert-success mb-3">Email updated successfully.</div><?php endif; ?>
                    <?php if ($msg = flash('email_change_error')): ?><div class="alert alert-danger mb-3"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Current Email</label>
                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" readonly>
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
    const pwField = document.getElementById('emailConfirmPassword');
    if (!pwField || pwField.value) return; // already has password
    e.preventDefault();

    // Hide the Bootstrap modal first to avoid focus trap blocking input
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
    if (password) {
        pwField.value = password;
        this.submit();
    } else {
        // If user cancelled, re-open the modal so they can continue editing
        modalInstance.show();
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
*/
