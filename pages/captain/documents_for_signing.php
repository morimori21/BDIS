<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('captain');

// Handle signing via POST to prevent duplicate actions (Post/Redirect/Get)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sign_request_id'])) {
    $request_id = (int)$_POST['sign_request_id'];
    // Update status to signed (from printed to signed)
    $stmt = $pdo->prepare("UPDATE document_requests SET request_status = 'signed' WHERE request_id = ?");
    $stmt->execute([$request_id]);

    // Get the requesting resident to notify
    $stmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch();
    if ($req && $req['resident_id']) {
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
        $notif->execute([$req['resident_id'], 'document', 'Your document has been signed and is ready for pickup', $request_id]);
    }

    logActivity($_SESSION['user_id'], "Signed document: $request_id");
    header('Location: documents_for_signing.php');
    exit;
}

include 'header.php'; 

// Ensure pickup_schedule_id column exists in document_requests table
try {
    $pdo->exec("ALTER TABLE document_requests ADD COLUMN IF NOT EXISTS pickup_schedule_id INT NULL");
} catch (PDOException $e) {
    // Column might already exist, check manually
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM document_requests LIKE 'pickup_schedule_id'");
        if ($colCheck->rowCount() == 0) {
            $pdo->exec("ALTER TABLE document_requests ADD COLUMN pickup_schedule_id INT NULL");
        }
    } catch (PDOException $e2) {
        // Ignore if column creation fails
    }
}
?>

<div class="container mt-4">
    <h2 class="mb-4">Documents for Signing</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Resident</th>
                <th>Document Type</th>
                <th>Reason</th>
                <th>Pickup Date</th>
                <th>Generated Document</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $stmt = $pdo->query("
                SELECT dr.*, dt.doc_name as doc_type_name, u.first_name, u.surname, 
                       s.schedule_date as pickup_date
                FROM document_requests dr
                JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                JOIN users u ON dr.resident_id = u.user_id
                LEFT JOIN schedule s ON dr.schedule_id = s.schedule_id
                WHERE dr.request_status = 'printed'
                ORDER BY dr.date_requested DESC
            ");
            while ($request = $stmt->fetch()) {
                $rid = (int)$request['request_id'];
                $fname = htmlspecialchars($request['first_name']);
                $lname = htmlspecialchars($request['surname']);
                $dname = htmlspecialchars($request['doc_type_name']);
                $reason = htmlspecialchars($request['request_purpose'] ?? 'N/A');           
                $pdate = $request['pickup_date'] ? date('M j, Y', strtotime($request['pickup_date'])) : 'Not specified';
                // $rid = (int)$request['request_id'];
                // $fname = htmlspecialchars($request['first_name']);
                // $lname = htmlspecialchars($request['surname']);
                // $dname = htmlspecialchars($request['name']);
                // $reason = htmlspecialchars($request['reason']);
                // $pdate = htmlspecialchars($request['pickup_date']);
                
                // Always show View button to open the document viewer in modal
                $docDownload = "<button class='btn btn-sm btn-info' type='button' onclick='viewDocument({$rid})'>👁️ View</button>";
                
                echo "<tr>";
                echo "<td>{$fname} {$lname}</td>";
                echo "<td>{$dname}</td>";
                echo "<td>{$reason}</td>";
                echo "<td>{$pdate}</td>";
                echo "<td>{$docDownload}</td>";
                echo "<td>
                    <span class='badge bg-info'><i class='bi bi-hourglass-split'></i> Waiting for Signature</span>
                </td>";
                echo "</tr>";
            }
            ?>
        </tbody>
    </table>
    
</div>

<!-- Document Preview Modal -->
<div class="modal fade" id="documentPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Document Preview & Print</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="documentPreviewFrame" style="width: 100%; height: 80vh; border: none;"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" id="modalSignBtn" onclick="signDocumentFromModal()"><i class="bi bi-pen"></i> Sign</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function viewDocument(requestId) {
    console.log('viewDocument called:', requestId);
    // Store the current request ID for signing
    window.currentRequestId = requestId;
    
    const iframe = document.getElementById('documentPreviewFrame');
    iframe.src = `../secretary/view_document.php?request_id=${requestId}`;
    const modal = new bootstrap.Modal(document.getElementById('documentPreviewModal'));
    modal.show();
}

function signDocumentFromModal() {
    if (window.currentRequestId) {
        // Create form and submit to sign the document
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="sign_request_id" value="${window.currentRequestId}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function printDocumentFromModal() {
    const iframe = document.getElementById('documentPreviewFrame');
    iframe.contentWindow.print();
}
</script>

<?php include 'footer.php'; ?>
