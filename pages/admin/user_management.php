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

// Fetch all users with roles
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
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
}
.table td img {
    width: 40px;
    height: 40px;
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
</style>


<div class="container">

<div class="row mb-4">
  <h2 class="mb-4">User Management
  </h2>
</div>

    <!-- Users Table -->
    <div class="card">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>User Directory</h5>
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
    </div>
</div>



<script>
    //All Checkboxes select all
    document.getElementById('selectAll').addEventListener('change', function() {
        document.querySelectorAll('.userCheckbox').forEach(cb => cb.checked = this.checked);
    });

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





</script>




<?php include 'footer.php'; ?>
