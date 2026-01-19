<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();

// Helper function for file size formatting
if (!function_exists('formatBytes')) {
    function formatBytes($size, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB');
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        return round($size, $precision) . ' ' . $units[$i];
    }
}

// Handle barangay update BEFORE any output
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_barangay'])) {
    $stmt = $pdo->query("SELECT * FROM address_config LIMIT 1");
    $barangay = $stmt->fetch();
    
    $brgy_name = sanitize($_POST['brgy_name']);
    $municipality = sanitize($_POST['municipality']);
    $province = sanitize($_POST['province']);
    
    // Keep existing logos if no new ones uploaded
    $brgy_logo = $barangay['brgy_logo'] ?? null;
    $city_logo = $barangay['city_logo'] ?? null;

    // Handle Barangay Logo upload
    if (isset($_FILES['brgy_logo']) && $_FILES['brgy_logo']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $filename = $_FILES['brgy_logo']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            // Validate MIME type for additional safety
            $mime = mime_content_type($_FILES['brgy_logo']['tmp_name']);
            $valid_mimes = ['image/jpeg', 'image/png'];
            
            if (in_array($mime, $valid_mimes)) {
                // Store image as BLOB
                $brgy_logo = file_get_contents($_FILES['brgy_logo']['tmp_name']);
            }
        }
    }

    // Handle City Logo upload
    if (isset($_FILES['city_logo']) && $_FILES['city_logo']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $filename = $_FILES['city_logo']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            // Validate MIME type for additional safety
            $mime = mime_content_type($_FILES['city_logo']['tmp_name']);
            $valid_mimes = ['image/jpeg', 'image/png'];
            
            if (in_array($mime, $valid_mimes)) {
                // Store image as BLOB
                $city_logo = file_get_contents($_FILES['city_logo']['tmp_name']);
            }
        }
    }

    if ($barangay) {
        $stmt = $pdo->prepare("UPDATE address_config SET brgy_name = ?, municipality = ?, province = ?, brgy_logo = ?, city_logo = ? WHERE address_id = ?");
        $stmt->execute([$brgy_name, $municipality, $province, $brgy_logo, $city_logo, $barangay['address_id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO address_config (brgy_name, municipality, province, brgy_logo, city_logo) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$brgy_name, $municipality, $province, $brgy_logo, $city_logo]);
    }
    logActivity($_SESSION['user_id'], 'Updated barangay details');
    
    // Set success message in session and redirect
    $_SESSION['barangay_update_success'] = true;
    header('Location: system_configuration.php');
    exit;
}

// Handle toggling document status BEFORE any output
if (isset($_GET['toggle_status']) && is_numeric($_GET['toggle_status'])) {
    $doc_id = $_GET['toggle_status'];
    $stmt = $pdo->prepare("SELECT doc_status, doc_name FROM document_types WHERE doc_type_id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch();
    
    if ($doc) {
        $new_status = ($doc['doc_status'] == 'Open') ? 'Closed' : 'Open';
        $stmt = $pdo->prepare("UPDATE document_types SET doc_status = ? WHERE doc_type_id = ?");
        $stmt->execute([$new_status, $doc_id]);
        logActivity($_SESSION['user_id'], "Changed {$doc['doc_name']} status to {$new_status}");
        
        // Redirect to remove the parameter from URL
        $_SESSION['success_message'] = "Document status updated to {$new_status}!";
        header('Location: system_configuration.php');
        exit;
    }
}

// Handle updating price BEFORE any output
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_price'])) {

    $doc_id = $_POST['doc_type_id'];
    $new_price = $_POST['price'];

    // Get the old document info
    $stmt = $pdo->prepare("SELECT * FROM document_types WHERE doc_type_id = ?");
    $stmt->execute([$doc_id]);
    $oldDoc = $stmt->fetch();

    if ($oldDoc) {

        // 1. CLOSE OLD PRICE ENTRY
        $stmt = $pdo->prepare("UPDATE document_types SET doc_status = 'Closed' WHERE doc_type_id = ?");
        $stmt->execute([$doc_id]);

        // 2. INSERT NEW PRICE ENTRY (THIS IS THE UPDATE)
        // Ensure to use the correct doc_name from the old record.
        $stmt = $pdo->prepare("
            INSERT INTO document_types (doc_name, doc_price, doc_status) 
            VALUES (?, ?, 'Open')
        ");
        $stmt->execute([$oldDoc['doc_name'], $new_price]);

        logActivity($_SESSION['user_id'], "Updated price for {$oldDoc['doc_name']} to ₱{$new_price} (new record inserted)");

        $_SESSION['success_message'] = "Price updated successfully!";
        header('Location: system_configuration.php');
        exit;
    }
}


include 'header.php';
?>

<style>
    /* Custom Card Style for clean white look */
    .custom-card {
        background: #fff;
        border: 1px solid #e9ecef; /* Subtle border */
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05); /* Light shadow */
        padding: 20px;
        margin-bottom: 20px;
    }
    /* Style for form inputs */
    .form-control:focus {
        border-color: #0d6efd; /* Blue focus color */
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }
    /* Logo Preview Styles */
    .logo-preview-box {
        width: 60px;
        height: 60px;
        object-fit: contain;
        border: 1px solid #dee2e6;
        padding: 5px;
        border-radius: 6px;
    }
    /* Table Header style */
    .table-dark-blue thead {
        background-color: #0d6efd; /* Blue header */
        color: white;
    }
    .table-dark-blue thead th {
        border-color: #0d6efd;
    }
</style>

<div class="container">
    <h2 class="mb-4">System Configuration</h2>
    
    <div class="row g-4">
        
        <div class="col-md-6">
            <div class="custom-card">
                <h4 class="mb-3 text-primary">Barangay Details</h4>
                <?php
                // Get current barangay details
                $stmt = $pdo->query("SELECT * FROM address_config LIMIT 1");
                $barangay = $stmt->fetch();
                
                // Display success message if exists
                if (isset($_SESSION['barangay_update_success'])) {
                    echo "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                            <i class='bi bi-check-circle-fill me-1'></i> Barangay details updated successfully!
                            <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                          </div>";
                    unset($_SESSION['barangay_update_success']);
                }
                
                if (isset($_SESSION['officials_update_success'])) {
                    echo "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                            <i class='bi bi-check-circle-fill me-1'></i> Barangay officials updated!
                            <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                          </div>";
                    unset($_SESSION['officials_update_success']);
                }
                ?>
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="brgy_name" class="form-label fw-bold">Barangay Name</label>
                        <input type="text" class="form-control form-control-sm" id="brgy_name" name="brgy_name" 
                               value="<?php echo htmlspecialchars($barangay['brgy_name'] ?? ''); ?>" required 
                               onkeydown="preventEnterSubmit(event)">
                    </div>
                    <div class="mb-3">
                        <label for="municipality" class="form-label fw-bold">Municipality / City</label>
                        <input type="text" class="form-control form-control-sm" id="municipality" name="municipality" 
                               value="<?php echo htmlspecialchars($barangay['municipality'] ?? ''); ?>" required 
                               onkeydown="preventEnterSubmit(event)">
                    </div>
                    <div class="mb-3">
                        <label for="province" class="form-label fw-bold">Province</label>
                        <input type="text" class="form-control form-control-sm" id="province" name="province" 
                               value="<?php echo htmlspecialchars($barangay['province'] ?? ''); ?>" required 
                               onkeydown="preventEnterSubmit(event)">
                    </div>
                    
                    <hr class="my-3">
                    
                    <div class="mb-3">
                        <label for="brgy_logo" class="form-label fw-bold">Barangay Logo (JPG or PNG)</label>
                        <div class="d-flex align-items-center gap-3">
                            <?php if ($barangay && !empty($barangay['brgy_logo'])): ?>
                                <div class="text-center">
                                    <img src="data:image/png;base64,<?php echo base64_encode($barangay['brgy_logo']); ?>" 
                                         alt="Current Barangay Logo" class="logo-preview-box">
                                    <small class="text-muted d-block" style="font-size: 0.7rem;">Current</small>
                                </div>
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <input type="file" class="form-control form-control-sm" id="brgy_logo" name="brgy_logo" accept=".jpg,.jpeg,.png">
                                <small class="form-text text-muted">Leave empty to keep the current logo.</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="city_logo" class="form-label fw-bold">City/Municipality Logo (JPG or PNG)</label>
                        <div class="d-flex align-items-center gap-3">
                            <?php if ($barangay && !empty($barangay['city_logo'])): ?>
                                <div class="text-center">
                                    <img src="data:image/png;base64,<?php echo base64_encode($barangay['city_logo']); ?>" 
                                         alt="Current City Logo" class="logo-preview-box">
                                    <small class="text-muted d-block" style="font-size: 0.7rem;">Current</small>
                                </div>
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <input type="file" class="form-control form-control-sm" id="city_logo" name="city_logo" accept=".jpg,.jpeg,.png">
                                <small class="form-text text-muted">Leave empty to keep the current logo.</small>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" name="update_barangay" class="btn btn-primary w-100">
                        <i class="bi bi-save-fill me-1"></i> Save Barangay Details
                    </button>
                </form>
            </div>
        </div>
        
        <div class="col-md-6">
             <div class="custom-card">
                <h4 class="mb-3 text-primary">Document Types & Prices</h4>
                <?php
                // Display success message if exists
                if (isset($_SESSION['success_message'])) {
                    echo "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                            <i class='bi bi-check-circle-fill me-1'></i> {$_SESSION['success_message']}
                            <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                          </div>";
                    unset($_SESSION['success_message']);
                }
                ?>
                
                <div class="table-responsive">
                    <table class="table table-hover table-sm table-dark-blue">
                        <thead class="table-dark-blue">
                            <tr>
                                <th>Document Name</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("
                                SELECT dt.*
                                FROM document_types dt
                                INNER JOIN (
                                    SELECT doc_name, MAX(doc_type_id) AS max_id
                                    FROM document_types
                                    GROUP BY doc_name
                                ) latest ON dt.doc_type_id = latest.max_id
                                ORDER BY dt.doc_name ASC
                            ");
                            while ($type = $stmt->fetch()) {
                                $statusBadge = $type['doc_status'] == 'Open' 
                                    ? "<span class='badge bg-success'><i class='bi bi-check-circle'></i> Open</span>" 
                                    : "<span class='badge bg-secondary'><i class='bi bi-x-circle'></i> Closed</span>";
                                
                                $toggleIcon = $type['doc_status'] == 'Open' ? 'toggle-on' : 'toggle-off';
                                $toggleColor = $type['doc_status'] == 'Open' ? 'success' : 'secondary';
                                
                                // Assuming formatNumberShort() is defined in includes/config.php or elsewhere
                                $priceDisplay = "₱" . (function_exists('formatNumberShort') ? formatNumberShort($type['doc_price'], 2) : number_format($type['doc_price'], 2));
                                
                                echo "<tr>
                                    <td><strong>{$type['doc_name']}</strong></td>
                                    <td>{$priceDisplay}</td>
                                    <td>{$statusBadge}</td>
                                    <td class='text-center text-nowrap'>
                                        <button class='btn btn-sm btn-primary me-1' onclick=\"openEditModal({$type['doc_type_id']}, '{$type['doc_name']}', {$type['doc_price']})\" title='Edit Price'>
                                            <i class='bi bi-pencil'></i>
                                        </button>
                                        <a href='?toggle_status={$type['doc_type_id']}' class='btn btn-sm btn-{$toggleColor}' title='Toggle Status'>
                                            <i class='bi bi-{$toggleIcon}'></i>
                                        </a>
                                    </td>
                                </tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editPriceModal" tabindex="-1" aria-labelledby="editPriceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="editPriceModalLabel">
                    <i class="bi bi-pencil-square me-2"></i> Edit Document Price
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="doc_type_id" id="edit_doc_type_id">
                    
                    <div class="mb-3">
                        <label for="edit_doc_name" class="form-label fw-bold">Document Name</label>
                        <input type="text" class="form-control" id="edit_doc_name" readonly aria-describedby="docNameHelp">
                        <div id="docNameHelp" class="form-text">Changing the document name is not allowed here.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_price" class="form-label fw-bold">New Price (₱)</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-lg border-primary" 
                               id="edit_price" name="price" required placeholder="0.00">
                    </div>
                    
                    <div class="alert alert-info py-2" role="alert" style="font-size: 0.9rem;">
                        <i class="bi bi-info-circle me-1"></i> Note: This action creates a new price record, effectively "closing" the old price.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </button>
                    <button type="submit" name="update_price" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i> Update Price
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
function openEditModal(docTypeId, docName, currentPrice) {
    document.getElementById('edit_doc_type_id').value = docTypeId;
    document.getElementById('edit_doc_name').value = docName;
    document.getElementById('edit_price').value = currentPrice;
    
    // Check if the current price is formatted (e.g., '10.00' vs 10) and ensure the input accepts it.
    // The current code passes the raw numeric price, which is ideal.
    
    const modal = new bootstrap.Modal(document.getElementById('editPriceModal'));
    modal.show();
}

function confirmDelete(typeName) {
    return confirm('Are you sure you want to delete the document type: ' + typeName + '?');
}

function preventEnterSubmit(event) {
    // Prevent form submission when Enter is pressed in input fields
    if (event.key === 'Enter' || event.keyCode === 13) {
        event.preventDefault();
        return false;
    }
}

// Note: handleOfficialsSubmit and previewTemplate were not used in the final configuration, 
// so they are commented out or removed for cleaner code unless needed later.
</script>

<?php include 'footer.php'; ?>