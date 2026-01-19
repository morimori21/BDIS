<?php
require_once '../../includes/config.php';
redirectIfNotLoggedIn();
$role = getUserRole();
if ($role !== 'secretary' && $role !== 'admin') {
    header('Location: /Project_A2/unauthorized.php');
    exit;
}

header('Content-Type: text/plain');
echo "Backfilling request_remarks...\n\n";

$map = [
    'pending' => 'Your Have Requested A Document, awaiting for approval',
    'in-progress' => 'Your Requested Document has been approved and waiting to be printed',
    'printed' => 'Your Document is printed, waiting for Barangay Captain to sign it.',
    'for-signing' => 'Your Document is printed, waiting for Barangay Captain to sign it.',
    'signed' => 'Your document is signed and ready for pickup',
    'completed' => 'The Document is already Received',
];

$total = 0;
foreach ($map as $status => $remarks) {
    $sql = "UPDATE document_requests 
            SET request_remarks = :remarks 
            WHERE request_status = :status 
              AND (request_remarks IS NULL OR request_remarks = '' OR request_remarks = request_purpose)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['remarks' => $remarks, 'status' => $status]);
    $count = $stmt->rowCount();
    $total += $count;
    echo sprintf("%s -> %d updated\n", $status, $count);
}

// For rejected: keep existing rejection reason if any, otherwise set generic label
$sqlRejected = "UPDATE document_requests 
                SET request_remarks = 'Reason of Rejection' 
                WHERE request_status = 'rejected' 
                  AND (request_remarks IS NULL OR request_remarks = '' OR request_remarks = request_purpose)";
$rejectedCount = $pdo->exec($sqlRejected);
$total += (int)$rejectedCount;
echo sprintf("rejected -> %d updated\n", (int)$rejectedCount);

echo "\nTotal updated: {$total}\nDone.\n";
