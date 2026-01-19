<?php include 'header.php'; ?>
<link rel="stylesheet" href="path/to/font-awesome/css/font-awesome.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

<div class="container">
    <!-- Main Statistics -->
    <div class="row mb-4">
        <?php
        // general stats
        $stats = [
            'pending_signature' => $pdo->query("SELECT COUNT(*) FROM document_requests WHERE request_status = 'printed'")->fetchColumn(),
            'signed_documents' => $pdo->query("SELECT COUNT(*) FROM document_requests WHERE request_status = 'signed'")->fetchColumn(),
            'completed_documents' => $pdo->query("SELECT COUNT(*) FROM document_requests WHERE request_status = 'completed'")->fetchColumn(),
            'total_documents' => $pdo->query("SELECT COUNT(*) FROM document_requests")->fetchColumn(),
            'my_requests' => $pdo->query(" SELECT COUNT(*) FROM document_requests WHERE resident_id = {$_SESSION['user_id']}")->fetchColumn()
        ];
        ?>

<!-- FETCHERs -->
 <?php
 //revenue stat
                        $currentMonth = date('Y-m');
                        
      
                        $stmt = $pdo->prepare("
                             SELECT COALESCE(SUM(dt.doc_price), 0) as monthly_income 
                            FROM document_requests dr 
                            JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id 
                            WHERE DATE_FORMAT(dr.date_requested, '%Y-%m') = ? 
                            AND dr.request_status IN ('completed')
                        ");
                        $stmt->execute([$currentMonth]);
                        $monthlyIncome = $stmt->fetch()['monthly_income'];
                        
       
                        $stmt = $pdo->query("
                            SELECT SUM(dt.doc_price) AS weekly_income
                            FROM document_requests dr
                            JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                            WHERE dr.request_status = 'completed'
                            AND dr.date_requested >= DATE(NOW() - INTERVAL 7 DAY)
                        ");
                        $weeklyIncome = $stmt->fetch()['weekly_income'];
            
                        $stmt = $pdo->query("
                            SELECT COALESCE(SUM(dt.doc_price), 0) as daily_income 
                            FROM document_requests dr 
                            JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id 
                            WHERE DATE(dr.date_requested) = CURDATE()
                            AND dr.request_status IN ('completed')
                        ");
                        $dailyIncome = $stmt->fetch()['daily_income'];
                        
                        // Total revenue
                        $stmt = $pdo->query("
                           SELECT SUM(dt.doc_price) AS total_revenue
                          FROM document_requests dr
                          JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
                          WHERE dr.request_status = 'completed'
                        ");
                        $totalRevenue = $stmt->fetch()['total_revenue'];
                        ?>


<?php
//chart prediction
$monthlyData = [];
$monthlyLabels = [];
$stmt = $pdo->query("
  SELECT DATE_FORMAT(date_requested, '%b') AS month, SUM(dt.doc_price) AS total
  FROM document_requests dr
  JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
  WHERE dr.request_status IN ('printed','signed','completed')
  GROUP BY DATE_FORMAT(date_requested, '%Y-%m')
  ORDER BY DATE_FORMAT(date_requested, '%Y-%m') ASC
  LIMIT 6
");
while ($row = $stmt->fetch()) {
  $monthlyLabels[] = $row['month'];
  $monthlyData[] = $row['total'];
}

// Weekly: last 7 days
$weeklyData = [];
$weeklyLabels = [];
$stmt = $pdo->query("
  SELECT DATE_FORMAT(date_requested, '%a') AS day, SUM(dt.doc_price) AS total
  FROM document_requests dr
  JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
  WHERE dr.request_status IN ('printed','signed','completed')
  AND date_requested >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
  GROUP BY DATE(date_requested)
  ORDER BY DATE(date_requested)
");
while ($row = $stmt->fetch()) {
  $weeklyLabels[] = $row['day'];
  $weeklyData[] = $row['total'];
}

// Daily: hourly breakdown for today
$dailyData = [];
$dailyLabels = [];
$stmt = $pdo->query("
  SELECT DATE_FORMAT(date_requested, '%H:00') AS hour, SUM(dt.doc_price) AS total
  FROM document_requests dr
  JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
  WHERE DATE(dr.date_requested) = CURDATE()
  AND dr.request_status IN ('printed','signed','completed')
  GROUP BY HOUR(date_requested)
  ORDER BY HOUR(date_requested)
");
while ($row = $stmt->fetch()) {
  $dailyLabels[] = $row['hour'];
  $dailyData[] = $row['total'];
}
$totalData = $monthlyData; 
$totalLabels = $monthlyLabels;
?>

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
#revenueChart {
  width: 100% !important;
  height: 100% !important;
  min-height: 350px;
}
.chart-container {
  position: relative;
  width: 100%;
  height: 400px;
  max-height: 400px;
}
.chart-filter {
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
  border-radius: 14px;
  padding: 0.5rem !important; 
}
.chart-filter .card-body {
  padding: 0.75rem !important; 
}
.chart-filter:hover {
  transform: scale(1.02);
  box-shadow: 0 0 10px rgba(0,0,0,0.1);
}
.chart-filter.active {
  border: 2px solid #0d6efd;
}
.chart-filter p {
  font-size: 0.85rem; 
  margin-bottom: 0.25rem !important;
}
.chart-filter h3 {
  font-size: 1.4rem; 
  margin-bottom: 0.25rem !important;
}

.chart-filter small {
  font-size: 0.75rem; 
}
.chart-filter:hover {
  transform: scale(1.02);
  box-shadow: 0 0 8px rgba(0, 0, 0, 0.08);
}
.d-flex.flex-column.gap-3 {
  gap: 0.75rem !important;
}
.chart-container {
  height: 300px !important; 
  max-height: 300px !important;
}
</style>

<div class="row g-3">
  <div class="col-md-3">
    <div class="stat-card bg-green-gradient">
      <div class="stat-header">
        <span>Completed</span>
        <span class="stat-icon bg-white text-success">
          <i class="fa fa-level-up"></i>
        </span>
      </div>
      <div class="stat-body">
          <?php echo $stats['completed_documents']; ?>
      </div>
      <div class="stat-footer text-white">
        Ready for resident pickup
      </div>
    </div>
  </div>


  <div class="col-md-3">
     <div class="stat-card border border-dark bg-white">
      <div class="stat-header">
        <span>Pending Signature</span>
        <span class="stat-icon">
         <i class="fa fa-level-up"></i>
        </span>
      </div>
      <div class="stat-body text-black">
         <?php echo $stats['pending_signature']; ?>
      </div>
      <div class="stat-footer">
        Awaiting processing
      </div>
    </div>
  </div>

  <div class="col-md-3">
    <div class="stat-card border border-dark bg-white">
      <div class="stat-header">
        <span>Signed Document</span>
        <span class="stat-icon">
          <i class="fa fa-level-up"></i>
        </span>
      </div>
      <div class="stat-body text-black">
        <?php echo $stats['signed_documents']; ?>
      </div>
      <div class="stat-footer text-black">
        Officially approved and signed
      </div>
    </div>
  </div>


  <div class="col-md-3">
    <div class="stat-card border border-dark bg-white">
      <div class="stat-header">
        <span>My Requests</span>
        <span class="stat-icon">
          <i class="fa fa-level-up"></i>
        </span>
      </div>
      <div class="stat-body text-black">
        <?php echo $stats['my_requests']; ?>
      </div>
      <div class="stat-footer text-black">
          Total documents you requested
      </div>
    </div>
  </div>
</div>




<div class="row g-4 align-items-stretch">
  <!-- LEFT SIDE: Revenue Cards -->
  <div class="col-lg-4 col-md-5">
    <div class="d-flex flex-column gap-3">

      <!-- This Month -->
      <div class="card shadow-sm border-0 chart-filter active" data-filter="monthly">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <p class="text-muted fw-semibold mb-0">This Month</p>
            <h3 class="fw-bold text-warning mb-0">₱<?php echo number_format($monthlyIncome, 2); ?></h3>
          </div>
          <small class="text-muted"><?php echo date('F Y'); ?></small>
        </div>
      </div>

      <!-- This Week -->
      <div class="card shadow-sm border-0 chart-filter" data-filter="weekly">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <p class="text-muted fw-semibold mb-0">This Week</p>
            <h3 class="fw-bold text-info mb-0">₱<?php echo number_format($weeklyIncome, 2); ?></h3>
          </div>
          <small class="text-muted">Last 7 days</small>
        </div>
      </div>

      <!-- Today -->
      <div class="card shadow-sm border-0 chart-filter" data-filter="daily">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <p class="text-muted fw-semibold mb-0">Today</p>
            <h3 class="fw-bold text-success mb-0">₱<?php echo number_format($dailyIncome, 2); ?></h3>
          </div>
          <small class="text-muted"><?php echo date('M j, Y'); ?></small>
        </div>
      </div>

      <!-- Total Revenue -->
      <div class="card shadow-sm border-0 chart-filter" data-filter="total">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <p class="text-muted fw-semibold mb-0">Total Revenue</p>
            <h3 class="fw-bold text-primary mb-0">₱<?php echo number_format($totalRevenue, 2); ?></h3>
          </div>
          <small class="text-muted">All time</small>
        </div>
      </div>

    </div>
  </div>

  <!-- RIGHT SIDE: Chart -->
  <div class="col-lg-8 col-md-7">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="fw-semibold mb-0" id="chartTitle">Monthly Revenue Trend</h5>
          <span id="profitBadge" class="badge bg-success bg-opacity-10 text-success fw-semibold">
            +0% Overall Profit
          </span>
        </div>
        <div class="chart-container" style="position: relative; height: 400px;">
          <canvas id="revenueChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>

const chartDataSets = {
  monthly: <?php echo json_encode($monthlyData); ?>,
  weekly: <?php echo json_encode($weeklyData); ?>,
  daily: <?php echo json_encode($dailyData); ?>,
  total: <?php echo json_encode($totalData); ?>
};

const chartLabels = {
  monthly: <?php echo json_encode($monthlyLabels); ?>,
  weekly: <?php echo json_encode($weeklyLabels); ?>,
  daily: <?php echo json_encode($dailyLabels); ?>,
  total: <?php echo json_encode($totalLabels); ?>
};

const ctx = document.getElementById('revenueChart').getContext('2d');
let revenueChart = new Chart(ctx, {
  type: 'line',
  data: {
    labels: chartLabels.monthly,
    datasets: [{
      label: 'Revenue',
      data: chartDataSets.monthly,
      borderColor: '#0d6efd',
      backgroundColor: 'rgba(13,110,253,0.1)',
      fill: true,
      tension: 0.3
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true } }
  }
});

document.querySelectorAll('.chart-filter').forEach(card => {
  card.addEventListener('click', () => {
    document.querySelectorAll('.chart-filter').forEach(c => c.classList.remove('active'));
    card.classList.add('active');

    const filter = card.dataset.filter;

    // Update chart with new data
    revenueChart.data.labels = chartLabels[filter];
    revenueChart.data.datasets[0].data = chartDataSets[filter];
    revenueChart.update();

    document.getElementById('chartTitle').textContent =
      filter.charAt(0).toUpperCase() + filter.slice(1) + ' Revenue Trend';
  });
});


const profitBadge = document.getElementById('profitBadge');

document.querySelectorAll('.chart-filter').forEach(card => {
  card.addEventListener('click', () => {
    document.querySelectorAll('.chart-filter').forEach(c => c.classList.remove('active'));
    card.classList.add('active');

    const filter = card.dataset.filter;

    // Update chart
    revenueChart.data.labels = chartLabels[filter];
    revenueChart.data.datasets[0].data = chartDataSets[filter];
    revenueChart.update();

    // Update title
    document.getElementById('chartTitle').textContent =
      filter.charAt(0).toUpperCase() + filter.slice(1) + ' Revenue Trend';

    // === Calculate Overall Profit ===
    const data = chartDataSets[filter];
    let change = 0;
    if (data.length > 1) {
      const prev = data[data.length - 2];
      const curr = data[data.length - 1];
      if (prev !== 0) {
        change = ((curr - prev) / prev) * 100;
      }
    }

    const formattedChange = (change >= 0 ? '+' : '') + change.toFixed(1) + '%';
    profitBadge.textContent = formattedChange + ' Overall Profit';

    // Update badge color dynamically
    if (change >= 0) {
      profitBadge.classList.remove('bg-danger', 'text-danger');
      profitBadge.classList.add('bg-success', 'text-success', 'bg-opacity-10');
    } else {
      profitBadge.classList.remove('bg-success', 'text-success', 'bg-opacity-10');
      profitBadge.classList.add('bg-danger', 'text-danger', 'bg-opacity-10');
    }
  });
});
</script>
<?php include 'footer.php'; ?>
