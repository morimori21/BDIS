    <!-- 
    KUNG MAPAPANSIN NYO KUNG BAKIT MAY CHECK BOXES SA TABLE HEADERS AT EACH ROWS
    PLAN KO UPDATE UNG MULTI ACTIONS PARA MAG APPLY SA MGA SELECTED USERS
    EFFICIENTLY , MULTI MANAGE IDK PARA GOOD UX RIN XD 

    FOR SAMPLE I CAN SELECT MULTIPLE USERS AND CHANGE THEIR ROLES IN BULK

    ⠀⠀⠀⠀⠀⠀⠀⠀⠀⡀⠤⠀⠄⢀⠀⠀⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⠀⡠⢧⣾⣷⣿⣿⣵⣿⣄⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⢰⢱⡿⠟⠛⠛⠉⠙⢿⣿⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⢸⣿⡇⣤⣤⡀⢠⡄⣸⢿⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⢨⡼⡇⠈⠉⣡⠀⠉⢈⣞⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⠈⠱⢢⡄⠀⣬⣤⠀⣾⠋⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⠀⠀⠀⢀⡎⡇⠀⠈⣿⢻⡋⠀⠀⠀⠀⠀⠀⠀
    ⠀⠀⠀⠀⣀⣤⣶⣿⣧⢃⠀⠠⣶⣿⢻⣶⣤⣤⣀⠀⠀⠀
    ⢀⣤⣶⣿⣿⣿⣿⣿⣿⡤⠱⢀⣹⠋⠈⣿⣿⣿⣿⣿⣦⠀
    ⣾⣿⣿⣿⣿⡿⢛⣿⣿⣧⣔⣂⣄⠠⣴⣿⣿⣿⣿⣿⣿⡇
    ⡿⣿⣿⣿⣿⣳⠫⠈⣙⣻⡧⠔⢺⣛⣿⣿⣿⣿⣿⣿⣿⡇
    ⣿⣿⣿⣿⣿⠄⠀⢀⣨⣿⡯⠭⢭⣶⣿⣿⣿⣿⣿⣿⣿⡇
    -->


    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <?php include 'header.php'; ?>
    <!-- template thing -->
    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'save_template_roles') {
        $templateRoles = [
            'captain' => $_POST['captain'] ?? null,
            'secretary' => $_POST['secretary'] ?? null,
            'treasurer' => $_POST['treasurer'] ?? null,
            'sk_chairman' => $_POST['sk_chairman'] ?? null,
            'councilors' => $_POST['councilors'] ?? []
        ];

        // 🧹 Clear out previous holders for unique roles (captain/secretary)
        $pdo->query("DELETE FROM user_roles WHERE role IN ('captain','secretary')");

        // 🧩 Reassign unique roles
        foreach (['captain','secretary'] as $role) {
            if (!empty($templateRoles[$role])) {
                $pdo->prepare("INSERT INTO user_roles (user_id, role, role_desc) VALUES (?,?,?)")
                    ->execute([$templateRoles[$role], $role, ucfirst(str_replace('_',' ',$role))]);
            }
        }

        // ✅ For multi roles (Councilor, Treasurer, SK Chairman)
        foreach (['treasurer','sk_chairman'] as $r) {
            if (!empty($templateRoles[$r])) {
                $pdo->prepare("REPLACE INTO user_roles (user_id, role, role_desc) VALUES (?,?,?)")
                    ->execute([$templateRoles[$r], $r, ucfirst(str_replace('_',' ',$r))]);
            }
        }

        // ✅ For councilors (many)
        $pdo->query("DELETE FROM user_roles WHERE role = 'councilor'");
        foreach ($templateRoles['councilors'] as $uid) {
            $pdo->prepare("INSERT INTO user_roles (user_id, role, role_desc) VALUES (?, 'councilor', 'Barangay Councilor')")
                ->execute([$uid]);
        }

        echo 'success';
        exit;
    }
    ?>

    <?php
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'change_role') {
        $user_id = (int) $_POST['user_id'];
        $new_role = trim($_POST['role']);

        // 🧩 Step 1: Fetch old role BEFORE updating
        $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $old_role = $stmt->fetchColumn() ?: 'Resident';

        // 🧩 Step 2: Insert or update role
        if ($old_role) {
            $stmt = $pdo->prepare("UPDATE user_roles SET role = ? WHERE user_id = ?");
            $stmt->execute([$new_role, $user_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (?, ?)");
            $stmt->execute([$user_id, $new_role]);
        }

        // 🧩 Step 3: Only log if there’s an actual change
        if (strcasecmp($old_role, $new_role) !== 0) {
            if (function_exists('logActivity')) {
                logActivity($_SESSION['user_id'], "Changed role", [
                    'target_user_id' => $user_id,
                    'old_role' => $old_role,
                    'new_role' => $new_role
                ]);
            }
        }

        echo "success";
        exit;
    }
    ?>





    <?php
    // Fetch user stats
    $user_stats = [
        'total' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'verified' => $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'verified'")->fetchColumn(),
        'pending' => $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn(),

        'admins' => $pdo->query("SELECT COUNT(*) FROM user_roles WHERE role = 'Admin'")->fetchColumn(),
        'secretaries' => $pdo->query("SELECT COUNT(*) FROM user_roles WHERE role = 'Secretary'")->fetchColumn(),
        'captains' => $pdo->query("SELECT COUNT(*) FROM user_roles WHERE role = 'Captain'")->fetchColumn(),
        'residents' => $pdo->query("SELECT COUNT(*) FROM user_roles WHERE role = 'Resident'")->fetchColumn()
    ];

    // Pagination setup
    $entriesPerPage = isset($_GET['entries']) ? (int)$_GET['entries'] : 10;
    $currentPage = isset($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;

    // Count total users
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalPages = max(1, ceil($totalUsers / $entriesPerPage));
    $offset = ($currentPage - 1) * $entriesPerPage;

    // Fetch users with pagination
    $stmt = $pdo->prepare("
    SELECT 
            u.user_id,
            u.first_name,
            u.surname,
            u.status,
            u.date_registered,
            u.profile_picture,
            e.email,
            COALESCE(ur.role, 'resident') AS role
        FROM users u
        LEFT JOIN account a ON a.user_id = u.user_id
        LEFT JOIN email e ON e.email_id = a.email_id
        LEFT JOIN user_roles ur ON ur.user_id = u.user_id
        ORDER BY u.date_registered DESC
        LIMIT :offset, :limit
    ");
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $entriesPerPage, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all users for template modal (without pagination)
    $allUsersStmt = $pdo->query("
        SELECT u.user_id, u.first_name, u.surname, COALESCE(ur.role, 'resident') AS role
        FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.user_id
        ORDER BY u.first_name
    ");
    $allUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <style>
    /* Table container aah */
    .card,
    .card-header,
    .table-responsive {
        overflow: visible !important;
        position: relative;
    }
    .table td, .table th {
        vertical-align: middle;
        white-space: nowrap;
        padding: 0.5rem 0.75rem;
        font-size: 0.9rem;
    }
    .table td img {
        width: 35px;
        height: 35px;
        object-fit: cover;
    }
    .table td span.badge {
        display: inline-block;
        min-width: 60px;
        text-align: center;
    }
    .table td select.form-select-sm {
        width: 120px;
    }
    .table thead th {
        position: relative;
        z-index: 5;
        white-space: nowrap;
    }

    /* column filter sevtion */
    .filter-header {
        align-items: center;
        gap: 6px; /* space between button and title */
        position: relative;
    }

    .filter-btn {
        border: 1px solid #dee2e6;
        background-color: transparent;
        background-repeat: no-repeat;
        color: #495057;
        padding: 3px 6px;
        font-size: 13px;
        line-height: 1;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
        overflow: hidden;
        outline: none;
    }
    .filter-btn:hover {
        color: #000;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .filter-menu {
        display: none;
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 8px 0;
        min-width: 190px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15);
        z-index: 9999;
        animation: dropdownFade 0.15s ease-in-out;
    }
    @keyframes dropdownFade {
        from { opacity: 0; transform: translateY(-5px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .filter-menu label {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        margin: 0;
        font-size: 14px;
        color: #333;
        cursor: pointer;
        transition: background 0.15s;
    }
    .filter-menu label:hover {
        background: #f8f9fa;
    }
    .filter-menu input[type="checkbox"] {
        accent-color: #007bff;
    }
    .dropdown-item {
        display: block;
        width: 100%;
        padding: 6px 12px;
        font-size: 14px;
        color: #333;
        text-align: left;
        border: none;
        background: none;
        cursor: pointer;
        transition: background 0.15s;
    }
    .dropdown-item:hover {
        background: #f8f9fa;
    }

    /* Pagination styling */
    .pagination .page-link {
        border: 1px solid #dee2e6;
        margin: 0 2px;
        border-radius: 4px;
        padding: 0.375rem 0.75rem;
        font-size: 0.9rem;
    }
    .pagination .page-item.active .page-link {
        background-color: #007bff;
        border-color: #007bff;
        color: white;
    }
    .pagination .page-item.disabled .page-link {
        color: #6c757d;
        background-color: #fff;
        border-color: #dee2e6;
    }
    </style>


    <div class="container">

    

        <!-- Users Table -->
        <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <h5 class="mb-0 me-3"><i class="fas fa-filter me-2"></i>User Management</h5>
                <select id="entriesPerPage" class="form-select form-select-sm" style="width: auto;" onchange="changeEntries()">
                    <option value="10" <?= $entriesPerPage == 10 ? 'selected' : '' ?>>10</option>
                    <option value="25" <?= $entriesPerPage == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= $entriesPerPage == 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $entriesPerPage == 100 ? 'selected' : '' ?>>100</option>
                </select>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateRolesModal">
                <i class="fa fa-file-text-o me-2"></i>Template Setup
            </button>
        </div>

        <!-- modal template -->
    <div class="modal fade" id="templateRolesModal" tabindex="-1" aria-labelledby="templateRolesModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title" id="templateRolesModalLabel">
          <i class="fa fa-file-text-o me-2"></i>Configure Barangay Template Roles
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="templateRolesForm">
          <div class="row g-3">

            <!-- 🧑 Captain -->
            <div class="col-md-6">
              <label class="form-label fw-bold">Punong Barangay (Captain)</label>
              <select name="captain" class="form-select" required>
                <option value="">-- Select Captain --</option>
                <?php
                $hasCaptain = false;
                foreach ($allUsers as $u) {
                  if ($u['role'] === 'captain') {
                    $hasCaptain = true;
                    break;
                  }
                }
                foreach ($allUsers as $u):
                  if ($hasCaptain) {
                    if ($u['role'] === 'captain' || $u['role'] === 'resident'):
                ?>
                      <option value="<?= $u['user_id'] ?>" <?= $u['role'] === 'captain' ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                      </option>
                <?php
                    endif;
                  } else {
                    if ($u['role'] === 'resident'):
                ?>
                      <option value="<?= $u['user_id'] ?>">
                        <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                      </option>
                <?php
                    endif;
                  }
                endforeach;
                ?>
              </select>
            </div>

            <!-- 📜 Secretary -->
            <div class="col-md-6">
              <label class="form-label fw-bold">Barangay Secretary</label>
              <select name="secretary" class="form-select" required>
                <option value="">-- Select Secretary --</option>
                <?php
                $hasSecretary = false;
                foreach ($allUsers as $u) {
                  if ($u['role'] === 'secretary') {
                    $hasSecretary = true;
                    break;
                  }
                }
                foreach ($allUsers as $u):
                  if ($hasSecretary) {
                    if ($u['role'] === 'secretary' || $u['role'] === 'resident'):
                ?>
                      <option value="<?= $u['user_id'] ?>" <?= $u['role'] === 'secretary' ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                      </option>
                <?php
                    endif;
                  } else {
                    if ($u['role'] === 'resident'):
                ?>
                      <option value="<?= $u['user_id'] ?>">
                        <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                      </option>
                <?php
                    endif;
                  }
                endforeach;
                ?>
              </select>
            </div>

            <!-- 💰 Treasurer -->
            <div class="col-md-6">
              <label class="form-label fw-bold">Barangay Treasurer</label>
              <select name="treasurer" class="form-select" required>
                <option value="">-- Select Treasurer --</option>
                <?php foreach ($allUsers as $u): ?>
                  <?php if (in_array($u['role'], ['resident', 'treasurer'])): ?>
                    <option value="<?= $u['user_id'] ?>" <?= $u['role'] === 'treasurer' ? 'selected' : '' ?>>
                      <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 🧒 SK Chairman -->
            <div class="col-md-6">
              <label class="form-label fw-bold">S.K. Chairman</label>
              <select name="sk_chairman" class="form-select" required>
                <option value="">-- Select SK Chairman --</option>
                <?php foreach ($allUsers as $u): ?>
                  <?php if (in_array($u['role'], ['resident', 'sk_chairman'])): ?>
                    <option value="<?= $u['user_id'] ?>" <?= $u['role'] === 'sk_chairman' ? 'selected' : '' ?>>
                      <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 👥 Councilors -->
            <div class="col-12">
              <label class="form-label fw-bold">Barangay Councilors (7)</label>
              <select name="councilors[]" class="form-select" multiple size="7" required>
                <?php foreach ($allUsers as $u): ?>
                  <?php if (in_array($u['role'], ['resident', 'councilor'])): ?>
                    <option value="<?= $u['user_id'] ?>" <?= $u['role'] === 'councilor' ? 'selected' : '' ?>>
                      <?= htmlspecialchars($u['first_name'].' '.$u['surname']) ?>
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">Hold Ctrl/Cmd to select multiple.</small>
            </div>

          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success" id="saveTemplateRolesBtn">
          <i class="fa fa-save me-1"></i>Save Template
        </button>
      </div>
    </div>
  </div>
</div>

    <!-- COLUMNS -->
        <div class="card-body">
            <div class="table-responsive position-relative">
                <table class="table align-middle" id="usersTable">
                <thead class="table-light">
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                            <!-- Name -->
                            <th class="filter-header position-relative">
                            Name
                            <button class="btn btn-sm btn-light filter-btn" onclick="toggleSort('name')">
                                <i class="fa fa-chevron-down"></i>
                            </button>
                        </th>

                        <!-- Role -->
                        <th class="filter-header position-relative">
                            Role
                            <button class="filter-btn" onclick="toggleFilter('roleFilterMenu', event)">
                            <i class='fa fa-chevron-down'></i>
                            </button>
                            <div class="filter-menu" id="roleFilterMenu">
                               <label><input type="checkbox" class="role-filter" value="admin"> Admin</label>
                                <label><input type="checkbox" class="role-filter" value="secretary"> Secretary</label>
                                <label><input type="checkbox" class="role-filter" value="captain"> Captain</label>
                                <label><input type="checkbox" class="role-filter" value="treasurer"> Treasurer</label>
                                <label><input type="checkbox" class="role-filter" value="sk_chairman"> S.K. Chairman</label>
                                <label><input type="checkbox" class="role-filter" value="councilor"> Councilor</label>
                                <label><input type="checkbox" class="role-filter" value="resident"> Resident</label>
                            </div>
                        </th>

                        <!-- Status -->
                        <th class="filter-header position-relative">
                            Status
                            <button class="btn btn-sm btn-light filter-btn" onclick="toggleFilter('statusFilterMenu', event)">
                                <i class="fa fa-chevron-down"></i>
                            </button>
                            <div class="filter-menu" id="statusFilterMenu">
                                <label><input type="checkbox" class="status-filter" value="verified"> Verified</label>
                                <label><input type="checkbox" class="status-filter" value="pending"> Pending</label>
                            </div>
                        </th>


                            <!-- Date Registered -->
                        <th class="filter-header position-relative">
                            Date Registered
                            <button class="btn btn-sm btn-light filter-btn" onclick="toggleSort('Date Registered')">
                                <i class="fa fa-chevron-down"></i>
                            </button>
                        </th>

                            <!-- Action -->
                            <th class="filter-header position-relative">
                                Action
                            </th>
                        </tr>
                </thead>
    <!-- CONTENTS -->
                <tbody>
                        <?php foreach ($users as $user):
                            $fullName = htmlspecialchars($user['first_name'] . ' ' . $user['surname']);
                            $email = htmlspecialchars($user['email']);
                            $role = htmlspecialchars($user['role'] ?? 'Not Registered');
                            $status = htmlspecialchars($user['status']); // This will capture 'pending' or 'verified'

                            $profile_src = "../../assets/images/avatar-guest@2x.png"; // fallback
                            if (!empty($user['profile_picture'])) {
                                $file_path = "../../uploads/" . $user['profile_picture'];
                                if (file_exists($file_path)) {
                                    $profile_src = $file_path;
                                } elseif (strlen($user['profile_picture']) > 0) {
                                    $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $user['profile_picture']);
                                    $profile_src = 'data:' . $mime . ';base64,' . base64_encode($user['profile_picture']);
                                }
                            }
                        ?>
                        <tr data-role="<?= $role ?>" data-status="<?= $status ?>" data-name="<?= $fullName ?>" data-date="<?= $user['date_registered'] ?>" data-user-id="<?= $user['user_id'] ?>">
                            <td><input type="checkbox" class="userCheckbox"></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="<?= $profile_src ?>" alt="Profile" class="rounded-circle me-3" width="45" height="45" style="object-fit: cover;">
                                    <div>
                                        <strong><?= $fullName ?></strong><br>
                                        <small class="text-muted"><?= $email ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge bg-secondary"><?= ucfirst($role) ?></span></td>
                            <td><span class="badge bg-<?= $status === 'verified' ? 'success' : 'warning' ?>"><?= ucfirst($status) ?></span></td>
                            <td><?= date('M j, Y', strtotime($user['date_registered'])) ?></td>
                            <td>
                                <?php if ($status !== 'pending'): ?>
                                <select class="form-select form-select-sm" onchange="changeRole(<?= $user['user_id'] ?>, this.value)">
                                    <option value="resident" <?= $role === 'resident' ? 'selected' : '' ?>>Resident</option>
                                    <option value="secretary" <?= $role === 'secretary' ? 'selected' : '' ?>>Secretary</option>
                                    <option value="captain" <?= $role === 'captain' ? 'selected' : '' ?>>Captain</option>
                                    <option value="treasurer" <?= $role === 'treasurer' ? 'selected' : '' ?>>Treasurer</option>
                                    <option value="sk_chairman" <?= $role === 'sk_chairman' ? 'selected' : '' ?>>S.K. Chairman</option>
                                    <option value="councilor" <?= $role === 'councilor' ? 'selected' : '' ?>>Councilor</option>
                                    <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
                                </select>
                                <?php else: ?>
                                <em>Pending</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                </table>
            </div>

            <!-- Pagination Section -->
            <div class="d-flex justify-content-between align-items-center mt-2 px-3 pb-3">
                <div class="text-muted" style="font-size: 0.9rem;">
                    Showing <?= min($offset + 1, $totalUsers) ?> to <?= min($offset + $entriesPerPage, $totalUsers) ?> of <?= $totalUsers ?> entries
                </div>
                <nav>
                    <ul class="pagination mb-0">
                        <!-- Previous Button -->
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $currentPage - 1 ?>&entries=<?= $entriesPerPage ?>" style="color: #6c757d;">Previous</a>
                        </li>
                        
                        <!-- Page Numbers -->
                        <?php 
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $currentPage + 2);
                        
                        for($p = $startPage; $p <= $endPage; $p++): 
                        ?>
                            <li class="page-item <?= $currentPage == $p ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $p ?>&entries=<?= $entriesPerPage ?>" 
                                   style="<?= $currentPage == $p ? 'background-color: #007bff; border-color: #007bff; color: white;' : 'color: #6c757d;' ?>">
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <!-- Next Button -->
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $currentPage + 1 ?>&entries=<?= $entriesPerPage ?>" style="color: #6c757d;">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>



    <script>
        //All Checkboxes select all
        document.getElementById('selectAll').addEventListener('change', function() {
            document.querySelectorAll('.userCheckbox').forEach(cb => cb.checked = this.checked);
        });

    // Change entries per page
    function changeEntries() {
        const entries = document.getElementById('entriesPerPage').value;
        window.location.href = '?page=1&entries=' + entries;
    }

    function toggleFilter(id) {
        document.querySelectorAll('.filter-menu').forEach(menu => {
            if (menu.id !== id) menu.style.display = 'none';
        });
        const menu = document.getElementById(id);
        menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
    }

    //ALL ABOUT FILTERS
            document.addEventListener('click', e => {
                if (!e.target.closest('.filter-header')) {
                    document.querySelectorAll('.filter-menu').forEach(m => m.style.display = 'none');
                }
            });

            // Role Filter
            document.querySelectorAll('.role-filter').forEach(cb => {
                cb.addEventListener('change', filterTable);
            });
            
            // Status Filter
            document.querySelectorAll('.status-filter').forEach(cb => {
                cb.addEventListener('change', filterTable);
            });

            // fetching into ONE FOR ALL
            function filterTable() {
                const selectedRoles = [...document.querySelectorAll('.role-filter:checked')].map(cb => cb.value);
                const selectedStatuses = [...document.querySelectorAll('.status-filter:checked')].map(cb => cb.value);

                document.querySelectorAll('#usersTable tbody tr').forEach(row => {
                    const role = row.dataset.role;
                    const status = row.dataset.status;

                    // Show row only if it matches role AND status filters
                    const showRole = selectedRoles.length ? selectedRoles.includes(role) : true;
                    const showStatus = selectedStatuses.length ? selectedStatuses.includes(status) : true;

                    row.style.display = (showRole && showStatus) ? '' : 'none';
                });
            }

            // Sorting (name/date)
            function sortTable(type, direction) {
                const rows = Array.from(document.querySelectorAll('#usersTable tbody tr'));
                rows.sort((a, b) => {
                    const valA = a.dataset[type].toLowerCase();
                    const valB = b.dataset[type].toLowerCase();
                    if (type === 'date') {
                        return (new Date(valA) - new Date(valB)) * (direction === 'asc' ? 1 : -1);
                    }
                    return valA.localeCompare(valB) * (direction === 'asc' ? 1 : -1);
                });
                const tbody = document.querySelector('#usersTable tbody');
                tbody.innerHTML = '';
                rows.forEach(r => tbody.appendChild(r));
            }



            function toggleFilter(id, event) {
                event.stopPropagation(); // Prevent immediate close

                // Close all other menus and reset icons
                document.querySelectorAll('.filter-menu').forEach(menu => {
                    if (menu.id !== id) menu.style.display = 'none';
                });
                document.querySelectorAll('.filter-btn i').forEach(icon => {
                    icon.style.transform = 'rotate(0deg)';
                });

                const menu = document.getElementById(id);
                const isVisible = menu.style.display === 'block';

                menu.style.display = isVisible ? 'none' : 'block';

                // Rotate icon
                const buttonIcon = menu.parentElement.querySelector('.filter-btn i');
                buttonIcon.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(180deg)';
                buttonIcon.style.transition = 'transform 0.2s ease';
            }

            // Close dropdowns when clicking outside
            document.addEventListener('click', e => {
                if (!e.target.closest('.filter-header')) {
                    document.querySelectorAll('.filter-menu').forEach(menu => menu.style.display = 'none');
                    document.querySelectorAll('.filter-btn i').forEach(icon => icon.style.transform = 'rotate(0deg)');
                }
            });

    let currentSort = { column: null, order: 'asc' };

    function toggleSort(column) {
        const table = document.getElementById('usersTable');
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));

        // Determine next order
        let order = 'asc';
        if (currentSort.column === column && currentSort.order === 'asc') {
            order = 'desc';
        }
        currentSort = { column, order };

        // Determine column index
        const headers = Array.from(table.querySelectorAll('thead th'));
        const columnIndex = headers.findIndex(th => th.textContent.trim().toLowerCase() === column.toLowerCase());

        // Sort rows
        rows.sort((a, b) => {
            let aText = a.children[columnIndex].innerText.trim().toLowerCase();
            let bText = b.children[columnIndex].innerText.trim().toLowerCase();

            if (column === 'date registered') { // for date column
                return (new Date(aText) - new Date(bText)) * (order === 'asc' ? 1 : -1);
            }

            return order === 'asc' ? aText.localeCompare(bText) : bText.localeCompare(aText);
        });

        rows.forEach(row => tbody.appendChild(row));

        // Reset all icons rotation
        document.querySelectorAll('.filter-header .filter-btn i').forEach(icon => {
            icon.style.transform = 'rotate(0deg)';
            icon.style.transition = 'transform 0.2s ease';
        });

        // Rotate the active column icon
        const activeIcon = headers[columnIndex].querySelector('.filter-btn i');
        activeIcon.style.transform = order === 'asc' ? 'rotate(180deg)' : 'rotate(0deg)';
        activeIcon.style.transition = 'transform 0.2s ease';
    }


    // CHANGE ROLE FUN
    function changeRole(userId, newRole) {
        if (!confirm(`Are you sure you want to change this user's role to "${newRole}"?`)) return;

        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'change_role',
                user_id: userId,
                role: newRole
            })
        })
        .then(res => res.text())
        .then(data => {
            // Optional: show a success message
            console.log(`Role changed for user ${userId} to ${newRole}`);
            // Optionally reload page or update badge in table
            const row = document.querySelector(`#usersTable tbody tr[data-role][data-user-id='${userId}']`);
            if (row) {
                row.dataset.role = newRole;
                row.querySelector('td:nth-child(3) span.badge').textContent = newRole.charAt(0).toUpperCase() + newRole.slice(1);
            }
        })
        .catch(err => console.error(err));
    }



    document.getElementById('saveTemplateRolesBtn').addEventListener('click', () => {
    const form = document.getElementById('templateRolesForm');
    const formData = new FormData(form);
    formData.append('action', 'save_template_roles');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.text())
    .then(res => {
        if (res.trim() === 'success') {
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: 'Template roles saved successfully!'
        }).then(() => {
            bootstrap.Modal.getInstance(document.getElementById('templateRolesModal')).hide();
            location.reload();
        });
        } else {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Something went wrong while saving.'
        });
        }
    })
    .catch(err => console.error(err));
    });


    </script>




    <?php include 'footer.php'; ?>
