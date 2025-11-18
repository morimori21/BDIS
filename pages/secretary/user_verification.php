    <style>
    .resident-card {
        border: 1px solid #ddd;
        border-radius: 12px;
        padding: 20px;
        background: #fff;
    }

    #residentInfoModal .row > .col-5 {
        font-size: 14px;
    }

    #residentInfoModal .row > .col-7 {
        font-size: 14px;
        font-weight: 600;
    }


    .resident-section-title {
        font-size: 14px;
        font-weight: bold;
        text-transform: uppercase;
        color: #555;
        margin-bottom: 6px;
    }

    .resident-info {
        font-size: 15px;
        font-weight: 600;
        margin-bottom: 10px;
    }

    .divider-line {
        width: 100%;
        border-bottom: 1px solid #d9d9d9;
        margin: 12px 0;
    }


    </style>

    <?php
    require_once '../../includes/config.php';
    require_once '../../includes/email_notif.php';

    redirectIfNotLoggedIn();
    redirectIfNotRole('secretary');

    // Handle verify/reject actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
        $action = $_POST['action'];
        $user_id = (int)$_POST['user_id'];

        if ($action === 'verify') {
            // ✅ Update status
            $stmt = $pdo->prepare("UPDATE users SET status = 'verified', remarks = NULL WHERE user_id = ?");
            $stmt->execute([$user_id]);

             $stmtRole = $pdo->prepare("
                INSERT INTO user_roles (user_id, role, role_desc)
                VALUES (?, 'resident', 'Verified resident user')
                ON DUPLICATE KEY UPDATE role = 'resident', role_desc = 'A verified Resident'
            ");
            $stmtRole->execute([$user_id]);

            require_once '../../includes/config.php'; // or your actual file path
            logActivity($_SESSION['user_id'], 'approve user', ['target_user_id' => $user_id]);

            // ✅ Fetch user info for email
            $stmtUser = $pdo->prepare("SELECT first_name, surname, email FROM users u
                LEFT JOIN account a ON u.user_id = a.user_id
                LEFT JOIN email e ON a.email_id = e.email_id
                WHERE u.user_id = ?");
            $stmtUser->execute([$user_id]);
            $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);

            $status = 'verified';
            $remarks_to_send = null;

            if ($userData && !empty($userData['email'])) {
                $to = trim($userData['email']);
                $name = htmlspecialchars(trim($userData['first_name'] . ' ' . $userData['surname']));

                $sent = sendAccountStatusEmail($to, $name, $status, $remarks_to_send);

                $_SESSION['flash_' . ($sent ? 'success' : 'error')] =
                    $sent ? "✅ User verified & 📧 email sent." : "✅ User verified, ❌ email failed.";
            } else {
                $_SESSION['flash_warning'] = "✅ User verified, ⚠ no email found.";
            }

        } elseif ($action === 'reject' && !empty($_POST['remarks'])) {
            $remarks = trim($_POST['remarks']);

            // ✅ Update status
            $stmt = $pdo->prepare("UPDATE users SET status = 'rejected', remarks = ? WHERE user_id = ?");
            $stmt->execute([$remarks, $user_id]);

            require_once '../../includes/config.php'; // or your actual file path
            logActivity($_SESSION['user_id'], 'reject user', [
                'target_user_id' => $user_id,
                'remarks' => $remarks
            ]);

            // ✅ Fetch user info
            $stmtUser = $pdo->prepare("SELECT first_name, surname, email FROM users u
                LEFT JOIN account a ON u.user_id = a.user_id
                LEFT JOIN email e ON a.email_id = e.email_id
                WHERE u.user_id = ?");
            $stmtUser->execute([$user_id]);
            $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);

            $status = 'rejected';
            $remarks_to_send = $remarks;

            if ($userData && !empty($userData['email'])) {
                $to = trim($userData['email']);
                $name = htmlspecialchars(trim($userData['first_name'] . ' ' . $userData['surname']));

                $sent = sendAccountStatusEmail($to, $name, $status, $remarks_to_send);

                $_SESSION['flash_' . ($sent ? 'success' : 'error')] =
                    $sent ? "❌ User rejected & 📧 email sent." : "❌ User rejected, email failed.";
            } else {
                $_SESSION['flash_warning'] = "❌ User rejected, ⚠ no email found.";
            }
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }



    // Fetch pending users
    $stmt = $pdo->query("
        SELECT 
            u.user_id, 
            u.first_name, u.middle_name, u.surname, u.suffix,
            u.sex, u.birthdate, u.contact_number,
            u.street, u.date_registered, u.status, u.remarks,

            ac.brgy_name, ac.municipality, ac.province,

            e.email,

            iv.id_type,
            iv.front_img AS front_id, 
            iv.back_img AS back_id
            
        FROM users u
        LEFT JOIN account acc ON u.user_id = acc.user_id
        LEFT JOIN email e ON acc.email_id = e.email_id
        LEFT JOIN id_verification iv ON u.user_id = iv.user_id
        LEFT JOIN address_config ac ON u.address_id = ac.address_id

        WHERE u.status = 'pending'
    ");
    $pending_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    include 'header.php';
    ?>

    <div class="container">


    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show mt-3">
            <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mt-3">
            <?= $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_warning'])): ?>
        <div class="alert alert-warning alert-dismissible fade show mt-3">
            <?= $_SESSION['flash_warning']; unset($_SESSION['flash_warning']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>



    <div class="card mb-4">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="fas fa-user-clock me-2"></i>Pending User Verifications</h5>
        </div>
        <div class="card-body table-responsive">
            <?php if(empty($pending_users)): ?>
                <div class="text-center py-5">
                    <div class="display-1 text-muted">🎉</div>
                    <h4 class="text-muted">All caught up!</h4>
                    <p class="text-muted">No pending user verifications at the moment.</p>
                </div>
            <?php else: ?>
            <table class="table table-hover align-middle" id="pendingUsersTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>ID Document</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
            <?php foreach($pending_users as $user):
        $uid = (int)$user['user_id'];
        $fullName = htmlspecialchars(trim($user['first_name'] . ' ' . $user['surname'] . ' ' . ($user['suffix'] ?? '')));
        $email = htmlspecialchars($user['email'] ?? '');

        // Prepare safe JS payload (only include needed fields)
        $user_for_js = [
            'user_id'      => $uid,
            'first_name'   => $user['first_name'] ?? '',
            'middle_name'  => $user['middle_name'] ?? '',
            'surname'      => $user['surname'] ?? '',
            'suffix'       => $user['suffix'] ?? '',
            'sex'          => $user['sex'] ?? '',
            'birthdate'    => $user['birthdate'] ?? '',
            'contact_number'=> $user['contact_number'] ?? '',
            'street'       => $user['street'] ?? '',
            'brgy_name'    => $user['brgy_name'] ?? '',
            'municipality' => $user['municipality'] ?? '',
            'province'     => $user['province'] ?? '',
            'email'        => $user['email'] ?? '',
            'id_type'      => $user['id_type'] ?? '',
            // ensure images are base64 strings (or empty string)
            'front_b64'    => !empty($user['front_id']) ? base64_encode($user['front_id']) : '',
            'back_b64'     => !empty($user['back_id'])  ? base64_encode($user['back_id'])  : '',
            // pre-format date for display
            'date_registered' => !empty($user['date_registered']) ? date('M j, Y g:i A', strtotime($user['date_registered'])) : ''
        ];

        // JSON encode and escape for attribute
        $data_user_attr = htmlspecialchars(json_encode($user_for_js), ENT_QUOTES, 'UTF-8');
    ?>
    <tr data-user-id="<?= $uid ?>">
        <td><?= $fullName ?></td>
        <td><?= $email ?></td>
        <td>
            <?php if($user_for_js['front_b64'] || $user_for_js['back_b64']): ?>
                <span class="badge bg-info text-dark"><i class="fas fa-id-card me-1"></i>Provided</span>
            <?php else: ?>
                <span class="badge bg-secondary"><i class="fas fa-times me-1"></i>Not provided</span>
            <?php endif; ?>
        </td>
        <td><?= $user_for_js['date_registered'] ?></td>
        <td>
            <button class="btn btn-primary btn-sm view-info-btn" data-user='<?= $data_user_attr ?>'>
                <i class="fas fa-eye"></i> View Info
            </button>
        </td>
    </tr>
    <?php endforeach; ?>

                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="residentInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="fas fa-user"></i> Resident Information</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

    <div class="modal-body resident-card">
    <div class="row">
        <!-- LEFT SIDE -->
        <div class="col-6 border-end pe-4">
            <div class="text-center fw-bold mb-2">ID TYPE</div>
            <div class="text-center mb-3">
                <span id="resIDType" class="fw-semibold"></span>
            </div>

            <!-- FRONT ID -->
            <div class="text-center mb-3">
                <img id="idFront" class="border rounded w-100" style="height:160px; object-fit:cover; background:#e5e5e5;">
                <div class="fw-bold mt-2">Front ID</div>
            </div>

            <!-- BACK ID -->
            <div class="text-center">
                <img id="idBack" class="border rounded w-100" style="height:160px; object-fit:cover; background:#e5e5e5;">
                <div class="fw-bold mt-2">Back ID</div>
            </div>
        </div>

        <!-- RIGHT SIDE -->
        <div class="col-6 ps-4">

            <div class="row mb-2">
                <div class="col-5 fw-bold">Name:</div>
                <div class="col-7" id="resName"></div>
            </div>

            <div class="row mb-2">
                <div class="col-5 fw-bold">Address:</div>
                <div class="col-7">
                    <span id="resStreet"></span><br>
                    <small id="resBrgy" class="text-muted"></small>
                </div>
            </div>

            <hr>

            <div class="row mb-2">
                <div class="col-5 fw-bold">Contact Number:</div>
                <div class="col-7" id="resContact"></div>
            </div>

            <div class="row mb-2">
                <div class="col-5 fw-bold">Email:</div>
                <div class="col-7" id="resEmail"></div>
            </div>

            <div class="row mb-2">
                <div class="col-5 fw-bold">Sex:</div>
                <div class="col-7" id="resSex"></div>
            </div>

            <div class="row mb-2">
                <div class="col-5 fw-bold">Birthdate:</div>
                <div class="col-7" id="resBirthdate"></div>
            </div>

            <div class="row mb-2">
                <div class="col-5 fw-bold">Date Registered:</div>
                <div class="col-7" id="resRegistered"></div>

                <div id="resRemarks" style="display:none;"></div>

            </div>
        </div>
    </div>
    </div>
      <div class="modal-footer justify-content-end">
    <!-- Hidden form for Verify -->
    <form method="POST" class="d-inline me-2" id="verifyForm">
        <input type="hidden" name="user_id" id="verifyUserId">
        <input type="hidden" name="action" value="verify">
        <button type="button" class="btn btn-success" id="verifyTrigger">
            <i class="fas fa-check"></i> Verify
        </button>
    </form>

    <!-- Reject Button (JS handles SweetAlert) -->
    <button type="button" class="btn btn-danger" id="rejectTrigger">
        <i class="fas fa-times"></i> Reject
    </button>
</div>
        </div>
        </div>
    </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="path/to/bootstrap.bundle.min.js"></script>
    <script>
    // ID Preview
    document.querySelectorAll('.id-thumb').forEach(btn => {
        btn.addEventListener('click', e => {
            document.getElementById('idPreviewFront').src = btn.dataset.front || '';
            document.getElementById('idPreviewBack').src  = btn.dataset.back || '';
            new bootstrap.Modal(document.getElementById('idPreviewModal')).show();
        });
    });




    (function(){
    // ensure bootstrap bundle is loaded before calling this code
    document.querySelectorAll('.view-info-btn').forEach(btn => {
        btn.addEventListener('click', () => {
        try {
            // parse JSON safely from attribute
            const u = JSON.parse(btn.getAttribute('data-user'));

            // fill left (IDs)
            document.getElementById('resIDType').textContent = u.id_type || '';

            // images: use front_b64 / back_b64 (already base64-encoded by PHP)
            document.getElementById('idFront').src = u.front_b64 ? `data:image/jpeg;base64,${u.front_b64}` : '';
            document.getElementById('idBack').src  = u.back_b64  ? `data:image/jpeg;base64,${u.back_b64}`  : '';

            // fill right (details)
            document.getElementById('resName').textContent = 
            `${u.first_name || ''} ${u.middle_name || ''} ${u.surname || ''} ${u.suffix || ''}`.replace(/\s+/g,' ').trim();

            document.getElementById('resStreet').textContent = u.street || '';
            // brgy line: Barangay, Municipality, Province
            const brgyParts = [u.brgy_name, u.municipality, u.province].filter(Boolean);
            document.getElementById('resBrgy').textContent = brgyParts.join(', ');

            document.getElementById('resContact').textContent = u.contact_number || '';
            document.getElementById('resEmail').textContent   = u.email || '';

            // sex with color + symbol
            if ((u.sex || '').toLowerCase() === 'male') {
            document.getElementById('resSex').innerHTML = '<span class="text-primary fw-bold">♂ Male</span>';
            } else if ((u.sex || '').toLowerCase() === 'female') {
            document.getElementById('resSex').innerHTML = '<span class="text-danger fw-bold">♀ Female</span>';
            } else {
            document.getElementById('resSex').textContent = u.sex || '';
            }

            // birthdate and registered: use preformatted date_registered from PHP when available,
            // otherwise format fallback
            document.getElementById('resBirthdate').textContent = u.birthdate
            ? (new Date(u.birthdate).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }))
            : '';

            document.getElementById('resRegistered').textContent = u.date_registered || '';

            // hidden id for verify
            document.getElementById('verifyUserId').value = u.user_id || '';

            // finally show modal (ID must match modal element above)
            new bootstrap.Modal(document.getElementById('residentInfoModal')).show();
        } catch (err) {
            console.error('Failed to open resident modal:', err);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Unable to open resident info. Check console for details.'
            });
        }
        });
    });
    })();


    document.addEventListener('DOMContentLoaded', function() {

    // VERIFY ACTION
    document.getElementById('verifyTrigger').addEventListener('click', function() {
        const userId = document.getElementById('verifyUserId').value;

        Swal.fire({
        title: 'Are you sure?',
        text: "You are about to VERIFY this user.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Verify!',
        cancelButtonText: 'Cancel',
        reverseButtons: true
        }).then((result) => {
        if (result.isConfirmed) {
            // create a hidden form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
            <input type="hidden" name="user_id" value="${userId}">
            <input type="hidden" name="action" value="verify">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        });
    });

document.getElementById('rejectTrigger').addEventListener('click', function () {
    const userId = document.getElementById('verifyUserId').value;
    const existingRemarks = document.getElementById('resRemarks')?.textContent || '';

    // hide bootstrap modal first
    const residentModal = bootstrap.Modal.getInstance(document.getElementById('residentInfoModal'));
    residentModal?.hide();

    Swal.fire({
        title: 'Reject User?',
        html: `
            <label for="rejectReason" class="swal2-label" style="display:block; margin-bottom:6px;">Select reason:</label>
            <select id="rejectReason" class="swal2-select" 
                style="width:80%; max-width:300px; padding:6px; font-size:0.9rem;">
                <option value="" disabled selected>Select a reason</option>
                <option value="Incomplete information">Incomplete information</option>
                <option value="Invalid or fake information">Invalid or fake information</option>
                <option value="Non-residency">Non-residency</option>
                <option value="Invalid or expired identification">Invalid or expired identification</option>
                <option value="Duplicate registration">Duplicate registration</option>
                <option value="Unverified address">Unverified address</option>
                <option value="Unclear or blurry uploaded documents">Unclear or blurry uploaded documents</option>
                <option value="System or technical error">System or technical error</option>
                <option value="Violation of data policy or misuse of system">Violation of data policy or misuse of system</option>
                <option value="Other">Other</option>
            </select>
            <textarea id="otherReason" class="swal2-textarea" 
                placeholder="Enter custom reason..." 
                style="display:none; width:85%; height:90px; margin-top:10px; font-size:0.9rem; resize:none;">${existingRemarks}</textarea>
        `,
        showCancelButton: true,
        confirmButtonText: 'Reject',
        cancelButtonText: 'Cancel',
        customClass: {
            popup: 'swal2-fit-box',
        },
        preConfirm: () => {
            const dropdownValue = document.getElementById('rejectReason').value;
            const customReason = document.getElementById('otherReason').value.trim();

            if (!dropdownValue) {
                Swal.showValidationMessage('Please select a reason!');
                return false;
            }

            if (dropdownValue === 'Other' && !customReason) {
                Swal.showValidationMessage('Please enter a reason for rejection!');
                return false;
            }

            return dropdownValue === 'Other' ? customReason : dropdownValue;
        },
        didOpen: () => {
            const dropdown = document.getElementById('rejectReason');
            const textbox = document.getElementById('otherReason');

            dropdown.addEventListener('change', () => {
                if (dropdown.value === 'Other') {
                    textbox.style.display = 'block';
                    textbox.focus();
                } else {
                    textbox.style.display = 'none';
                }
            });
        }
    }).then((result) => {
        // reopen modal if cancelled
        if (!result.isConfirmed) {
            residentModal?.show();
            return;
        }

        const reason = result.value;

        // send reason to server and email
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="user_id" value="${userId}">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="remarks" value="${reason}">
        `;
        document.body.appendChild(form);
        form.submit();
    });
});
    });
    </script>

    <?php include 'footer.php'; ?>
