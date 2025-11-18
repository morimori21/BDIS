<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
redirectIfNotRole('secretary');

// Handle signing via POST to prevent duplicate actions (Post/Redirect/Get)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sign_request_id'])) {
    $request_id = (int)$_POST['sign_request_id'];
    // Update status to signed (from printed to signed)
    $stmt = $pdo->prepare("UPDATE document_requests SET request_status = 'signed' WHERE request_id = ?");
    $stmt->execute([$request_id]);

    // Get the requesting user to notify
    $stmt = $pdo->prepare("SELECT resident_id FROM document_requests WHERE request_id = ?");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch();
    if ($req && $req['resident_id']) {
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
        $notif->execute([$req['resident_id'], 'document', "Your document request #$request_id has been signed and is ready for pickup.", $request_id]);
    }

    // Notify secretaries that document is signed
    $stmt = $pdo->prepare("SELECT u.user_id FROM users u JOIN user_roles ur ON u.user_id = ur.user_id WHERE ur.role = 'secretary'");
    $stmt->execute();
    $secretaries = $stmt->fetchAll();
    foreach ($secretaries as $sec) {
        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, notif_type, notif_topic, notif_entity_id) VALUES (?, ?, ?, ?)");
        $notifStmt->execute([$sec['user_id'], 'document', "Document request #$request_id has been signed.", $request_id]);
    }

    logActivity($_SESSION['user_id'], "Signed document: $request_id");
    header('Location: documents_for_signing.php');
    exit;
}

include 'header.php'; 
?>

<div class="container">
    <h2>Documents for Signing</h2>
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
            $stmt = $pdo->query("SELECT dr.*, dt.doc_name as doc_type_name, r.first_name, r.surname
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    JOIN users r ON dr.resident_id = r.user_id
    WHERE dr.request_status = 'printed'
    ORDER BY dr.date_requested DESC");
            while ($request = $stmt->fetch()) {
                $rid = (int)$request['request_id'];
                $fname = htmlspecialchars($request['first_name']);
                $lname = htmlspecialchars($request['surname']);
                $dname = htmlspecialchars($request['doc_type_name']);
                $reason = htmlspecialchars($request['request_purpose'] ?? 'N/A');           
                $pdate = htmlspecialchars($request['pickup_representative'] ?? 'Not specified');
                // $rid = (int)$request['request_id'];
                // $fname = htmlspecialchars($request['first_name']);
                // $lname = htmlspecialchars($request['surname']);
                // $dname = htmlspecialchars($request['name']);
                // $reason = htmlspecialchars($request['reason']);
                // $pdate = htmlspecialchars($request['pickup_date']);
                
                $docDownload = '';
                if ($request['generated_document']) {
                    $docDownload = "<button class='btn btn-sm btn-info' type='button' onclick=\"viewDocument('{$request['generated_document']}')\">👁️ View Document</button>";
                } else {
                    $docDownload = "<span class='text-muted'>Not available</span>";
                }
                
                echo "<tr>";
                echo "<td>{$fname} {$lname}</td>";
                echo "<td>{$dname}</td>";
                echo "<td>{$reason}</td>";
                echo "<td>{$pdate}</td>";
                echo "<td>{$docDownload}</td>";
                echo "<td>
                    <div class='d-flex gap-2'>
                        <form method='POST' style='display:inline;'>
                            <input type='hidden' name='sign_request_id' value='{$rid}'>
                            <button type='submit' class='btn btn-sm btn-success'>✍️ Mark as Signed</button>
                        </form>
                    </div>
                </td>";
                echo "</tr>";
            }
            ?>
        </tbody>
    </table>
    
</div>

<script>
function viewDocument(filename) {
    console.log('viewDocument called:', filename);
    // Open document in new tab for viewing
    window.open(`../../uploads/generated_documents/${filename}`, '_blank');
}
</script>

<?php include 'footer.php'; ?>
