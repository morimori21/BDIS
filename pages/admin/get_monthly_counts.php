<?php
require '../../includes/config.php'; 

if(isset($_GET['year'])){
    $year = (int)$_GET['year'];
    
    $monthlyCounts = array_fill(1, 12, 0);
    
    $stmt = $pdo->prepare("
        SELECT MONTH(date_requested) AS month, COUNT(*) AS count
        FROM document_requests
        WHERE request_status = 'completed' AND YEAR(date_requested) = :year
        GROUP BY MONTH(date_requested)
    ");
    $stmt->execute(['year' => $year]);
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
        $monthlyCounts[(int)$row['month']] = (int)$row['count'];
    }
    
    $firstHalfCounts = array_slice($monthlyCounts, 0, 6);
    $secondHalfCounts = array_slice($monthlyCounts, 6, 6);
    
    $firstHalfMonths = [];
    $secondHalfMonths = [];
    for($m=1;$m<=6;$m++) $firstHalfMonths[] = date('M', mktime(0,0,0,$m,1));
    for($m=7;$m<=12;$m++) $secondHalfMonths[] = date('M', mktime(0,0,0,$m,1));
    
    echo json_encode([
        'firstMonths'=>$firstHalfMonths,
        'firstCounts'=>$firstHalfCounts,
        'secondMonths'=>$secondHalfMonths,
        'secondCounts'=>$secondHalfCounts
    ]);
}
?>
