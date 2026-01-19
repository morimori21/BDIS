<?php include 'header.php'; ?>
<link rel="stylesheet" href="path/to/font-awesome/css/font-awesome.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

<style>
.stat-card {
  border-radius: 16px;
  padding: 1rem;
  height: 100%;
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
  padding:0px
}

.stat-body {
  font-size: 2.5rem;
  font-weight: 700;
  padding:0;    
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
.bg-purple-gradient {
  background: linear-gradient(135deg, #6a1b9a, #8e24aa);
  color: #fff;
  border: none;
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

@media (max-width: 768px) {
  .stat-body { font-size: 1.5rem; }
  .stat-footer { font-size: 0.6rem; }
  .chart-container { height: 250px !important; max-height: 250px !important; }
  #monthContainer div { flex-direction: column; align-items: flex-start; gap: 3px; }
}

.stat-body { font-size: clamp(1.5rem, 5vw, 2.5rem); }
.stat-footer { font-size: clamp(.6rem, 2.5vw, .7rem); }

#monthContainer {
  overflow-x: auto;
  padding-bottom: 5px;
}
.analytics-btn {
  position: fixed;
  bottom: 20px;
  right: 20px;
  width: 50px;
  height: 50px;
  border-radius: 50%;
  z-index: 1000;
}

.card {
  transition: transform 0.2s ease;
}

.card:hover {
  transform: translateY(-2px);
}

.activity-bars .bar {
  transition: all 0.3s ease;
  cursor: pointer;
  flex: 1;
}

.activity-bars .bar:hover {
  opacity: 0.8;
  transform: scale(1.05);
}

.activity-bars {
  height: 80px !important; 
  gap: 8px !important; 
}

.activity-bars .bar .graph-bar {
  width: 20px !important; 
  min-height: 5px;
  border-radius: 4px 4px 0 0;
  margin: 0 auto;
  transition: height 0.5s ease;
}

.activity-bars .bar small {
  font-size: 0.65rem;
  margin-top: 4px;
  display: block;
}

.btn-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
}

.form-select-sm, .form-control-sm {
  font-size: 0.75rem;
}

.small {
  font-size: 0.7rem;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .modal-xl {
    margin: 0.5rem;
  }
  
  .analytics-btn {
    bottom: 20px;
    right: 20px;
    width: 50px;
    height: 50px;
    font-size: 1rem;
  }
  
  .chart-container {
    height: 150px !important;
  }
}
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.modal-content {
  animation: fadeIn 0.3s ease-in-out;
}
</style>

<?php

  $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

  // Verified Users
  $verifiedUsers = $pdo->query("
      SELECT COUNT(*)
  FROM users u
  LEFT JOIN account a ON u.user_id = a.user_id
  WHERE u.status = 'verified'
  ")->fetchColumn();

  // New Users This Week
  $newUsersThisWeek = $pdo->query("
      SELECT COUNT(*) 
      FROM users 
      WHERE date_registered >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
  ")->fetchColumn();


  // --- Comprehensive Statistics ---
  $stats = [];

  // Pending Verifications
  $stats['pending_verifications'] = $pdo->query("
        SELECT COUNT(*)
      FROM users
      WHERE status = 'pending'
  ")->fetchColumn();

  // Total Document Requests
  $stats['total_requests'] = $pdo->query("
      SELECT COUNT(*) 
      FROM document_requests
  ")->fetchColumn();

  // Completed Document Requests
  $stats['completed_requests'] = $pdo->query("
      SELECT COUNT(*) 
      FROM document_requests
      WHERE request_status = 'completed'
  ")->fetchColumn();

?>


<!-- MONTH AND YEARS COUNT DOCUMENT REQUEST         -->
<?php
  $selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

  // Initialize counts for all 12 months
  $monthlyCounts = array_fill(1, 12, 0);

  $stmt = $pdo->prepare("
      SELECT MONTH(date_requested) AS month, COUNT(*) AS count
      FROM document_requests
      WHERE request_status = 'completed' AND YEAR(date_requested) = :year
      GROUP BY MONTH(date_requested)
  ");
  $stmt->execute(['year' => $selectedYear]);

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $monthlyCounts[(int)$row['month']] = (int)$row['count'];
  }

  // First half: Jan - Jun
  $firstHalfMonths = [];
  $firstHalfCounts = [];
  for ($m = 1; $m <= 6; $m++) {
      $firstHalfMonths[] = date('M', mktime(0,0,0,$m,1));
      $firstHalfCounts[] = $monthlyCounts[$m];
  }

  // Second half: Jul - Dec
  $secondHalfMonths = [];
  $secondHalfCounts = [];
  for ($m = 7; $m <= 12; $m++) {
      $secondHalfMonths[] = date('M', mktime(0,0,0,$m,1));
      $secondHalfCounts[] = $monthlyCounts[$m];
  }
?>



<?php
  // --- TOTAL REVENUE ---
  $totalRevenue = $pdo->query("
      SELECT COALESCE(SUM(dt.doc_price), 0)
      FROM document_requests dr
      JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
      WHERE dr.request_status = 'completed'
  ")->fetchColumn();

  // --- MONTHLY REVENUE (CURRENT MONTH) ---
  $monthlyRevenue = $pdo->query("
      SELECT COALESCE(SUM(dt.doc_price),0)
      FROM document_requests dr
      JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
      WHERE dr.request_status = 'completed'
      AND MONTH(dr.date_requested) = MONTH(CURDATE())
      AND YEAR(dr.date_requested) = YEAR(CURDATE())
  ")->fetchColumn();

  // --- WEEKLY REVENUE (CURRENT WEEK) ---
  $weeklyRevenue = $pdo->query("
      SELECT COALESCE(SUM(dt.doc_price),0)
      FROM document_requests dr
      JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
      WHERE dr.request_status = 'completed'
      AND YEARWEEK(dr.date_requested,1) = YEARWEEK(CURDATE(),1)
  ")->fetchColumn();

  // --- PREVIOUS PERIODS FOR TRENDS ---

  // Total revenue trend (compared to all previous revenue)
  $prevTotalRevenue = $pdo->query("
      SELECT COALESCE(SUM(dt.doc_price),0)
      FROM document_requests dr
      JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
      WHERE dr.request_status = 'completed'
      AND dr.date_requested < CURDATE()
  ")->fetchColumn();

  $totalRevenueTrend = ($prevTotalRevenue > 0) ? (($totalRevenue - $prevTotalRevenue)/$prevTotalRevenue)*100 : 0;

  // Monthly trend (compare with last month)
  $lastMonth = date('Y-m', strtotime('-1 month'));
  $prevMonthRevenue = $pdo->prepare("
      SELECT COALESCE(SUM(dt.doc_price),0)
      FROM document_requests dr
      JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
      WHERE dr.request_status = 'completed'
      AND DATE_FORMAT(dr.date_requested, '%Y-%m') = ?
  ");
  $prevMonthRevenue->execute([$lastMonth]);
  $prevMonthRevenue = $prevMonthRevenue->fetchColumn();
  $monthlyTrend = ($prevMonthRevenue > 0) ? (($monthlyRevenue - $prevMonthRevenue)/$prevMonthRevenue)*100 : 0;

  // Weekly trend (compare with last week)
  $prevWeekRevenue = $pdo->query("
      SELECT COALESCE(SUM(dt.doc_price),0)
      FROM document_requests dr
      JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
      WHERE dr.request_status = 'completed'
      AND YEARWEEK(dr.date_requested,1) = YEARWEEK(CURDATE(),1)-1
  ")->fetchColumn();
  $weeklyTrend = ($prevWeekRevenue > 0) ? (($weeklyRevenue - $prevWeekRevenue)/$prevWeekRevenue)*100 : 0;
  ?>


<!-- NUMBERS ROUND UP FORMAT -->
  <?php
function formatCurrencyShort($amount) {
    if ($amount >= 1000000) {
        return '₱' . round($amount / 1000000, 1) . 'M';
    } elseif ($amount >= 1000) {
        return '₱' . round($amount / 1000, 1) . 'K';
    } else {
        return '₱' . $amount;
    }
}
?>


<?php
$monthlyData = [];
$monthlyLabels = [];
$stmt = $pdo->query("
    SELECT DATE_FORMAT(dr.date_requested, '%b') AS month, SUM(dt.doc_price) AS total
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    WHERE dr.request_status = 'completed'
    GROUP BY DATE_FORMAT(dr.date_requested, '%Y-%m')
    ORDER BY DATE_FORMAT(dr.date_requested, '%Y-%m') ASC
    LIMIT 6
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $monthlyLabels[] = $row['month'];
    $monthlyData[] = (float)$row['total'];
}

// Weekly data (last 7 days)
$weeklyData = [];
$weeklyLabels = [];
$stmt = $pdo->query("
    SELECT DATE_FORMAT(dr.date_requested, '%a') AS day, SUM(dt.doc_price) AS total
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    WHERE dr.request_status = 'completed'
    AND dr.date_requested >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(dr.date_requested)
    ORDER BY DATE(dr.date_requested)
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $weeklyLabels[] = $row['day'];
    $weeklyData[] = (float)$row['total'];
}

// Total historical revenue by month (all-time)
$totalData = [];
$totalLabels = [];
$stmt = $pdo->query("
    SELECT DATE_FORMAT(dr.date_requested, '%b %Y') AS month, SUM(dt.doc_price) AS total
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    WHERE dr.request_status = 'completed'
    GROUP BY DATE_FORMAT(dr.date_requested, '%Y-%m')
    ORDER BY DATE_FORMAT(dr.date_requested, '%Y-%m') ASC
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $totalLabels[] = $row['month'];
    $totalData[] = (float)$row['total'];
}
?>

<div class="container mt-3">
  <div class="row g-3">

    <!-- USER OVERVIEW BOX -->
      <div class="col-12 col-md-6 col-lg-4">
      <div class="stat-card border border-dark bg-white h-100">
        <div class="fw-bold">User Overview</div>

        <div class="mt-2">
          <div class="mb-3">
              <div class="stat-body"><?= $totalUsers ?></div>
            <div class="stat-footer">Total Users</div>
          </div>
          <div class="mb-3">
           <div class="stat-body"><?= $verifiedUsers ?></div>
            <div class="stat-footer">Verified Users</div>
          </div>
          <div>
           <div class="stat-body"><?= $newUsersThisWeek ?></div>
            <div class="stat-footer">New Resident, 7 days ago</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ACCOUNT & REQUEST OVERVIEW BOX -->
     <div class="col-12 col-md-6 col-lg-4">
      <div class="stat-card border border-dark bg-white h-100">
        <div class="fw-bold">Account & Requests</div>

        <div class="mt-2">
          <div class="mb-3">
             <div class="stat-body"><?= $stats['pending_verifications'] ?></div>
            <div class="stat-footer">Pending Verifications</div>
          </div>
          <div class="mb-3">
           <div class="stat-body"><?= $stats['total_requests'] - $stats['completed_requests'] ?></div>
            <div class="stat-footer">Pending Document Requests</div>
          </div>
          <div>
            <div class="stat-body"><?= $stats['total_requests'] ?></div>
            <div class="stat-footer">Total Document Requests</div>
          </div>
        </div>
      </div>
    </div>

    <!-- DOCUMENT REQUEST TREND-->
<div class="col-12 col-md-12 col-lg-4">
  <div class="stat-card border border-dark bg-white h-100">
    <div class="d-flex justify-content-between align-items-center">
      <span class="fw-bold">Completed Document Requests</span>
      <select id="yearFilter" class="form-select form-select-sm" style="width:120px;">
        <option selected><?= date('Y') ?></option>
        <option><?= date('Y')-1 ?></option>
      </select>
    </div>
    <div class="mt-3" id="monthContainer">
    </div>
    <div class="d-flex justify-content-end mt-2">
      <button id="prevBtn" class="btn btn-sm btn-outline-dark me-2" disabled>Prev</button>
      <button id="nextBtn" class="btn btn-sm btn-outline-dark">Next</button>
    </div>

  </div>
</div>

<div class="row g-3 mt-2">
    <!-- Revenue Column -->
  <div class="col-12 col-md-3 d-flex flex-column gap-2">

    <!-- Total Revenue -->
<div class="stat-card border border-dark bg-white"> 
        <small>Total Revenue</small>
        <h3 class="fw-bold mb-0"><?= formatCurrencyShort($totalRevenue) ?></h3>

        <?php if ($totalRevenueTrend > 0): ?>
            <span class="text-success small">
                ▲ <?= number_format($totalRevenueTrend, 2) ?>% this period
            </span>
        <?php elseif ($totalRevenueTrend < 0): ?>
            <span class="text-danger small">
                ▼ <?= number_format(abs($totalRevenueTrend), 2) ?>% this period
            </span>
        <?php else: ?>
            <span class="text-muted small">No change</span>
        <?php endif; ?>
    </div>

    <!-- Revenue This Month -->
   <div class="stat-card border border-dark bg-white">
        <small>Revenue this month</small>
        <h3 class="fw-bold mb-0"><?= formatCurrencyShort($monthlyRevenue) ?></h3>

        <?php if ($monthlyTrend > 0): ?>
            <span class="text-success small">
                ▲ <?= number_format($monthlyTrend, 2) ?>% from last month
            </span>
        <?php elseif ($monthlyTrend < 0): ?>
            <span class="text-danger small">
                ▼ <?= number_format(abs($monthlyTrend), 2) ?>% from last month
            </span>
        <?php else: ?>
            <span class="text-muted small">No change</span>
        <?php endif; ?>
    </div>

    <!-- Revenue This Week -->
 <div class="stat-card border border-dark bg-white">
        <small>Revenue this week</small>
        <h3 class="fw-bold mb-0"><?= formatCurrencyShort($weeklyRevenue) ?></h3>

        <?php if ($weeklyTrend > 0): ?>
            <span class="text-success small">
                ▲ <?= number_format($weeklyTrend, 2) ?>% from last week
            </span>
        <?php elseif ($weeklyTrend < 0): ?>
            <span class="text-danger small">
                ▼ <?= number_format(abs($weeklyTrend), 2) ?>% from last week
            </span>
        <?php else: ?>
            <span class="text-muted small">No change</span>
        <?php endif; ?>
    </div>

</div>
    <!-- Chart -->
  <div class="col-12 col-md-9">
        <div class="p-3 border bg-white" style="height:360px;">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 id="chartTitle" class="mb-0">Total Revenue Trend</h6>
           <?php
$totalRevenueChange = ($prevTotalRevenue > 0) ? (($totalRevenue - $prevTotalRevenue)/$prevTotalRevenue)*100 : 0;
$predictedNext = $totalRevenue * (1 + $totalRevenueChange/100);
?>
<span id="profitBadge" class="badge <?= $totalRevenueChange>=0 ? 'bg-success text-success bg-opacity-10' : 'bg-danger text-danger bg-opacity-10' ?>">
<?= ($totalRevenueChange>=0?'+':'').number_format($totalRevenueChange,1) ?>% Overall Profit | Next: <?= formatCurrencyShort($predictedNext) ?>
</span>
    </div>
    <canvas id="revenueChart"></canvas>
</div>
    </div>
</div>
  </div>
</div>

<!-- MODAL -->
<button class="btn btn-dark shadow-lg analytics-btn" data-bs-toggle="modal" data-bs-target="#analyticsModal" title="Analytics Report">
    <i class="fa fa-bar-chart"></i>
</button>

<div class="modal fade" id="analyticsModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white py-2">
        <h6 class="modal-title mb-0"><i class="fa fa-dashboard me-2"></i>Activity Dashboard</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-3">
        <!-- Compact Filter Controls -->
        <div class="card shadow-sm border-0 mb-3">
          <div class="card-body p-3">
            <div class="row g-2 align-items-center">
              <div class="col-md-3">
                <label class="form-label small fw-bold mb-1 text-muted">ROLE</label>
                <select class="form-select form-select-sm" id="roleFilter">
                  <option value="all">All Roles</option>
                  <option value="RESIDENT">Resident</option>
                  <option value="CAPTAIN">Captain</option>
                  <option value="SECRETARY">Secretary</option>
                  <option value="ADMIN">Admin</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-bold mb-1 text-muted">DATE</label>
                <input type="date" class="form-control form-control-sm" id="dateFilter" value="<?php echo date('Y-m-d'); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-bold mb-1 text-muted">USER SEARCH</label>
                <div class="input-group input-group-sm">
                  <input type="text" class="form-control" id="userSearch" placeholder="Search user..." style="display: none;">
                  <button class="btn btn-outline-secondary" type="button" id="clearSearch" style="display: none;">
                    <i class="fa fa-times"></i>
                  </button>
                </div>
                <div class="mt-1" id="searchResults" style="max-height: 120px; overflow-y: auto; display: none;"></div>
              </div>
              <div class="col-md-2 text-end">
                <button class="btn btn-success btn-sm w-100" id="exportExcel">
                  <i class="fa fa-download me-1"></i>Export
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Compact Stats Grid -->
        <div class="row g-2 mb-3">
          <!-- Total Activities -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-primary bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-primary mb-1">
                  <i class="fa fa-history fa-sm"></i>
                </div>
                <h5 class="text-primary mb-0" id="totalActivities">0</h5>
                <small class="text-muted">Total</small>
              </div>
            </div>
          </div>

          <!-- Login Count -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-success bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-success mb-1">
                  <i class="fa fa-sign-in fa-sm"></i>
                </div>
                <h5 class="text-success mb-0" id="loginCount">0</h5>
                <small class="text-muted">Logins</small>
              </div>
            </div>
          </div>

          <!-- Request Count -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-info bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-info mb-1">
                  <i class="fa fa-file-text fa-sm"></i>
                </div>
                <h5 class="text-info mb-0" id="requestCount">0</h5>
                <small class="text-muted">Requests</small>
              </div>
            </div>
          </div>

          <!-- Role Changes -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-warning bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-warning mb-1">
                  <i class="fa fa-user-cog fa-sm"></i>
                </div>
                <h5 class="text-warning mb-0" id="roleChangeCount">0</h5>
                <small class="text-muted">Roles</small>
              </div>
            </div>
          </div>

          <!-- Ticket Appeals -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-danger bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-danger mb-1">
                  <i class="fa fa-ticket fa-sm"></i>
                </div>
                <h5 class="text-danger mb-0" id="ticketAppealCount">0</h5>
                <small class="text-muted">Tickets</small>
              </div>
            </div>
          </div>

          <!-- System Changes -->
          <div class="col-4 col-sm-2">
            <div class="card shadow-sm border-0 bg-dark bg-opacity-10">
              <div class="card-body p-2 text-center">
                <div class="text-dark mb-1">
                  <i class="fa fa-cog fa-sm"></i>
                </div>
                <h5 class="text-dark mb-0" id="systemChangeCount">0</h5>
                <small class="text-muted">System</small>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Action Filters -->
        <div class="card shadow-sm border-0 mb-3">
          <div class="card-body p-2">
            <label class="form-label small fw-bold mb-2 text-muted">QUICK FILTERS</label>
            <div class="d-flex flex-wrap gap-1">
              <button class="btn btn-outline-primary btn-sm active action-filter" data-action="all">
                <i class="fa fa-th me-1"></i>All
              </button>
              <button class="btn btn-outline-success btn-sm action-filter" data-action="login">
                <i class="fa fa-sign-in me-1"></i>Logins
              </button>
              <button class="btn btn-outline-info btn-sm action-filter" data-action="request">
                <i class="fa fa-file-text me-1"></i>Requests
              </button>
              <button class="btn btn-outline-warning btn-sm action-filter" data-action="role">
                <i class="fa fa-user-cog me-1"></i>Roles
              </button>
              <button class="btn btn-outline-danger btn-sm action-filter" data-action="ticket">
                <i class="fa fa-ticket me-1"></i>Tickets
              </button>
              <button class="btn btn-outline-dark btn-sm action-filter" data-action="system">
                <i class="fa fa-cog me-1"></i>System
              </button>
            </div>
          </div>
        </div>

        <!-- Mini Activity Chart -->
        <div class="card shadow-sm border-0">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <label class="form-label small fw-bold mb-0 text-muted">ACTIVITY OVERVIEW</label>
              <span class="badge bg-light text-dark small" id="lastUpdate">Just now</span>
            </div>
            <div class="activity-bars d-flex align-items-end gap-1" style="height: 60px;" id="activityChart">
              <!-- Activity bars will be generated here -->
              <div class="text-center small text-muted">
                <div class="bg-primary bg-opacity-25 rounded-top mx-auto" style="width: 12px; height: 20px;"></div>
                <small>Loading...</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer py-2">
        <small class="text-muted me-auto" id="dataSummary">0 activities today</small>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>

//DOCUMENT REQUEST TREND
const currentMonth = new Date().getMonth() + 1; // 1-12
let currentGroup = (currentMonth >= 1 && currentMonth <= 6) ? 'first' : 'second';

const firstMonths = <?= json_encode($firstHalfMonths) ?>;
const firstCounts = <?= json_encode($firstHalfCounts) ?>;

const secondMonths = <?= json_encode($secondHalfMonths) ?>;
const secondCounts = <?= json_encode($secondHalfCounts) ?>;

const renderMonths = () => {
    const container = document.getElementById("monthContainer");
    container.innerHTML = "";

    let months, counts;
    if(currentGroup === 'first'){
        months = firstMonths;
        counts = firstCounts;
    } else {
        months = secondMonths;
        counts = secondCounts;
    }

    const maxCount = Math.max(...counts) || 1;

    for(let i=0;i<6;i++){
        let width = (counts[i]/maxCount)*100;
        container.innerHTML += `
        <div class="d-flex align-items-center mb-2">
            <span style="width:45px">${months[i]}</span>
            <div class="flex-grow-1" style="height:8px; background:#eaeaea; margin:0 8px">
                <div style="width:${width}%; height:8px; background:black;"></div>
            </div>
            <span style="width:30px">${counts[i]}</span>
        </div>
        `;
    }

    // Update buttons
    document.getElementById("prevBtn").disabled = currentGroup==='first';
    document.getElementById("nextBtn").disabled = currentGroup==='second';
};

document.getElementById("prevBtn").onclick = () => { currentGroup='first'; renderMonths(); };
document.getElementById("nextBtn").onclick = () => { currentGroup='second'; renderMonths(); };

renderMonths();

// YEAR FILTER — AJAX version
document.getElementById("yearFilter").addEventListener("change", function() {
    const year = this.value;

    fetch(`get_monthly_counts.php?year=${year}`)
        .then(res => res.json())
        .then(data => {
            firstMonths.splice(0, firstMonths.length, ...data.firstMonths);
            firstCounts.splice(0, firstCounts.length, ...data.firstCounts);
            secondMonths.splice(0, secondMonths.length, ...data.secondMonths);
            secondCounts.splice(0, secondCounts.length, ...data.secondCounts);

            // Set current group based on current month
            const currentMonth = new Date().getMonth() + 1;
            currentGroup = (currentMonth >= 1 && currentMonth <= 6) ? 'first' : 'second';

            renderMonths();
        })
        .catch(err => console.error(err));
});
//tool tips + ROUND UP NUMBER
function formatCurrencyShort(amount) {
  if(amount >= 1000000) return '₱' + (amount/1000000).toFixed(1) + 'M';
  if(amount >= 1000) return '₱' + (amount/1000).toFixed(1) + 'K';
  return '₱' + amount;
}

// --- Short currency formatter ---
function formatCurrencyShort(amount) {
    if(amount >= 1000000) return '₱' + (amount/1000000).toFixed(1) + 'M';
    if(amount >= 1000) return '₱' + (amount/1000).toFixed(1) + 'K';
    return '₱' + amount;
}

// --- Chart data from PHP ---
const chartDataSets = {
    total: <?php echo json_encode($totalData); ?>,
    monthly: <?php echo json_encode($monthlyData); ?>,
    weekly: <?php echo json_encode($weeklyData); ?>
};

const chartLabels = {
    total: <?php echo json_encode($totalLabels); ?>,
    monthly: <?php echo json_encode($monthlyLabels); ?>,
    weekly: <?php echo json_encode($weeklyLabels); ?>
};

// Initialize chart
const ctx = document.getElementById('revenueChart').getContext('2d');
let revenueChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: chartLabels.total,
        datasets: [{
            label: 'Revenue',
            data: chartDataSets.total,
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13,110,253,0.1)',
            fill: true,
            tension: 0.3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return formatCurrencyShort(context.raw);
                    }
                }
            }
        },
        scales: { y: { beginAtZero: true } }
    }
});

// PREDICTION
const cards = document.querySelectorAll('.col-md-3 > div'); 
const chartTitle = document.getElementById('chartTitle');
const profitBadge = document.getElementById('profitBadge');

cards.forEach(card => {
    card.style.cursor = 'pointer';
    card.addEventListener('click', () => {
       updateRevenueChart(card);
        let filter = '';
        const titleText = card.querySelector('small').textContent.toLowerCase();
        if(titleText.includes('total')) filter = 'total';
        else if(titleText.includes('month')) filter = 'monthly';
        else if(titleText.includes('week')) filter = 'weekly';

        if(!filter) return;

        // Update chart
        revenueChart.data.labels = chartLabels[filter];
        revenueChart.data.datasets[0].data = chartDataSets[filter];
        revenueChart.update();

        // Update chart title
        chartTitle.textContent = card.querySelector('small').textContent + ' Revenue Trend';

        // --- Calculate last change and prediction ---
        const data = chartDataSets[filter];
        let change = 0;
        let prediction = 0;

        if(data.length > 1){
            const prev = data[data.length-2];
            const curr = data[data.length-1];
            if(prev !== 0) change = ((curr-prev)/prev)*100;

            // Simple prediction: next period = current + change
            prediction = curr * (1 + change/100);
        }

        // Update profit/prediction badge
        profitBadge.textContent = `${change>=0?'+':''}${change.toFixed(1)}% Overall Profit | Next: ${formatCurrencyShort(prediction)}`;

        if(change>=0){
            profitBadge.classList.remove('bg-danger','text-danger','bg-opacity-10');
            profitBadge.classList.add('bg-success','text-success','bg-opacity-10');
        } else {
            profitBadge.classList.remove('bg-success','text-success','bg-opacity-10');
            profitBadge.classList.add('bg-danger','text-danger','bg-opacity-10');
        }

        // Highlight active card
        cards.forEach(c => c.classList.remove('active'));
        card.classList.add('active');
    });
});

function updateRevenueChart(filterCard) {
    let filter = '';
    const titleText = filterCard.querySelector('small').textContent.toLowerCase();
    if(titleText.includes('total')) filter = 'total';
    else if(titleText.includes('month')) filter = 'monthly';
    else if(titleText.includes('week')) filter = 'weekly';

    if(!filter) return;

    // Update chart
    revenueChart.data.labels = chartLabels[filter];
    revenueChart.data.datasets[0].data = chartDataSets[filter];
    revenueChart.update();

    // Update chart title
    chartTitle.textContent = filterCard.querySelector('small').textContent + ' Revenue Trend';

    // --- Calculate last change and prediction ---
    const data = chartDataSets[filter];
    let change = 0;
    let prediction = 0;
    if(data.length > 1){
        const prev = data[data.length-2];
        const curr = data[data.length-1];
        if(prev !== 0) change = ((curr-prev)/prev)*100;
        prediction = curr * (1 + change/100);
    }

    // Update profit/prediction badge
    profitBadge.textContent = `${change>=0?'+':''}${change.toFixed(1)}% Overall Profit | Next: ${formatCurrencyShort(prediction)}`;

    if(change>=0){
        profitBadge.classList.remove('bg-danger','text-danger','bg-opacity-10');
        profitBadge.classList.add('bg-success','text-success','bg-opacity-10');
    } else {
        profitBadge.classList.remove('bg-success','text-success','bg-opacity-10');
        profitBadge.classList.add('bg-danger','text-danger','bg-opacity-10');
    }

    // Highlight active card
    cards.forEach(c => c.classList.remove('active'));
    filterCard.classList.add('active');
}

//modal
document.addEventListener('DOMContentLoaded', function() {
    let currentActionFilter = 'all';
    let selectedUserId = null;
    let activityData = [];

    // Search Users
    function searchUsers(query, role) {
        if (query.length < 2) {
            document.getElementById('searchResults').style.display = 'none';
            return;
        }

        fetch(`/Project_A2/assets/api/search_users.php?q=${encodeURIComponent(query)}&role=${role}`)
            .then(response => response.json())
            .then(users => {
                const resultsContainer = document.getElementById('searchResults');
                resultsContainer.innerHTML = '';
                
                if (users.length === 0) {
                    resultsContainer.innerHTML = '<div class="text-center text-muted p-1 small">No users found</div>';
                    resultsContainer.style.display = 'block';
                    return;
                }

                users.forEach(user => {
                    const userItem = document.createElement('div');
                    userItem.className = 'user-item p-1 border-bottom cursor-pointer small';
                    userItem.style.cursor = 'pointer';
                    userItem.innerHTML = `
                        <div class="fw-semibold">${user.name}</div>
                        <small class="text-muted">${user.role}</small>
                    `;
                    
                    userItem.addEventListener('click', function() {
                        selectedUserId = user.user_id;
                        document.getElementById('userSearch').value = user.name;
                        resultsContainer.style.display = 'none';
                        loadActivityData();
                    });
                    
                    resultsContainer.appendChild(userItem);
                });
                
                resultsContainer.style.display = 'block';
            })
            .catch(error => {
                console.error('Error searching users:', error);
            });
    }

    // Load Activity Data
    function loadActivityData() {
        const role = document.getElementById('roleFilter').value;
        const date = document.getElementById('dateFilter').value;

        // Show loading state
        document.querySelectorAll('[id$="Count"]').forEach(el => {
            el.textContent = '...';
        });

        const params = new URLSearchParams({
            role: role,
            user_id: selectedUserId || 'all',
            date: date,
            action_type: currentActionFilter
        });

        fetch(`/Project_A2/assets/api/get_activity_data.php?${params}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    activityData = data.activities || [];
                    
                    // Update stats
                    document.getElementById('totalActivities').textContent = data.stats.totalActivities;
                    document.getElementById('loginCount').textContent = data.stats.loginCount;
                    document.getElementById('requestCount').textContent = data.stats.requestCount;
                    document.getElementById('roleChangeCount').textContent = data.stats.roleChangeCount;
                    document.getElementById('ticketAppealCount').textContent = data.stats.ticketAppealCount;
                    document.getElementById('systemChangeCount').textContent = data.stats.systemChangeCount;
                    
                    // Update summary
                    document.getElementById('dataSummary').textContent = 
                        `${data.stats.totalActivities} activities • ${date}`;
                    document.getElementById('lastUpdate').textContent = 'Just now';
                    
                    // Update chart
                    updateActivityChart(data.stats);
                } else {
                    throw new Error(data.error);
                }
            })
            .catch(error => {
                console.error('Error loading activity data:', error);
                // Set default values on error
                document.querySelectorAll('[id$="Count"]').forEach(el => {
                    el.textContent = '0';
                });
            });
    }

    // Update Activity Chart
    function updateActivityChart(stats) {
        const chartContainer = document.getElementById('activityChart');
        const total = stats.totalActivities || 1;
        
        const chartData = [
            { label: 'Logins', value: stats.loginCount, color: 'success' },
            { label: 'Requests', value: stats.requestCount, color: 'info' },
            { label: 'Roles', value: stats.roleChangeCount, color: 'warning' },
            { label: 'Tickets', value: stats.ticketAppealCount, color: 'danger' },
            { label: 'System', value: stats.systemChangeCount, color: 'dark' }
        ];

        chartContainer.innerHTML = '';
        
        chartData.forEach(item => {
            const height = total > 0 ? Math.max((item.value / total) * 50, 5) : 5;
            const bar = document.createElement('div');
            bar.className = 'bar text-center';
            bar.innerHTML = `
                <div class="bg-${item.color} bg-opacity-75 rounded-top mx-auto" 
                     style="width: 10px; height: ${height}px;" 
                     title="${item.label}: ${item.value}">
                </div>
                <small class="text-muted d-block mt-1">${item.value}</small>
            `;
            chartContainer.appendChild(bar);
        });
    }

    // Action Filter Button Handler
    function setupActionFilters() {
        const filterButtons = document.querySelectorAll('.action-filter');
        
        filterButtons.forEach(button => {
            button.addEventListener('click', function() {
                // Remove active class from all buttons
                filterButtons.forEach(btn => btn.classList.remove('active', 'btn-primary'));
                filterButtons.forEach(btn => btn.classList.add('btn-outline-primary'));
                
                // Add active class to clicked button
                this.classList.remove('btn-outline-primary');
                this.classList.add('active', 'btn-primary');
                
                // Update current filter
                currentActionFilter = this.getAttribute('data-action');
                
                // Reload data with new filter
                loadActivityData();
            });
        });
    }

    // Toggle search based on role
    function toggleSearch() {
        const role = document.getElementById('roleFilter').value;
        const searchInput = document.getElementById('userSearch');
        const clearBtn = document.getElementById('clearSearch');
        
        if (role !== 'all') {
            searchInput.style.display = 'block';
            clearBtn.style.display = 'block';
        } else {
            searchInput.style.display = 'none';
            clearBtn.style.display = 'none';
            selectedUserId = null;
            searchInput.value = '';
            document.getElementById('searchResults').style.display = 'none';
        }
    }

    // Initialize modal when opened
    document.getElementById('analyticsModal').addEventListener('show.bs.modal', function () {
        setupActionFilters();
        toggleSearch();
        loadActivityData();
    });

    // Event listeners for filters
    document.getElementById('roleFilter').addEventListener('change', function() {
        toggleSearch();
        loadActivityData();
    });

    document.getElementById('dateFilter').addEventListener('change', loadActivityData);

    // User search functionality
    document.getElementById('userSearch').addEventListener('input', function() {
        const role = document.getElementById('roleFilter').value;
        searchUsers(this.value, role);
    });

    document.getElementById('clearSearch').addEventListener('click', function() {
        document.getElementById('userSearch').value = '';
        document.getElementById('searchResults').style.display = 'none';
        selectedUserId = null;
        loadActivityData();
    });

    // Export functionality
    document.getElementById('exportExcel').addEventListener('click', function() {
        const role = document.getElementById('roleFilter').value;
        const date = document.getElementById('dateFilter').value;

        const originalText = this.innerHTML;
        this.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Exporting...';
        this.disabled = true;
        
        const exportUrl = `/Project_A2/assets/api/export_activity_excel.php?role=${role}&user_id=${selectedUserId || 'all'}&date=${date}&action_type=${currentActionFilter}`;
        
        // Create a temporary iframe for download
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.src = exportUrl;
        document.body.appendChild(iframe);
        
        // Remove iframe after download
        setTimeout(() => {
            document.body.removeChild(iframe);
            this.innerHTML = originalText;
            this.disabled = false;
        }, 2000);
    });
});
</script>

<?php include 'footer.php'; ?>
