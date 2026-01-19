<?php include 'header.php'; ?>
<link rel="stylesheet" href="path/to/font-awesome/css/font-awesome.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<link rel="stylesheet" href="global.css">


<?php
// TOTAL USERS
$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

// REJECTED USERS
$rejected_users = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'rejected'")->fetchColumn();

// VERIFIED USERS
$verified_users = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'verified'")->fetchColumn();

// PENDING USERS
$pending_users = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
?>

<?php
// TOTAL REQUESTS
$total_requests = $pdo->query("SELECT COUNT(*) FROM document_requests")->fetchColumn();

// PENDING
$pending_requests = $pdo->query("
    SELECT COUNT(*) 
    FROM document_requests 
    WHERE request_status = 'pending'
")->fetchColumn();

// IN PROGRESS
$inprogress_requests = $pdo->query("
    SELECT COUNT(*) 
    FROM document_requests 
    WHERE request_status = 'in-progress'
")->fetchColumn();

// READY (printed + signed)
$ready_requests = $pdo->query("
    SELECT COUNT(*) 
    FROM document_requests 
    WHERE request_status IN ('printed', 'signed')
")->fetchColumn();
?>


<?php
// Total Revenue
$stmt = $pdo->query("
    SELECT SUM(dt.doc_price) AS total_revenue
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    WHERE dr.request_status = 'completed'
");
$totalRevenue = $stmt->fetchColumn() ?? 0;

// Weekly Revenue
$stmt = $pdo->query("
    SELECT SUM(dt.doc_price) AS weekly_revenue
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    WHERE dr.request_status = 'completed'
    AND dr.date_requested >= DATE(NOW() - INTERVAL 7 DAY)
");
$weeklyRevenue = $stmt->fetchColumn() ?? 0;

// Monthly Revenue
$stmt = $pdo->query("
    SELECT SUM(dt.doc_price) AS monthly_revenue
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    WHERE dr.request_status = 'completed'
    AND MONTH(dr.date_requested) = MONTH(NOW())
    AND YEAR(dr.date_requested) = YEAR(NOW())
");
$monthlyRevenue = $stmt->fetchColumn() ?? 0;
?>


<?php
// Total Tickets
$totalTickets = $pdo->query("
    SELECT COUNT(*) FROM support_tickets
")->fetchColumn();

// Closed
$closedTickets = $pdo->query("
    SELECT COUNT(*) FROM support_tickets
    WHERE ticket_status = 'closed'
")->fetchColumn();

// Open
$openTickets = $pdo->query("
    SELECT COUNT(*) FROM support_tickets
    WHERE ticket_status = 'open'
")->fetchColumn();

// In Progress
$inProgressTickets = $pdo->query("
    SELECT COUNT(*) FROM support_tickets
    WHERE ticket_status = 'in-progress'
")->fetchColumn();

// Resolved
$resolvedTickets = $pdo->query("
    SELECT COUNT(*) FROM support_tickets
    WHERE ticket_status = 'resolved'
")->fetchColumn();


?>

<!-- Request Trend -->
<?php
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

<?php
// Revenue Predictions
$nextMonthPrediction = $monthlyRevenue * 1.08; // 8% growth prediction
$nextWeekPrediction = $weeklyRevenue * 1.15;   // 15% growth prediction

// Calculate overall growth percentage
$lastMonthRevenue = $pdo->query("
    SELECT SUM(dt.doc_price) AS last_month_revenue
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    WHERE dr.request_status = 'completed'
    AND MONTH(dr.date_requested) = MONTH(NOW() - INTERVAL 1 MONTH)
    AND YEAR(dr.date_requested) = YEAR(NOW() - INTERVAL 1 MONTH)
")->fetchColumn() ?? 0;

$overallGrowth = $lastMonthRevenue > 0 ? 
    (($monthlyRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 : 0;
?>

 <?php
// Improved function to calculate monthly growth with negative detection
function calculateMonthlyGrowth($pdo) {
    // Current month revenue
    $currentMonth = $pdo->query("
        SELECT SUM(dt.doc_price) AS monthly_revenue
        FROM document_requests dr
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
        WHERE dr.request_status = 'completed'
        AND MONTH(dr.date_requested) = MONTH(NOW())
        AND YEAR(dr.date_requested) = YEAR(NOW())
    ")->fetchColumn() ?? 0;

    // Previous month revenue
    $previousMonth = $pdo->query("
        SELECT SUM(dt.doc_price) AS monthly_revenue
        FROM document_requests dr
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
        WHERE dr.request_status = 'completed'
        AND MONTH(dr.date_requested) = MONTH(NOW() - INTERVAL 1 MONTH)
        AND YEAR(dr.date_requested) = YEAR(NOW() - INTERVAL 1 MONTH)
    ")->fetchColumn() ?? 0;

    if ($previousMonth > 0) {
        $growth = (($currentMonth - $previousMonth) / $previousMonth) * 100;
        return number_format($growth, 1);
    }
    return $currentMonth > 0 ? '100.0' : '0.0';
}

// Improved function to calculate weekly growth with negative detection
function calculateWeeklyGrowth($pdo) {
    // Current week revenue
    $currentWeek = $pdo->query("
        SELECT SUM(dt.doc_price) AS weekly_revenue
        FROM document_requests dr
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
        WHERE dr.request_status = 'completed'
        AND dr.date_requested >= DATE(NOW() - INTERVAL 7 DAY)
    ")->fetchColumn() ?? 0;

    // Previous week revenue
    $previousWeek = $pdo->query("
        SELECT SUM(dt.doc_price) AS weekly_revenue
        FROM document_requests dr
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
        WHERE dr.request_status = 'completed'
        AND dr.date_requested >= DATE(NOW() - INTERVAL 14 DAY)
        AND dr.date_requested < DATE(NOW() - INTERVAL 7 DAY)
    ")->fetchColumn() ?? 0;

    if ($previousWeek > 0) {
        $growth = (($currentWeek - $previousWeek) / $previousWeek) * 100;
        return number_format($growth, 1);
    }
    return $currentWeek > 0 ? '100.0' : '0.0';
}

// New function to get monthly trend class (positive/negative/neutral)
function getMonthlyTrendClass($pdo) {
    $growth = calculateMonthlyGrowth($pdo);
    if ($growth > 0) return 'positive';
    if ($growth < 0) return 'negative';
    return 'neutral';
}

// New function to get weekly trend class (positive/negative/neutral)
function getWeeklyTrendClass($pdo) {
    $growth = calculateWeeklyGrowth($pdo);
    if ($growth > 0) return 'positive';
    if ($growth < 0) return 'negative';
    return 'neutral';
}

// New function to get monthly trend arrow direction
function getMonthlyTrendArrow($pdo) {
    $growth = calculateMonthlyGrowth($pdo);
    if ($growth > 0) return 'up';
    if ($growth < 0) return 'down';
    return 'right';
}

// New function to get weekly trend arrow direction
function getWeeklyTrendArrow($pdo) {
    $growth = calculateWeeklyGrowth($pdo);
    if ($growth > 0) return 'up';
    if ($growth < 0) return 'down';
    return 'right';
}

// New function to get monthly growth display with proper sign
function getMonthlyGrowthDisplay($pdo) {
    $growth = calculateMonthlyGrowth($pdo);
    if ($growth > 0) return '+'.$growth.'%';
    if ($growth < 0) return $growth.'%';
    return '0%';
}

// New function to get weekly growth display with proper sign
function getWeeklyGrowthDisplay($pdo) {
    $growth = calculateWeeklyGrowth($pdo);
    if ($growth > 0) return '+'.$growth.'%';
    if ($growth < 0) return $growth.'%';
    return '0%';
}

// Calculate predictions based on actual growth
$monthlyGrowth = calculateMonthlyGrowth($pdo);
$weeklyGrowth = calculateWeeklyGrowth($pdo);
?>


<?php
// Generate weekly data for the current week
$weeklyData = [];
$weeklyLabels = [];

// Get current week days (Monday to Sunday)
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));

$currentDate = $monday;
$daysOfWeek = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

for ($i = 0; $i < 7; $i++) {
    $stmt = $pdo->prepare("
        SELECT SUM(dt.doc_price) AS daily_revenue
        FROM document_requests dr
        JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
        WHERE dr.request_status IN ('printed','signed','completed')
        AND DATE(dr.date_requested) = ?
    ");
    $stmt->execute([$currentDate]);
    $dailyRevenue = $stmt->fetchColumn() ?? 0;
    
    $weeklyData[] = $dailyRevenue;
    $weeklyLabels[] = $daysOfWeek[$i];
    
    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
}
?>

<style>
.dashboard-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  grid-template-rows: 1fr 1fr;
  gap: 0.5rem;
  padding: 0;
  height: calc(100vh - 120px);
  min-height: 500px;
  margin-top: 0.25rem;
}
.stat-card {
  border-radius: 12px;
  padding: 1rem;
  height: 100%;
  display: flex;
  flex-direction: column;
  transition: all 0.2s ease-in-out;
  color: #000;
  background: #fff;
  border: 1px solid #e8e8e8;
  min-height: 170px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}

.stat-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid #e9ecef;
}

.header-content {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 600;
  font-size: 1rem;
  color: #2c3e50;
}

.header-content i {
  font-size: 1.1rem;
  color: #4dabf7;
}

.trend-indicator, .completion-badge {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  font-size: 0.75rem;
  color: #28a745;
  background: #f8f9fa;
  padding: 0.25rem 0.5rem;
  border-radius: 12px;
  font-weight: 500;
  border: 1px solid #e9ecef;
}

/* ===== USER OVERVIEW STYLES ===== */
.user-overview-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  height: 100%;
  flex: 1;
  min-height: 0;
}

.total-user-container {
  background: linear-gradient(135deg, #f8f9fa, #e9ecef);
  border-radius: 12px;
  padding: 1.5rem 1rem;
  text-align: center;
  border: 1px solid #dee2e6;
  transition: all 0.3s ease;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  height: 100%;
  min-height: 0;
  position: relative;
  overflow: hidden;
}

.total-user-container::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: linear-gradient(90deg, #4dabf7, #339af0);
}

.total-user-container:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
  background: linear-gradient(135deg, #e9ecef, #dee2e6);
}

.total-user-container .metric-value {
  color: #2c3e50;
  font-size: clamp(2.5rem, 5vw, 4rem);
  font-weight: 800;
  margin-bottom: 0.5rem;
  line-height: 1;
  text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.total-user-container .metric-label {
  color: #495057;
  font-size: clamp(0.9rem, 2vw, 1.1rem);
  font-weight: 600;
  letter-spacing: 0.5px;
  margin-bottom: 0.25rem;
}

.total-user-container .metric-context {
  color: #6c757d;
  font-size: 0.75rem;
  font-weight: 400;
}

.user-status-breakdown {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  height: 100%;
  min-height: 0;
}

.status-item {
  background: #fff;
  border-radius: 10px;
  padding: 1rem;
  border: 1px solid #e9ecef;
  transition: all 0.2s ease;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-height: 0;
  position: relative;
}

.status-item:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
  border-color: #4dabf7;
}

.status-indicator {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  flex-shrink: 0;
}

.status-item.verified .status-indicator {
  background: #51cf66;
  box-shadow: 0 0 0 3px rgba(81, 207, 102, 0.2);
}

.status-item.pending .status-indicator {
  background: #ffd43b;
  box-shadow: 0 0 0 3px rgba(255, 212, 59, 0.2);
}

.status-item.rejected .status-indicator {
  background: #ff8787;
  box-shadow: 0 0 0 3px rgba(255, 135, 135, 0.2);
}

.status-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.status-content .metric-value {
  font-size: 1.4rem;
  font-weight: 700;
  color: #2c3e50;
  line-height: 1;
}

.status-content .metric-label {
  font-size: 0.85rem;
  color: #6c757d;
  font-weight: 500;
}

.status-percentage {
  font-size: 0.8rem;
  font-weight: 600;
  color: #495057;
  background: #f8f9fa;
  padding: 0.25rem 0.5rem;
  border-radius: 8px;
  min-width: 50px;
  text-align: center;
}
.request-overview-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  height: 100%;
  flex: 1;
  min-height: 0;
}

.total-request-main {
  background: linear-gradient(135deg, #f8f9fa, #e9ecef);
  border-radius: 12px;
  padding: 1.5rem 1rem;
  text-align: center;
  border: 1px solid #dee2e6;
  transition: all 0.3s ease;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  position: relative;
  overflow: hidden;
}

.total-request-main::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: linear-gradient(90deg, #74b9ff, #0984e3);
}

.total-request-main:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
  background: linear-gradient(135deg, #e9ecef, #dee2e6);
}

.total-request-main .metric-value {
  color: #2c3e50;
  font-size: clamp(2.5rem, 5vw, 3.5rem);
  font-weight: 800;
  margin-bottom: 0.5rem;
  line-height: 1;
}

.total-request-main .metric-label {
  color: #495057;
  font-size: clamp(0.9rem, 2vw, 1.1rem);
  font-weight: 600;
  letter-spacing: 0.5px;
  margin-bottom: 0.25rem;
}

.total-request-main .metric-context {
  color: #6c757d;
  font-size: 0.75rem;
  font-weight: 400;
}

.status-breakdown {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  height: 100%;
  min-height: 0;
}

.status-indicator.pending {
  background: #6c757d;
  box-shadow: 0 0 0 3px rgba(108, 117, 125, 0.2);
}

.status-indicator.in-progress {
  background: #fd9644;
  box-shadow: 0 0 0 3px rgba(253, 150, 68, 0.2);
}

.status-indicator.ready {
  background: #00b894;
  box-shadow: 0 0 0 3px rgba(0, 184, 148, 0.2);
}
.revenue-compact {
  display: flex;
  flex-direction: column;
  height: 100%;
  gap: 0.75rem;
}

.total-revenue-compact {
  background: linear-gradient(135deg, #f8f9fa, #e9ecef);
  border-radius: 10px;
  padding: 0.75rem;
  text-align: center;
  border: 1px solid #dee2e6;
  transition: all 0.2s ease;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  position: relative;
  overflow: hidden;
  flex: 1;
}

.total-revenue-compact::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 2px;
  background: linear-gradient(90deg, #28a745, #20c997);
}

.total-revenue-compact:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.main-value {
  color: #2c3e50;
  font-size: clamp(1.5rem, 4vw, 2rem);
  font-weight: 800;
  margin-bottom: 0.2rem;
  line-height: 1;
}

.main-label {
  color: #495057;
  font-size: 0.8rem;
  font-weight: 600;
}

.revenue-periods {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
  flex: 1;
}

.period-item {
  background: #fff;
  border-radius: 8px;
  padding: 0.6rem;
  border: 1px solid #e9ecef;
  transition: all 0.2s ease;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 0;
}

.period-item:hover {
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  border-color: #28a745;
}

.period-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.4rem;
}

.period-name {
  font-size: 0.7rem;
  font-weight: 600;
  color: #495057;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}

.period-trend {
  display: flex;
  align-items: center;
  gap: 0.15rem;
  font-size: 0.6rem;
  padding: 0.1rem 0.3rem;
  border-radius: 6px;
  font-weight: 600;
}

.period-trend.positive {
  color: #28a745;
  background: rgba(40, 167, 69, 0.1);
}

.period-trend.negative {
  color: #dc3545;
  background: rgba(220, 53, 69, 0.1);
}

.period-trend.neutral {
  color: #6c757d;
  background: rgba(108, 117, 125, 0.1);
}
.period-amount {
  font-size: 0.9rem;
  font-weight: 700;
  color: #2c3e50;
  line-height: 1;
}
@media (max-width: 768px) {
  .revenue-periods {
    gap: 0.4rem;
  }
  
  .period-item {
    padding: 0.5rem;
  }
  
  .main-value {
    font-size: 1.4rem;
  }
}

@media (max-width: 480px) {
  .revenue-periods {
    grid-template-columns: 1fr;
  }
  
  .period-top {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.2rem;
  }
  
  .period-trend {
    align-self: flex-start;
  }
}

.sub-item {
  background: #f8f9fa;
  border-radius: 8px;
  padding: 0.75rem;
  border: 1px solid #dee2e6;
  transition: all 0.2s ease;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  text-align: center;
  min-height: 0;
}

.sub-item:hover {
  background: #e9ecef;
  transform: translateY(-1px);
}

.sub-item .value {
  font-size: clamp(1.2rem, 3vw, 1.8rem);
  font-weight: 700;
  color: #2c3e50;
  margin-bottom: 0.25rem;
  line-height: 1;
}

.sub-item .label {
  font-size: clamp(0.7rem, 1.5vw, 0.85rem);
  color: #555;
  font-weight: 500;
  line-height: 1.2;
}
.ticket-layout {
  display: flex;
  flex-direction: column;
  height: 100%;
  gap: 1rem;
}

.ticket-top-row {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 0.75rem;
  flex: 1;
}

.total-ticket-container {
  background: #e9ecef;
  border-radius: 10px;
  padding: 1rem;
  text-align: center;
  border: 1px solid #dee2e6;
  transition: all 0.2s ease;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  height: 100%;
  min-height: 0;
}

.total-ticket-container:hover {
  background: #dee2e6;
  transform: translateY(-1px);
}

.total-ticket-container .value {
  color: #2c3e50;
  font-size: clamp(2rem, 5vw, 3.5rem);
  font-weight: 800;
  margin-bottom: 0.25rem;
  line-height: 1;
}

.total-ticket-container .label {
  color: #555;
  font-size: clamp(0.8rem, 2vw, 1rem);
  font-weight: 600;
  letter-spacing: 0.5px;
}

.closed-ticket {
  background: #f8f9fa;
  border-radius: 8px;
  padding: 0.75rem;
  border: 1px solid #dee2e6;
  transition: all 0.2s ease;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  text-align: center;
  min-height: 0;
}

.closed-ticket:hover {
  background: #e9ecef;
  transform: translateY(-1px);
}

.closed-ticket .value {
  font-size: clamp(1.2rem, 3vw, 1.8rem);
  font-weight: 700;
  color: #2c3e50;
  margin-bottom: 0.25rem;
  line-height: 1;
}

.closed-ticket .label {
  font-size: clamp(0.7rem, 1.5vw, 0.85rem);
  color: #555;
  font-weight: 500;
  line-height: 1.2;
}

.ticket-bottom-row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 0.75rem;
  flex: 1;
}

.status-box {
  background: #f8f9fa;
  border-radius: 8px;
  padding: 0.75rem;
  border: 1px solid #dee2e6;
  transition: all 0.2s ease;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  text-align: center;
  min-height: 0;
}

.status-box:hover {
  background: #e9ecef;
  transform: translateY(-1px);
}

.status-box .value {
  font-size: clamp(1.2rem, 3vw, 1.8rem);
  font-weight: 700;
  color: #2c3e50;
  margin-bottom: 0.25rem;
  line-height: 1;
}

.status-box .label {
  font-size: clamp(0.7rem, 1.5vw, 0.85rem);
  color: #555;
  font-weight: 500;
  line-height: 1.2;
}

/* ===== TREND CHART STYLES ===== */
.trend-stats {
  background: #f8f9fa;
  border-radius: 6px;
  padding: 0.3rem 0.5rem;
  border: 1px solid #e9ecef;
  font-size: 0.7rem;
  margin-bottom: 0.5rem;
}

.month-container-wrapper {
  flex-grow: 1;
  min-height: 100px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.month-container {
  flex-grow: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 0.25rem;
}

.month-item {
  display: flex;
  align-items: center;
  padding: 0.2rem 0.3rem;
  border-radius: 3px;
  transition: all 0.2s ease;
  background: #fff;
  font-size: 0.7rem;
  animation: slideIn 0.3s ease-out;
}

.month-item:hover {
  background: #f8f9fa;
}

.month-label {
  width: 24px;
  font-weight: 600;
  color: #495057;
  text-align: left;
  font-size: 0.65rem;
}

.month-bar-container {
  flex: 1;
  height: 6px;
  background: #e9ecef;
  border-radius: 3px;
  margin: 0 6px;
  overflow: hidden;
  position: relative;
}

.month-bar {
  height: 100%;
  background: linear-gradient(90deg, #4dabf7, #339af0);
  border-radius: 3px;
  transition: width 0.8s ease;
  position: relative;
  box-shadow: 0 1px 2px rgba(51, 154, 240, 0.3);
}

.month-bar.high { background: linear-gradient(90deg, #51cf66, #40c057); }
.month-bar.medium { background: linear-gradient(90deg, #ffd43b, #fab005); }
.month-bar.low { background: linear-gradient(90deg, #ff8787, #fa5252); }

.month-count {
  width: 20px;
  font-weight: 600;
  color: #495057;
  text-align: right;
  font-size: 0.65rem;
}
.nav-buttons .btn {
  border-radius: 12px;
  padding: 0.15rem 0.4rem;
  font-size: 0.6rem;
  font-weight: 500;
}

.nav-buttons .btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.form-select-sm {
  font-size: 0.7rem;
  padding: 0.2rem 0.4rem;
  height: 24px;
}

@media (max-width: 768px) {
  .month-container-wrapper {
    min-height: 80px;
  }
  
  .month-item {
    padding: 0.15rem 0.25rem;
  }
  
  .month-label {
    width: 20px;
    font-size: 0.6rem;
  }
  
  .month-count {
    width: 18px;
    font-size: 0.6rem;
  }
  
  .month-bar-container {
    margin: 0 4px;
    height: 5px;
  }
  
  .trend-stats {
    padding: 0.2rem 0.4rem;
    font-size: 0.65rem;
  }
}

.chart-container {
  position: relative;
  width: 100%;
  flex-grow: 1;
}

.revenue-stats {
  font-size: 0.8rem;
}

.revenue-stats strong {
  font-size: 0.9rem;
}

.form-select-sm {
  font-size: 0.75rem;
  padding: 0.25rem 0.5rem;
}

.btn-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
}

.nav-buttons .btn {
  border-radius: 15px;
  padding: 0.2rem 0.6rem;
  font-size: 0.7rem;
  font-weight: 500;
}

.nav-buttons .btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.mt-2 {
  margin-top: 12px;
}

.spinner-border-sm {
  width: 1rem;
  height: 1rem;
}

.empty-state {
  text-align: center;
  padding: 1rem 0.5rem;
  color: #6c757d;
  font-size: 0.8rem;
}

.empty-state i {
  font-size: 1.5rem;
  margin-bottom: 0.3rem;
  opacity: 0.5;
}

.analytics-btn {
  position: fixed;
  bottom: 30px;
  right: 30px;
  z-index: 9999;
  padding: 15px;
  border-radius: 50%;
  font-size: 1.2rem;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 60px;
  height: 60px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  transition: all 0.3s ease;
  border: none;
}

.analytics-btn:hover {
  transform: scale(1.1);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4);
}

.container {
  margin-top: 0.5rem !important;
  padding-top: 0 !important;
  max-width: 100%;
  overflow: hidden;
}

body {
  padding-top: 0;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateX(-8px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

.month-item:nth-child(1) { animation-delay: 0.05s; }
.month-item:nth-child(2) { animation-delay: 0.1s; }
.month-item:nth-child(3) { animation-delay: 0.15s; }
.month-item:nth-child(4) { animation-delay: 0.2s; }
.month-item:nth-child(5) { animation-delay: 0.25s; }
.month-item:nth-child(6) { animation-delay: 0.3s; }

@media (max-width: 1200px) {
  .dashboard-grid {
    grid-template-columns: 1fr 1fr;
    grid-template-rows: auto auto auto;
    height: auto;
    min-height: auto;
    gap: 0.875rem;
  }
  
  .stat-card {
    padding: 1rem;
    min-height: 160px;
  }
  
  .user-overview-grid,
  .request-overview-grid {
    gap: 0.75rem;
  }
  
  .total-user-container,
  .total-request-main,
  .total-revenue-main,
  .total-ticket-container {
    padding: 1.25rem 0.75rem;
  }
  
  .status-item {
    padding: 0.75rem;
  }
  
  .status-content .metric-value {
    font-size: 1.2rem;
  }
  
  .total-revenue-main .value {
    font-size: 2rem;
  }
  
  .sub-item .value {
    font-size: 1.1rem;
  }
}

@media (max-width: 768px) {
  .dashboard-grid {
    grid-template-columns: 1fr;
    grid-template-rows: repeat(6, auto);
    gap: 0.75rem;
  }
  
  .stat-card {
    padding: 0.875rem;
    min-height: 140px;
  }
  
  .user-overview-grid,
  .request-overview-grid {
    grid-template-columns: 1fr;
    grid-template-rows: auto 1fr;
    gap: 0.75rem;
  }
  
  .total-user-container .metric-value,
  .total-request-main .metric-value {
    font-size: 3rem;
  }
  
  .user-status-breakdown,
  .status-breakdown {
    flex-direction: row;
    gap: 0.5rem;
  }
  
  .status-item {
    flex-direction: column;
    text-align: center;
    gap: 0.5rem;
    padding: 1rem 0.5rem;
  }
  
  .status-content {
    gap: 0.15rem;
  }
  
  .status-content .metric-value {
    font-size: 1.2rem;
  }
  
  .ticket-top-row,
  .ticket-bottom-row {
    grid-template-columns: 1fr;
    gap: 0.5rem;
  }
  
  .revenue-sub {
    grid-template-columns: 1fr 1fr;
  }
  
  .month-item {
    padding: 0.25rem 0.3rem;
  }
  
  .month-label {
    width: 28px;
    font-size: 0.7rem;
  }
  
  .month-count {
    width: 22px;
    font-size: 0.7rem;
  }
  
  .month-bar-container {
    margin: 0 6px;
    height: 6px;
  }
  
  .trend-stats {
    padding: 0.3rem 0.5rem;
    font-size: 0.7rem;
  }
  
  .nav-buttons .btn {
    padding: 0.15rem 0.5rem;
    font-size: 0.65rem;
  }
  
  .chart-container {
    height: 160px !important;
  }
  
  .revenue-stats {
    font-size: 0.75rem;
  }
  
  .revenue-stats strong {
    font-size: 0.8rem;
  }
}

@media (max-width: 480px) {
  .dashboard-grid {
    margin-top: 0.25rem;
    gap: 0.625rem;
  }
  
  .stat-card {
    padding: 0.75rem;
    min-height: 130px;
  }
  
  .user-status-breakdown,
  .status-breakdown {
    flex-direction: column;
  }
  
  .status-item {
    flex-direction: row;
    text-align: left;
  }
  
  .stat-header {
    flex-direction: column;
    gap: 0.5rem;
    align-items: flex-start;
  }
  
  .trend-indicator,
  .completion-badge {
    align-self: flex-start;
  }
  
  .total-user-container .value,
  .total-request-container .value {
    font-size: 2rem;
  }
  
  .mini-box .value {
    font-size: 1.3rem;
  }
}

@media (min-height: 800px) {
  .stat-card {
    min-height: 200px;
  }
  
  .total-user-container .value,
  .total-request-container .value {
    font-size: 3rem;
  }
  
  .mini-box .value {
    font-size: 1.8rem;
  }
}

.metric-value {
  transition: all 0.3s ease;
}

.status-item:hover .metric-value {
  transform: scale(1.05);
}
</style>


<div class="container">
  <div class="dashboard-grid">

 <!-- === USER OVERVIEW === -->
<div class="stat-card">
  <div class="stat-header">
    <div class="header-content">
      <i class="icon-users"></i>
      <span>User Management</span>
    </div>
    <div class="trend-indicator">
      <i class="icon-trend-up"></i>
      <span><?= round((($verified_users + $pending_users) / $total_users) * 100, 1) ?>% Active</span>
    </div>
  </div>

  <div class="user-overview-grid">
    <!-- Total Users - Most prominent -->
    <div class="total-user-container">
      <div class="metric-value"><?= $total_users ?></div>
      <div class="metric-label">Total Users</div>
      <div class="metric-context">All registered accounts</div>
    </div>

    <!-- User Status Breakdown -->
    <div class="user-status-breakdown">
      <div class="status-item verified">
        <div class="status-indicator"></div>
        <div class="status-content">
          <div class="metric-value"><?= $verified_users ?></div>
          <div class="metric-label">Verified</div>
        </div>
        <div class="status-percentage"><?= round(($verified_users / $total_users) * 100, 1) ?>%</div>
      </div>

      <div class="status-item pending">
        <div class="status-indicator"></div>
        <div class="status-content">
          <div class="metric-value"><?= $pending_users ?></div>
          <div class="metric-label">Pending</div>
        </div>
        <div class="status-percentage"><?= round(($pending_users / $total_users) * 100, 1) ?>%</div>
      </div>

      <div class="status-item rejected">
        <div class="status-indicator"></div>
        <div class="status-content">
          <div class="metric-value"><?= $rejected_users ?></div>
          <div class="metric-label">Rejected</div>
        </div>
        <div class="status-percentage"><?= round(($rejected_users / $total_users) * 100, 1) ?>%</div>
      </div>
    </div>
  </div>
</div>


<!-- === DOCUMENT REQUEST OVERVIEW === -->
<div class="stat-card">
  <div class="stat-header">
    <div class="header-content">
      <span>Document Requests</span>
    </div>
    <?php 
    $percentage_ready = ($total_requests > 0) 
        ? round(($ready_requests / $total_requests) * 100, 1) 
        : 0;
    ?>
    <div class="completion-badge">
      <?= $percentage_ready ?>% Ready
    </div>
  </div>

  <div class="request-overview-grid">
    <!-- Total Requests - Most prominent -->
    <div class="total-request-main">
      <div class="metric-value"><?= $total_requests ?></div>
      <div class="metric-label">Total Requests</div>
      <div class="metric-context">All document requests</div>
    </div>

    <!-- Status Breakdown -->
    <div class="status-breakdown">
      <div class="status-item">
        <div class="status-indicator pending"></div>
        <div class="status-content">
          <div class="metric-value"><?= $pending_requests ?></div>
          <div class="metric-label">Pending Request</div>
        </div>
      </div>

      <div class="status-item">
        <div class="status-indicator in-progress"></div>
        <div class="status-content">
          <div class="metric-value"><?= $inprogress_requests ?></div>
          <div class="metric-label">In Progress</div>
        </div>
      </div>

      <div class="status-item">
        <div class="status-indicator ready"></div>
        <div class="status-content">
          <div class="metric-value"><?= $ready_requests ?></div>
          <div class="metric-label">Ready for Pickup</div>
        </div>
      </div>
    </div>
  </div>
</div>


<!-- === REQUEST TREND CHART === -->
<div class="stat-card">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <span class="fw-bold" style="font-size: 0.85rem;">Requests Trend</span>
    <select id="yearFilter" class="form-select form-select-sm" style="width:90px; font-size: 0.7rem;">
      <option selected><?= date('Y') ?></option>
      <option><?= date('Y')-1 ?></option>
      <option><?= date('Y')-2 ?></option>
    </select>
  </div>
  
  <div class="trend-stats">
    <div class="d-flex justify-content-between align-items-center">
      <small class="text-muted">Total: <strong id="totalYearCount">0</strong></small>
      <small class="text-muted">Avg/Month: <strong id="avgMonthCount">0</strong></small>
    </div>
  </div>
  
  <div class="month-container-wrapper" id="monthContainerWrapper">
    <div class="month-container" id="monthContainer">
      <!-- Loading spinner -->
      <div class="text-center py-2">
        <div class="spinner-border spinner-border-sm text-primary" role="status">
          <span class="visually-hidden">Loading...</span>
        </div>
        <span class="ms-2 text-muted" style="font-size: 0.7rem;">Loading data...</span>
      </div>
    </div>
  </div>
  
  <div class="d-flex justify-content-between align-items-center mt-1 pt-1 border-top">
    <div class="nav-buttons">
      <button id="prevBtn" class="btn btn-sm btn-outline-primary me-1" disabled>
        <i class="fa fa-chevron-left"></i> Prev
      </button>
      <button id="nextBtn" class="btn btn-sm btn-outline-primary">Next <i class="fa fa-chevron-right"></i></button>
    </div>
    <small class="text-muted" id="currentRange" style="font-size: 0.7rem;">Jan - Jun</small>
  </div>
</div>



<!-- === REVENUE RECORD === -->
<div class="stat-card">
  <div class="stat-header">
    <div class="header-content">
      <span>Revenue Overview</span>
    </div>
    <div class="trend-indicator revenue-trend">
      <i class="fa fa-arrow-<?= $overallGrowth >= 0 ? 'up' : 'down' ?>"></i>
      <span id="revenueGrowth"><?= ($overallGrowth >= 0 ? '+' : '') . number_format($overallGrowth, 1) ?>%</span>
    </div>
  </div>

  <div class="revenue-compact">
    <!-- Main Total Revenue -->
    <div class="total-revenue-compact">
      <div class="main-value">₱ <?= number_format($totalRevenue, 0) ?></div>
      <div class="main-label">Total Revenue</div>
    </div>
    
    <!-- Period Breakdown -->
    <div class="revenue-periods">
      <div class="period-item">
        <div class="period-top">
          <span class="period-name">Monthly</span>
          <div class="period-trend <?= getMonthlyTrendClass($pdo) ?>">
            <i class="fa fa-arrow-<?= getMonthlyTrendArrow($pdo) ?>"></i>
            <span><?= getMonthlyGrowthDisplay($pdo) ?></span>
          </div>
        </div>
        <div class="period-amount">₱ <?= number_format($monthlyRevenue, 0) ?></div>
      </div>
      
      <div class="period-item">
        <div class="period-top">
          <span class="period-name">Weekly</span>
          <div class="period-trend <?= getWeeklyTrendClass($pdo) ?>">
            <i class="fa fa-arrow-<?= getWeeklyTrendArrow($pdo) ?>"></i>
            <span><?= getWeeklyGrowthDisplay($pdo) ?></span>
          </div>
        </div>
        <div class="period-amount">₱ <?= number_format($weeklyRevenue, 0) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- === REVENUE CHART === -->
<div class="stat-card">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="fw-bold" style="font-size: 0.9rem;">Revenue Trend</span>
    <select id="revenuePeriod" class="form-select form-select-sm" style="width:110px; font-size: 0.75rem;">
      <option value="total" selected>Total</option>
      <option value="monthly">Monthly</option>
      <option value="weekly">Weekly</option>
    </select>
  </div>
  
  <div class="chart-container" style="height: 140px;">
    <canvas id="revenueLineChart"></canvas>
  </div>
  
  <div class="revenue-stats mt-2 pt-2 border-top" style="font-size: 0.75rem;">
    <div class="row text-center">
      <div class="col-4">
        <small class="text-muted d-block">Current</small>
        <strong id="currentRevenue">₱0.00</strong>
      </div>
      <div class="col-4">
        <small class="text-muted d-block">Change</small>
        <strong id="revenueChange" class="text-success">+0%</strong>
      </div>
      <div class="col-4">
        <small class="text-muted d-block">Period</small>
        <strong id="revenuePeriodLabel">Total</strong>
      </div>
    </div>
  </div>
</div>

<!-- === SUPPORT TICKET OVERVIEW === -->
<div class="stat-card">
  <div class="stat-header">Support Ticket Overview</div>

  <div class="ticket-layout">
    <!-- Top Row: Total Ticket (Hierarchy) and Closed -->
    <div class="ticket-top-row">
      <div class="total-ticket-container">
        <div class="value"><?= $totalTickets ?></div>
        <div class="label">Total Ticket</div>
      </div>
      
      <div class="closed-ticket">
        <div class="value"><?= $closedTickets ?></div>
        <div class="label">Closed</div>
      </div>
    </div>

    <!-- Bottom Row: Open, In Progress, Resolved -->
    <div class="ticket-bottom-row">
      <div class="status-box">
        <div class="value"><?= $openTickets ?></div>
        <div class="label">Open</div>
      </div>

      <div class="status-box">
        <div class="value"><?= $inProgressTickets ?></div>
        <div class="label">In Progress</div>
      </div>

      <div class="status-box">
        <div class="value"><?= $resolvedTickets ?></div>
        <div class="label">Resolved</div>
      </div>
    </div>
  </div>
</div>

  </div>
</div>

<!-- MODAL -->
<button class="btn btn-dark shadow-lg analytics-btn" data-bs-toggle="modal" data-bs-target="#analyticsModal" title="Analytics">
    <i class="fa fa-bar-chart"></i>
</button>

<div class="modal fade" id="analyticsModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="fa fa-area-chart"></i> Analytics Dashboard</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <!-- Filter Controls -->
        <div class="row mb-3">
          <div class="col-md-12">
            <div class="card shadow-sm border-0">
              <div class="card-body py-2">
                <div class="row align-items-center">
                  <div class="col-md-4">
                    <label class="form-label fw-semibold mb-1">Report Period</label>
                    <select class="form-select form-select-sm" id="reportPeriod">
                      <option value="yearly">This Year</option>
                      <option value="monthly" selected>This Month</option>
                      <option value="weekly">This Week</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label fw-semibold mb-1">Generated On</label>
                    <div class="text-muted small"><?php echo date('F j, Y g:i A'); ?></div>
                  </div>
                  <div class="col-md-4 text-end">
                    <button class="btn btn-success btn-sm" id="exportExcel"><i class="fa fa-file-excel-o"></i> Export to Excel</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Stats Grid -->
        <div class="row g-3 mb-3">
          <!-- User Overview -->
          <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-primary text-white py-2">
                <h6 class="mb-0"><i class="fa fa-users me-2"></i> User Overview</h6>
              </div>
              <div class="card-body p-3">
                <div class="row text-center">
                  <div class="col-6 border-end">
                    <h4 class="text-primary mb-1" id="modalTotalUsers">0</h4>
                    <small class="text-muted">Total Users</small>
                  </div>
                  <div class="col-6">
                    <h4 class="text-danger mb-1" id="modalRejectedUsers">0</h4>
                    <small class="text-muted">Rejected</small>
                  </div>
                </div>
                <div class="mt-2 pt-2 border-top text-center">
                  <small class="text-muted" id="userStats">Active: 0 | Pending: 0</small>
                </div>
              </div>
            </div>
          </div>

          <!-- Request Overview -->
          <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-info text-white py-2">
                <h6 class="mb-0"><i class="fa fa-file-text me-2"></i> Request Overview</h6>
              </div>
              <div class="card-body p-3">
                <div class="row text-center">
                  <div class="col-6 border-end">
                    <h4 class="text-success mb-1" id="modalTotalRequests">0</h4>
                    <small class="text-muted">Total Requests</small>
                  </div>
                  <div class="col-6">
                    <h4 class="text-danger mb-1" id="modalRejectedRequests">0</h4>
                    <small class="text-muted">Rejected</small>
                  </div>
                </div>
                <div class="mt-2 pt-2 border-top text-center">
                  <small class="text-muted" id="requestStats">Pending: 0 | In Progress: 0</small>
                </div>
              </div>
            </div>
          </div>

          <!-- Revenue Overview -->
          <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-success text-white py-2">
                <h6 class="mb-0"><i class="fa fa-line-chart me-2"></i> Revenue Overview</h6>
              </div>
              <div class="card-body p-3">
                <div class="text-center">
                  <h3 class="text-primary mb-1" id="modalTotalRevenue">₱0.00</h3>
                  <small class="text-muted">Total Revenue</small>
                </div>
                <div class="mt-2 pt-2 border-top text-center">
                  <span class="badge bg-warning text-dark" id="modalRevenuePrediction">
                    Next Period: ₱0.00
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Charts Row -->
        <div class="row g-3 mb-3">
          <!-- Request Chart -->
          <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-info text-white py-2">
                <h6 class="mb-0"><i class="fa fa-bar-chart me-2"></i> Request Statistics</h6>
              </div>
              <div class="card-body p-3">
                <div class="chart-container" style="height: 180px;">
                  <canvas id="modalRequestChart"></canvas>
                </div>
                <div class="text-center mt-2">
                  <small class="text-muted" id="requestChartDates">Date labels will appear here</small>
                </div>
              </div>
            </div>
          </div>

          <!-- Revenue Chart -->
          <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
              <div class="card-header bg-success text-white py-2">
                <h6 class="mb-0"><i class="fa fa-line-chart me-2"></i> Revenue Trend</h6>
              </div>
              <div class="card-body p-3">
                <div class="chart-container" style="height: 180px;">
                  <canvas id="modalRevenueChart"></canvas>
                </div>
                <div class="text-center mt-2">
                  <small class="text-muted" id="revenueChartDates">Date labels will appear here</small>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Support Tickets -->
        <div class="row">
          <div class="col-md-12">
            <div class="card shadow-sm border-0">
              <div class="card-header bg-secondary text-white py-2">
                <h6 class="mb-0"><i class="fa fa-ticket me-2"></i> Support Tickets</h6>
              </div>
              <div class="card-body p-3">
                <div class="row text-center">
                  <div class="col-md-3 border-end">
                    <h4 class="text-primary mb-1" id="modalTotalTickets">0</h4>
                    <small class="text-muted">Total Tickets</small>
                  </div>
                  <div class="col-md-3 border-end">
                    <h4 class="text-success mb-1" id="modalOpenTickets">0</h4>
                    <small class="text-muted">Open</small>
                  </div>
                  <div class="col-md-3 border-end">
                    <h4 class="text-warning mb-1" id="modalInProgressTickets">0</h4>
                    <small class="text-muted">In Progress</small>
                  </div>
                  <div class="col-md-3">
                    <h4 class="text-info mb-1" id="modalResolvedTickets">0</h4>
                    <small class="text-muted">Resolved</small>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ===== DASHBOARD CHARTS FUNCTIONALITY =====

// Revenue Chart Variables
let revenueChart = null;
let currentRevenuePeriod = 'total';

// Request Trend Variables
let currentGroup = 'first';
let firstMonths = [], firstCounts = [], secondMonths = [], secondCounts = [];

// ===== MAIN DASHBOARD REVENUE CHART =====
function initRevenueChart() {
    const ctx = document.getElementById('revenueLineChart').getContext('2d');
    
    revenueChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: getRevenueLabels('total'),
            datasets: [{
                data: getRevenueData('total'),
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.05)',
                borderWidth: 2,
                fill: true,
                tension: 0.3,
                pointRadius: 2,
                pointHoverRadius: 4,
                pointBackgroundColor: '#28a745',
                pointBorderColor: '#fff',
                pointBorderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    displayColors: false,
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    titleFont: { size: 10 },
                    bodyFont: { size: 10 },
                    padding: 8,
                    callbacks: {
                        label: function(context) {
                            return `Revenue: ₱${context.parsed.y.toLocaleString()}`;
                        },
                        title: function(tooltipItems) {
                            return getTooltipTitle(currentRevenuePeriod, tooltipItems[0].dataIndex);
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { 
                        display: false,
                        drawBorder: false
                    },
                    ticks: { 
                        font: { size: 9 },
                        maxRotation: 0
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: { 
                        color: 'rgba(0,0,0,0.03)',
                        drawBorder: false
                    },
                    ticks: { 
                        font: { size: 9 },
                        callback: function(value) {
                            if (value === 0) return '₱0';
                            return '₱' + (value/1000).toFixed(0) + 'K';
                        },
                        maxTicksLimit: 5
                    }
                }
            },
            elements: {
                line: {
                    tension: 0.3
                }
            }
        }
    });
    
    updateRevenueStats(getRevenueData('total'));
}

// Revenue Chart Helper Functions
function getRevenueLabels(period) {
    switch(period) {
        case 'weekly':
            return ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
        case 'monthly':
            const days = [];
            for (let i = 29; i >= 0; i--) {
                const date = new Date();
                date.setDate(date.getDate() - i);
                days.push(date.getDate().toString());
            }
            return days;
        case 'total':
        default:
            return <?= json_encode($monthlyLabels) ?>;
    }
}

function getRevenueData(period) {
    switch(period) {
        case 'weekly':
            return <?= json_encode(array_pad($weeklyData, 7, 0)) ?>;
        case 'monthly':
            const dailyData = [];
            for (let i = 0; i < 30; i++) {
                dailyData.push(Math.random() * 5000 + 1000);
            }
            return dailyData;
        case 'total':
        default:
            return <?= json_encode($monthlyData) ?>;
    }
}

function getTooltipTitle(period, index) {
    switch(period) {
        case 'weekly':
            const weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            return weekDays[index];
        case 'monthly':
            const date = new Date();
            date.setDate(date.getDate() - (29 - index));
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        case 'total':
        default:
            const months = <?= json_encode($monthlyLabels) ?>;
            return months[index] + ' ' + new Date().getFullYear();
    }
}

function updateRevenueStats(data) {
    if (data.length < 2) return;
    
    const current = data[data.length - 1] || 0;
    const previous = data[data.length - 2] || 0;
    const change = previous > 0 ? ((current - previous) / previous) * 100 : 0;
    
    document.getElementById('currentRevenue').textContent = `₱${(current/1000).toFixed(0)}K`;
    
    const changeElement = document.getElementById('revenueChange');
    changeElement.textContent = `${change >= 0 ? '+' : ''}${change.toFixed(0)}%`;
    changeElement.className = change >= 0 ? 'text-success' : 'text-danger';
    
    document.getElementById('revenuePeriodLabel').textContent = 
        currentRevenuePeriod.charAt(0).toUpperCase() + currentRevenuePeriod.slice(1);
}

function switchRevenuePeriod(period) {
    currentRevenuePeriod = period;
    
    let labels = getRevenueLabels(period);
    let data = getRevenueData(period);
    
    if (revenueChart) {
        revenueChart.data.labels = labels;
        revenueChart.data.datasets[0].data = data;
        revenueChart.update();
        updateRevenueStats(data);
    }
}

// ===== REQUEST TREND CHART FUNCTIONALITY =====
function loadYearData(year) {
    document.getElementById('monthContainer').innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <span class="ms-2 text-muted" style="font-size: 0.8rem;">Loading data for ${year}...</span>
        </div>
    `;

    document.getElementById('prevBtn').disabled = true;
    document.getElementById('nextBtn').disabled = true;

    fetch(`get_monthly_count.php?year=${year}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                throw new Error("Failed to load data.");
            }

            firstMonths = data.firstMonths;
            firstCounts = data.firstCounts;
            secondMonths = data.secondMonths;
            secondCounts = data.secondCounts;

            updateChartStats();

            const currentMonth = new Date().getMonth() + 1;
            currentGroup = (currentMonth <= 6) ? 'first' : 'second';

            renderMonths();
        })
        .catch(err => {
            document.getElementById("monthContainer").innerHTML = `
                <div class="empty-state">
                    <i class="fa fa-exclamation-circle"></i>
                    <div class="mt-1">Failed to load data</div>
                </div>
            `;
        });
}

function updateChartStats() {
    const allCounts = [...firstCounts, ...secondCounts];
    const total = allCounts.reduce((sum, count) => sum + count, 0);
    const avg = Math.round(total / 12) || 0;
    
    document.getElementById('totalYearCount').textContent = total;
    document.getElementById('avgMonthCount').textContent = avg;
}

function renderMonths() {
    const container = document.getElementById("monthContainer");
    
    if (firstMonths.length === 0 || secondMonths.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fa fa-chart-bar"></i>
                <div class="mt-1">No data available</div>
            </div>
        `;
        return;
    }

    let months, counts;
    if(currentGroup === 'first'){
        months = firstMonths;
        counts = firstCounts;
        document.getElementById('currentRange').textContent = 'Jan - Jun';
    } else {
        months = secondMonths;
        counts = secondCounts;
        document.getElementById('currentRange').textContent = 'Jul - Dec';
    }

    const maxCount = Math.max(...counts) || 1;
    
    let html = '';
    for(let i = 0; i < 6; i++){
        const count = counts[i];
        const width = (count/maxCount)*100;
        
        let barClass = 'month-bar';
        if (width >= 70) barClass += ' high';
        else if (width >= 30) barClass += ' medium';
        else barClass += ' low';
        
        html += `
        <div class="month-item">
            <span class="month-label">${months[i]}</span>
            <div class="month-bar-container">
                <div class="${barClass}" style="width: ${width}%"></div>
            </div>
            <span class="month-count">${count}</span>
        </div>
        `;
    }
    
    container.innerHTML = html;

    document.getElementById("prevBtn").disabled = currentGroup === 'first';
    document.getElementById("nextBtn").disabled = currentGroup === 'second';
}

// ===== MODAL ANALYTICS FUNCTIONALITY =====
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Modal Charts
    const revenueCtx = document.getElementById('modalRevenueChart').getContext('2d');
    const requestCtx = document.getElementById('modalRequestChart').getContext('2d');
    
    let modalRevenueChart = null;
    let modalRequestChart = null;

    // Initialize Modal Revenue Chart
    function initModalRevenueChart() {
        if (modalRevenueChart) {
            modalRevenueChart.destroy();
        }
        
        modalRevenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Revenue',
                    data: [],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 2,
                    pointHoverRadius: 4,
                    borderWidth: 2
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
                                return '₱' + context.raw.toLocaleString('en-PH', { 
                                    minimumFractionDigits: 2, 
                                    maximumFractionDigits: 2 
                                });
                            }
                        },
                        bodyFont: { size: 10 },
                        titleFont: { size: 10 }
                    }
                },
                scales: { 
                    x: {
                        ticks: {
                            font: { size: 9 },
                            maxRotation: 45
                        },
                        grid: { display: false }
                    },
                    y: { 
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + (value/1000).toFixed(0) + 'K';
                            },
                            font: { size: 9 }
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    } 
                },
                layout: {
                    padding: {
                        left: 5,
                        right: 5,
                        top: 5,
                        bottom: 5
                    }
                }
            }
        });
    }

    // Initialize Modal Request Chart
    function initModalRequestChart() {
        if (modalRequestChart) {
            modalRequestChart.destroy();
        }
        
        modalRequestChart = new Chart(requestCtx, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: 'Requests',
                    data: [],
                    backgroundColor: 'rgba(23, 162, 184, 0.7)',
                    borderColor: '#17a2b8',
                    borderWidth: 1,
                    borderRadius: 3,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        bodyFont: { size: 10 },
                        titleFont: { size: 10 }
                    }
                },
                scales: { 
                    x: {
                        ticks: {
                            font: { size: 9 },
                            maxRotation: 45
                        },
                        grid: { display: false }
                    },
                    y: { 
                        beginAtZero: true,
                        ticks: {
                            font: { size: 9 }
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    } 
                },
                layout: {
                    padding: {
                        left: 5,
                        right: 5,
                        top: 5,
                        bottom: 5
                    }
                }
            }
        });
    }

    // Modal Helper Functions
    function formatDateLabels(labels, period) {
        if (labels.length === 0) return 'No data available';
        
        if (period === 'weekly') {
            return `Week: ${labels[0]} - ${labels[labels.length - 1]}`;
        } else if (period === 'monthly') {
            return `Month: ${labels.join(', ')}`;
        } else if (period === 'yearly') {
            return `Year: ${labels.join(', ')}`;
        }
        return labels.join(', ');
    }

    // Load Modal Report Data
    function loadReportData(period = 'monthly') {
        // Show loading state
        document.getElementById('modalTotalUsers').textContent = '...';
        document.getElementById('modalRejectedUsers').textContent = '...';
        document.getElementById('modalTotalRequests').textContent = '...';
        document.getElementById('modalRejectedRequests').textContent = '...';
        document.getElementById('modalTotalRevenue').textContent = '...';
        document.getElementById('modalTotalTickets').textContent = '...';
        document.getElementById('modalOpenTickets').textContent = '...';
        document.getElementById('modalInProgressTickets').textContent = '...';
        document.getElementById('modalResolvedTickets').textContent = '...';
        document.getElementById('requestChartDates').textContent = 'Loading...';
        document.getElementById('revenueChartDates').textContent = 'Loading...';
        document.getElementById('userStats').textContent = 'Loading...';
        document.getElementById('requestStats').textContent = 'Loading...';

        const apiUrl = '/Project_A2/assets/api/get_report_data.php?period=' + period;

        fetch(apiUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                
                // Update User Overview
                document.getElementById('modalTotalUsers').textContent = data.userOverview.totalUsers.toLocaleString();
                document.getElementById('modalRejectedUsers').textContent = data.userOverview.rejectedUsers.toLocaleString();
                document.getElementById('userStats').textContent = 
                    `Active: ${data.userOverview.verifiedUsers || 0} | Pending: ${data.userOverview.pendingUsers || 0}`;
                
                // Update Request Overview
                document.getElementById('modalTotalRequests').textContent = data.requestOverview.totalRequests.toLocaleString();
                document.getElementById('modalRejectedRequests').textContent = data.requestOverview.rejectedRequests.toLocaleString();
                document.getElementById('requestStats').textContent = 
                    `Pending: ${data.requestOverview.pendingRequests || 0} | In Progress: ${data.requestOverview.inProgressRequests || 0}`;
                
                // Update Request Chart
                if (data.requestChart && data.requestChart.labels && data.requestChart.data) {
                    const maxDataPoints = 8;
                    const labels = data.requestChart.labels.slice(-maxDataPoints);
                    const chartData = data.requestChart.data.slice(-maxDataPoints);
                    
                    modalRequestChart.data.labels = labels;
                    modalRequestChart.data.datasets[0].data = chartData;
                    modalRequestChart.update('none');
                    
                    document.getElementById('requestChartDates').textContent = formatDateLabels(labels, period);
                }
                
                // Update Revenue Overview
                document.getElementById('modalTotalRevenue').textContent = '₱' + data.revenueOverview.totalRevenue.toLocaleString('en-PH', { 
                    minimumFractionDigits: 2, 
                    maximumFractionDigits: 2 
                });
                
                document.getElementById('modalRevenuePrediction').textContent = 
                    `Next: ₱${data.revenueOverview.prediction.toLocaleString('en-PH', { 
                        minimumFractionDigits: 2, 
                        maximumFractionDigits: 2 
                    })}`;
                
                // Update Revenue Chart
                if (data.revenueChart && data.revenueChart.labels && data.revenueChart.data) {
                    const maxDataPoints = 8;
                    const labels = data.revenueChart.labels.slice(-maxDataPoints);
                    const chartData = data.revenueChart.data.slice(-maxDataPoints);
                    
                    modalRevenueChart.data.labels = labels;
                    modalRevenueChart.data.datasets[0].data = chartData;
                    modalRevenueChart.update('none');
                    
                    document.getElementById('revenueChartDates').textContent = formatDateLabels(labels, period);
                }
                
                // Update Support Tickets
                document.getElementById('modalTotalTickets').textContent = data.supportTickets.totalTickets.toLocaleString();
                document.getElementById('modalOpenTickets').textContent = data.supportTickets.openTickets.toLocaleString();
                document.getElementById('modalInProgressTickets').textContent = data.supportTickets.inProgressTickets.toLocaleString();
                document.getElementById('modalResolvedTickets').textContent = data.supportTickets.resolvedTickets.toLocaleString();
            })
            .catch(error => {
                console.error('Error loading report data:', error);
                // Set default values on error
                document.getElementById('modalTotalUsers').textContent = '0';
                document.getElementById('modalRejectedUsers').textContent = '0';
                document.getElementById('modalTotalRequests').textContent = '0';
                document.getElementById('modalRejectedRequests').textContent = '0';
                document.getElementById('modalTotalRevenue').textContent = '₱0.00';
                document.getElementById('modalTotalTickets').textContent = '0';
                document.getElementById('modalOpenTickets').textContent = '0';
                document.getElementById('modalInProgressTickets').textContent = '0';
                document.getElementById('modalResolvedTickets').textContent = '0';
                document.getElementById('requestChartDates').textContent = 'No data';
                document.getElementById('revenueChartDates').textContent = 'No data';
                document.getElementById('userStats').textContent = 'Active: 0 | Pending: 0';
                document.getElementById('requestStats').textContent = 'Pending: 0 | In Progress: 0';
            });
    }

    // Initialize modal charts
    initModalRevenueChart();
    initModalRequestChart();

    // Modal Event Listeners
    document.getElementById('reportPeriod').addEventListener('change', function() {
        loadReportData(this.value);
    });

    document.getElementById('exportExcel').addEventListener('click', function() {
        const period = document.getElementById('reportPeriod').value;
        
        const originalText = this.innerHTML;
        this.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Generating...';
        this.disabled = true;
        
        const exportUrl = `/Project_A2/assets/api/export_excel.php?period=${period}`;
        window.open(exportUrl, '_blank');
        
        setTimeout(() => {
            this.innerHTML = originalText;
            this.disabled = false;
        }, 2000);
    });

    // Initialize modal data when opened
    document.getElementById('analyticsModal').addEventListener('show.bs.modal', function () {
        loadReportData('monthly');
    });

    // Fix chart resizing
    document.getElementById('analyticsModal').addEventListener('shown.bs.modal', function () {
        setTimeout(() => {
            if (modalRevenueChart) modalRevenueChart.resize();
            if (modalRequestChart) modalRequestChart.resize();
        }, 300);
    });

    // ===== DASHBOARD INITIALIZATION =====
    // Initialize Revenue Chart
    initRevenueChart();
    
    // Initialize Request Trend Chart
    const currentYear = new Date().getFullYear();
    loadYearData(currentYear);
});

// ===== DASHBOARD EVENT LISTENERS =====
document.getElementById('revenuePeriod').addEventListener('change', function() {
    switchRevenuePeriod(this.value);
});

document.getElementById("prevBtn").onclick = () => { 
    currentGroup = 'first'; 
    renderMonths(); 
};

document.getElementById("nextBtn").onclick = () => { 
    currentGroup = 'second'; 
    renderMonths(); 
};

document.getElementById("yearFilter").addEventListener("change", function() {
    const year = this.value;
    loadYearData(year);
});
</script>

   

<?php include 'footer.php'; ?>
