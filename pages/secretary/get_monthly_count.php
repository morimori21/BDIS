<?php
require_once '../../includes/config.php';

header('Content-Type: application/json');

try {
    $year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

    // Validate year
    if ($year < 2000 || $year > 2100) {
        throw new Exception('Invalid year');
    }

    // Initialize counts for all 12 months
    $monthlyCounts = array_fill(1, 12, 0);

    // Query to get all requests per month, regardless of status
    $stmt = $pdo->prepare("
        SELECT MONTH(date_requested) AS month, COUNT(*) AS count
        FROM document_requests
        WHERE YEAR(date_requested) = :year
        GROUP BY MONTH(date_requested)
        ORDER BY MONTH(date_requested)
    ");
    $stmt->execute(['year' => $year]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $monthlyCounts[(int)$row['month']] = (int)$row['count'];
    }

    // Split into first and second half of the year
    $firstHalfMonths = $firstHalfCounts = [];
    for ($m = 1; $m <= 6; $m++) {
        $firstHalfMonths[] = date('M', mktime(0, 0, 0, $m, 1));
        $firstHalfCounts[] = $monthlyCounts[$m];
    }

    $secondHalfMonths = $secondHalfCounts = [];
    for ($m = 7; $m <= 12; $m++) {
        $secondHalfMonths[] = date('M', mktime(0, 0, 0, $m, 1));
        $secondHalfCounts[] = $monthlyCounts[$m];
    }

    echo json_encode([
        'success' => true,
        'firstMonths' => $firstHalfMonths,
        'firstCounts' => $firstHalfCounts,
        'secondMonths' => $secondHalfMonths,
        'secondCounts' => $secondHalfCounts,
        'year' => $year,
        'usedStatus' => 'all'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
