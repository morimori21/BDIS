<style>
.stat-card {
  border-radius: 16px;
  padding: 1rem;
  height: auto;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  transition: all 0.2s ease-in-out;
  color: #000; 
  min-height: 140px;
}
.row.g-3 {
  --bs-gutter-x: 0.75rem;
  --bs-gutter-y: 0.75rem;
}


.stat-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
}

.stat-header {
  font-weight: 600;
  font-size: 0.8rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.stat-body {
  font-size:2.5rem;
  font-weight: 700;
  margin: 0.5rem 0;
}

.stat-footer {
  font-size: .7rem;
  color: #000;
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.stat-icon {
  width: 24px;
  height: 22px;
  border: 1px solid #000;
  border-radius: 50%;
  display: flex;
  justify-content: center;
  align-items: center;
  color: #000;
}

.bg-green-gradient {
  background: linear-gradient(135deg, #2e7d32, #388e3c);
  color: #fff;
}
</style>


<?php include 'header.php'; ?>

<div class="container">

<?php
// Secretary sees ALL documents, not just their own
$userId = $_SESSION['user_id'];

// Query list - Secretary version (all documents)
$queries = [

    'total_requests' => "
        SELECT COUNT(*)
        FROM document_requests
        WHERE request_status NOT IN ('ready', 'completed')
    ",
    // Pending
    'pending' => "
        SELECT COUNT(*)
        FROM document_requests
        WHERE request_status = 'pending'
    ",

    // In-progress
    'in_progress' => "
        SELECT COUNT(*)
        FROM document_requests
        WHERE request_status IN ('approved', 'in-progress', 'printed')
    ",

    // Completed (ready)
    'completed' => "
        SELECT COUNT(*)
        FROM document_requests
        WHERE request_status IN ('ready', 'signed', 'completed')
    ",

    // Total revenue
    'total_spent' => "
        SELECT COALESCE(SUM(dt.doc_price), 0)
        FROM document_requests dr
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
        WHERE dr.request_status IN ('printed', 'signed', 'completed')
    ",

    // Active Schedule (latest with slots > 0)
    'active_schedule' => "
        SELECT schedule_id, schedule_date, schedule_slots
        FROM schedule
        WHERE schedule_date >= CURDATE() AND schedule_slots > 0
        ORDER BY schedule_date ASC
        LIMIT 1
    ",

    // Current active request details (excluding completed) - most recent
    'current_request' => "
        SELECT dr.request_id, dr.request_status, dr.date_requested, dt.doc_name
        FROM document_requests dr
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
        WHERE dr.request_status NOT IN ('ready', 'completed')
        ORDER BY dr.date_requested DESC
        LIMIT 1
    "
];

$stats = [];
foreach ($queries as $key => $sql) {

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    if ($key === 'active_schedule' || $key === 'current_request') {
        $stats[$key] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $stats[$key] = $stmt->fetchColumn() ?? 0;
    }
}


extract($stats);

?>

<?php
// Get completed requests in the past 7 days (all requests)
$completedRequests = $pdo->prepare("
    SELECT DATE(date_requested) AS request_date, COUNT(*) AS total
    FROM document_requests
    WHERE request_status IN ('ready', 'signed', 'completed')
      AND date_requested >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DATE(date_requested)
    ORDER BY DATE(date_requested)
");
$completedRequests->execute();
$completedData = $completedRequests->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for chart
$chartLabels = [];
$chartData = [];

// Initialize week labels with 0 counts
for ($i = 6; $i >= 0; $i--) {
    $day = date('D M d', strtotime("-$i days"));
    $chartLabels[] = $day;
    $chartData[$day] = 0;
}

// Fill in the actual data
foreach ($completedData as $row) {
    $dayLabel = date('D M d', strtotime($row['request_date']));
    $chartData[$dayLabel] = (int)$row['total'];
}
?>



<div class="row g-3 mb-3">

    <!-- left block-->
    <div class="col-lg-8 d-flex flex-column gap-3">
    <!-- stats  -->
        <div class="p-3 rounded" style="background:#f6c1c1;">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="stat-card bg-green-gradient">
                        <div class="stat-header">
                            <span>Total Request</span>
                            <span class="stat-icon bg-white text-success">
                                <i class="fa fa-level-up"></i>
                            </span>
                        </div>
                        <div class="stat-body">
                            <?php echo $stats['total_requests']; ?>
                        </div>
                        <div class="stat-footer text-white">
                            Ongoing Request
                        </div>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="stat-card border border-dark bg-white">
                        <div class="stat-header">
                            <span>Pending</span>
                        </div>
                        <div class="stat-body text-black">
                            <?php echo $stats['pending']; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="stat-card border border-dark bg-white">
                        <div class="stat-header">
                            <span>In Progress</span>
                        </div>
                        <div class="stat-body text-black">
                            <?php echo $stats['in_progress']; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="stat-card border border-dark bg-white">
                        <div class="stat-header">
                            <span>Ready</span>
                        </div>
                        <div class="stat-body text-black">
                            <?php echo $stats['completed']; ?>
                        </div>
                    </div>
                </div>

                

                <div class="col-md-3">
                    <div class="stat-card border border-dark bg-white">
                        <div class="stat-header">
                            <span>Total Revenue</span>
                            <span class="stat-icon">
                                <i class="fa fa-level-up"></i>
                            </span>
                        </div>
                        <div class="stat-body text-black">
                            ₱<?php echo number_format($stats['total_spent'], 2); ?>
                        </div>
                        <div class="stat-footer">
                            Total earnings
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- active schedule -->
        <div class="p-4 rounded d-flex align-items-center justify-content-center text-center fw-bold" style="background:#eacbff; min-height:200px;">
            Active Schedule
        </div>

    </div>

    <!-- Rright block  -->
    <div class="col-lg-4">
        
        <div class="p-4 rounded d-flex flex-column gap-3 text-center fw-bold" style="background:#cce6ff; min-height:420px;">

        <!-- request history line graph -->
            <div class="flex-fill d-flex justify-content-center align-items-center p-2 rounded" style="background:#bcd9f6; flex-direction: column;">
                <canvas id="requestLineChart" style="width:100%; max-height:250px;"></canvas>

                <?php if(array_sum($chartData) === 0): ?>
                    <div class="mt-2 fw-bold">No requests this week</div>
                <?php endif; ?>
            </div>

        <!-- recent request -->
            <div class="flex-fill d-flex justify-content-center align-items-center p-2 rounded" style="background:#b7ffd8;">
                Current Request Status
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('requestLineChart').getContext('2d');
const labels = <?php echo json_encode(array_keys($chartData)); ?>;
const data = <?php echo json_encode(array_values($chartData)); ?>;

new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Completed Requests',
            data: data,
            fill: false,
            borderColor: '#1976d2',
            backgroundColor: '#1976d2',
            tension: 0.3,
            pointRadius: 5,
            pointHoverRadius: 7
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: true,
                position: 'top'
            },
            tooltip: {
                mode: 'index',
                intersect: false
            }
        },
        scales: {
            x: {
                title: {
                    display: true,
                    text: 'Day of the Week'
                }
            },
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Number of Completed Requests'
                },
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});
</script>
<?php include 'footer.php'; ?>
