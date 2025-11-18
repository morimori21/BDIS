<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<?php
function formatNumberShort($number) {
    if ($number >= 1000000000) {
        return round($number / 1000000000, 1) . 'B';
    } elseif ($number >= 1000000) {
        return round($number / 1000000, 1) . 'M';
    } elseif ($number >= 1000) {
        return round($number / 1000, 1) . 'k';
    } else {
        return $number;
    }
}
?>

<?php
include 'header.php';
$residentId = $_SESSION['user_id'];



// Your existing PHP queries and logic here
$queries = [
  'overview' => "
SELECT
  COUNT(*) AS total_requests,
  SUM(CASE WHEN request_status = 'pending' THEN 1 ELSE 0 END) AS pending,
  SUM(CASE WHEN request_status IN ('in-progress','printed') THEN 1 ELSE 0 END) AS in_progress,
  SUM(CASE WHEN request_status = 'signed' THEN 1 ELSE 0 END) AS ready
FROM document_requests
WHERE resident_id = ?
  ",
  'trend' => "
    SELECT DATE(date_requested) AS request_date, COUNT(*) AS total
    FROM document_requests
    WHERE resident_id = ?
      AND request_status = 'ready'
      AND date_requested >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DATE(date_requested)
    ORDER BY request_date ASC
  ",
  'recent_requests' => "
  SELECT dr.request_id, dt.doc_name, dr.request_status, dr.date_requested,
         s.schedule_date
  FROM document_requests dr
  JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
  LEFT JOIN schedule s ON dr.schedule_id = s.schedule_id
  WHERE dr.resident_id = ?
  ORDER BY dr.date_requested DESC
",
  'revenue' => "
    SELECT SUM(dt.doc_price) AS total_spent,
           COUNT(*) AS total_docs
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    WHERE dr.resident_id = ?
  ",
  'active_schedule' => "
    SELECT schedule_id, schedule_date, schedule_slots
    FROM schedule
    WHERE schedule_date >= CURDATE()
      AND schedule_slots > 0
    ORDER BY schedule_date ASC
    LIMIT 1
"
];





$stats = [];
foreach ($queries as $key => $sql) {
    $stmt = $pdo->prepare($sql);
    if (strpos($sql, '?') !== false) {
        $stmt->execute([$residentId]);
    } else {
        $stmt->execute();
    }
    $stats[$key] = $stmt->fetch(PDO::FETCH_ASSOC);
}
extract($stats);

// Chart data
$chartLabels = [];
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
  $day = date('D', strtotime("-$i days"));
  $chartLabels[] = $day;
  $chartData[$day] = 0;
}
$trendStmt = $pdo->prepare($queries['trend']);
$trendStmt->execute([$residentId]);
foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $chartData[date('D', strtotime($row['request_date']))] = (int)$row['total'];
}

$stmt = $pdo->prepare($queries['recent_requests']);
$stmt->execute([$residentId]);
$recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$queries['active_schedule_list'] = "
  SELECT schedule_id, schedule_date, schedule_slots
  FROM schedule
  WHERE schedule_date >= CURDATE()
    AND schedule_slots > 0
  ORDER BY schedule_date ASC
";
$stmt = $pdo->prepare($queries['active_schedule_list']);
$stmt->execute();
$active_schedule_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>


<!-- METHEMETHIC -->
<?php
// Add these queries to your existing queries array
$queries['weekly_revenue'] = "
    SELECT SUM(dt.doc_price) AS weekly_spent
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    WHERE dr.resident_id = ?
      AND dr.date_requested >= CURDATE() - INTERVAL 7 DAY
";

$queries['monthly_revenue'] = "
    SELECT SUM(dt.doc_price) AS monthly_spent
    FROM document_requests dr
    JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id
    WHERE dr.resident_id = ?
      AND dr.date_requested >= CURDATE() - INTERVAL 30 DAY
";

// Execute the new queries
$weekly_stmt = $pdo->prepare($queries['weekly_revenue']);
$weekly_stmt->execute([$residentId]);
$weekly_revenue = $weekly_stmt->fetch(PDO::FETCH_ASSOC);

$monthly_stmt = $pdo->prepare($queries['monthly_revenue']);
$monthly_stmt->execute([$residentId]);
$monthly_revenue = $monthly_stmt->fetch(PDO::FETCH_ASSOC);
?>

<style>
  
body {
  margin-left:80px;
  margin-top:0px;
  padding: 0;
  overflow-x: hidden;
}

.container {
  margin-top: 0.5rem !important;
  padding-top: 0 !important;
  max-width: 100%;
}

.navbar {
  margin-bottom: 0 !important;
  padding-bottom: 0.5rem !important;
}

/* ===== DASHBOARD GRID LAYOUT ===== */
.dashboard-grid {
  display: grid;
  grid-template-areas:
    "overview trend recent"
    "revenue schedule recent";
  grid-template-columns: 2fr 2fr 1.5fr;
  grid-template-rows: 1fr 1fr;
  gap: 1.5rem;
  padding: 0.5rem 0;
  height: calc(100vh - 80px);
  min-height: 550px;
}

.overview { grid-area: overview; }
.trend { grid-area: trend; }
.recent { grid-area: recent; }
.revenue { grid-area: revenue; }
.schedule { grid-area: schedule; }

/* ===== STAT CARDS ===== */
.stat-card {
  border-radius: 16px;
  padding: 1.25rem;
  height: 100%;
  display: flex;
  flex-direction: column;
  transition: all 0.2s ease-in-out;
  color: #000;
  background: #fff;
  border: 1px solid #000;
  min-height: 180px;
}

.stat-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
}

.stat-body {
  font-size: clamp(1.5rem, 4vw, 2.2rem);
  font-weight: 700;
}

.stat-footer {
  font-size: clamp(0.6rem, 2vw, 0.75rem);
  color: #000;
}

.card-title {
  font-weight: 600;
  font-size: 1rem;
  margin-bottom: 0.5rem;
}

/* ===== STATUS BADGES ===== */
.status-badge {
  position: absolute;
  top: 8px;
  right: 10px;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 0.25rem 0.6rem;
  border-radius: 12px;
  color: #000;
}

.status-badge.pending {
  background-color: #ffe58f;
  color: #665c00;
}

.status-badge.progress {
  background-color: #bae0ff;
  color: #004085;
}

.status-badge.printed {
  background-color: #d9d9d9;
  color: #333;
}

.status-badge.signed {
  background-color: #b7eb8f;
  color: #135200;
}

/* ===== REQUEST OVERVIEW ===== */
.stat-card.overview {
  padding: 1rem 1rem 0.75rem 1rem;
}

.total-request-container {
  background: #e9ecef;
  border-radius: 10px;
  padding: 0.75rem;
  margin-bottom: 0.75rem;
  text-align: center;
  border: 1px solid #dee2e6;
  transition: all 0.2s ease;
}

.total-request-container:hover {
  background: #dee2e6;
  transform: translateY(-1px);
}

.total-request-container .stat-body {
  color: #2c3e50;
  font-size: 2.8rem;
  font-weight: 800;
  margin-bottom: 0.25rem;
}

.total-request-container .stat-footer {
  color: #555;
  font-size: 0.9rem;
  font-weight: 600;
  letter-spacing: 0.5px;
}

.overview-metrics {
  display: flex;
  justify-content: space-around;
  text-align: center;
  margin-top: 0.25rem;
  gap: 0.4rem;
}

.overview-metrics .metric {
  flex: 1;
  background: #f8f9fa;
  border-radius: 10px;
  padding: 0.4rem 0;
  border: 1px solid #dee2e6;
  transition: all 0.2s ease;
}

.overview-metrics .metric:hover {
  background: #e9ecef;
  transform: translateY(-1px);
}

.overview-metrics .stat-body {
  font-size: 2rem;
  font-weight: 700;
  color: #2c3e50;
}

.overview-metrics .stat-footer {
  font-size: 0.85rem;
  color: #555;
  font-weight: 500;
}

/* ===== REQUEST TREND ===== */
.stat-card.trend {
  padding: 1.25rem 1.25rem 1rem 1.25rem;
}

.chart-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
  gap: 1rem;
}

.chart-title-wrapper {
  flex: 1;
}

.chart-subtitle {
  font-size: 0.8rem;
  color: #6c757d;
  margin-top: 0.25rem;
}

.trend-indicator-top {
  background: #f8f9fa;
  border: 1px solid #e9ecef;
  border-radius: 8px;
  padding: 0.4rem 0.75rem;
  text-align: center;
  white-space: nowrap;
}

.trend-label {
  font-size: 0.7rem;
  color: #6c757d;
  font-weight: 500;
  display: block;
  margin-bottom: 0.1rem;
}

.trend-value {
  font-size: 0.85rem;
  font-weight: 600;
  color: #2563eb;
  display: block;
}

.chart-container {
  position: relative;
  height: 100%;
  width: 100%;
  min-height: 180px;
  flex-grow: 1;
}

/* ===== RECENT REQUESTS ===== */
.stat-card.recent {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.recent-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.btn-sort {
  background: #f8f9fa;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  padding: 0.4rem 0.6rem;
  color: #6c757d;
  transition: all 0.2s ease;
}

.btn-sort:hover {
  background: #e9ecef;
  color: #495057;
  transform: scale(1.05);
}

.compact-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.compact-item {
  display: flex;
  align-items: center;
  background: #f8f9fa;
  border: 1px solid #e9ecef;
  border-radius: 8px;
  padding: 0.75rem;
  text-decoration: none;
  color: #212529;
  transition: all 0.2s ease;
  position: relative;
  min-height: 60px;
}

.compact-item:hover {
  background: #e9ecef;
  border-color: #dee2e6;
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.compact-item .status-badge {
  position: static;
  margin-right: 0.75rem;
  font-size: 0.65rem;
  padding: 0.2rem 0.5rem;
  align-self: flex-start;
}

.item-content {
  flex: 1;
  min-width: 0;
}

.item-title {
  margin-bottom: 0.25rem;
}

.doc-name {
  font-weight: 600;
  font-size: 0.9rem;
  color: #212529;
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.item-details {
  display: flex;
  gap: 1rem;
  font-size: 0.75rem;
  color: #6c757d;
}

.item-details span {
  display: flex;
  align-items: center;
}

.item-details i {
  font-size: 0.7rem;
  opacity: 0.7;
}

.item-action {
  color: #6c757d;
  opacity: 0.6;
  transition: all 0.2s ease;
  margin-left: 0.5rem;
}

.compact-item:hover .item-action {
  opacity: 1;
  transform: translateX(2px);
  color: #495057;
}

/* ===== REVENUE SECTION ===== */
.total-revenue-container {
  background: #e9ecef;
  border-radius: 10px;
  padding: 0.75rem;
  margin-bottom: 0.75rem;
  text-align: center;
  border: 1px solid #dee2e6;
  transition: all 0.2s ease;
}

.total-revenue-container:hover {
  background: #dee2e6;
  transform: translateY(-1px);
}

.total-revenue-container .stat-body {
  color: #2c3e50;
  font-size: 2.8rem;
  font-weight: 800;
  margin-bottom: 0.25rem;
}

.total-revenue-container .stat-footer {
  color: #555;
  font-size: 0.9rem;
  font-weight: 600;
  letter-spacing: 0.5px;
}

.revenue-metrics {
  display: flex;
  justify-content: space-around;
  gap: 0.5rem;
  margin-top: 0.5rem;
}

.revenue-metric {
  flex: 1;
  background: #f8f9fa;
  border-radius: 8px;
  padding: 0.75rem 0.5rem;
  border: 1px solid #dee2e6;
  transition: all 0.2s ease;
  text-align: center;
}

.revenue-metric:hover {
  background: #e9ecef;
  transform: translateY(-1px);
}

.metric-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
}

.metric-label {
  font-size: 0.75rem;
  color: #6c757d;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.trend-indicator {
  display: flex;
  align-items: center;
  gap: 0.2rem;
  font-size: 0.7rem;
  font-weight: 600;
  padding: 0.2rem 0.4rem;
  border-radius: 12px;
}

.trend-indicator.positive {
  background: rgba(40, 167, 69, 0.1);
  color: #28a745;
}

.trend-indicator.negative {
  background: rgba(220, 53, 69, 0.1);
  color: #dc3545;
}

.trend-indicator i {
  font-size: 0.6rem;
}

.metric-value {
  font-size: 1.1rem;
  font-weight: 700;
  color: #2c3e50;
}

/* ===== SCHEDULE SECTION ===== */
.schedule-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.schedule-count .badge {
  font-size: 0.7rem;
  padding: 0.3rem 0.6rem;
}

.schedule-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.schedule-item {
  display: flex;
  align-items: center;
  background: #f8f9fa;
  border: 1px solid #e9ecef;
  border-radius: 10px;
  padding: 0.8rem;
  text-decoration: none;
  color: #212529;
  transition: all 0.3s ease;
  position: relative;
  min-height: 70px;
  animation: fadeInUp 0.4s ease-out;
}

.schedule-item:hover {
  background: #e9ecef;
  border-color: #dee2e6;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.schedule-item:nth-child(1) { animation-delay: 0.1s; }
.schedule-item:nth-child(2) { animation-delay: 0.2s; }
.schedule-item:nth-child(3) { animation-delay: 0.3s; }

.schedule-date {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border-radius: 8px;
  padding: 0.5rem;
  min-width: 50px;
  margin-right: 0.8rem;
  text-align: center;
}

.date-day {
  font-size: 1.2rem;
  font-weight: 700;
  line-height: 1;
}

.date-month {
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  line-height: 1;
  margin: 0.1rem 0;
}

.date-year {
  font-size: 0.6rem;
  opacity: 0.9;
  line-height: 1;
}

.schedule-details {
  flex: 1;
  display: flex;
  justify-content: space-between;
  align-items: center;
  min-width: 0;
}

.schedule-info {
  flex: 1;
  min-width: 0;
}

.schedule-title {
  font-size: 0.8rem;
  color: #6c757d;
  font-weight: 500;
  margin-bottom: 0.2rem;
}

.schedule-day {
  font-size: 0.9rem;
  font-weight: 600;
  color: #212529;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.schedule-slots {
  margin-left: 0.5rem;
}

.slots-indicator {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.3rem 0.6rem;
  border-radius: 20px;
  font-size: 0.75rem;
  font-weight: 600;
  white-space: nowrap;
}

.slots-indicator.high {
  background: rgba(40, 167, 69, 0.1);
  color: #28a745;
}

.slots-indicator.medium {
  background: rgba(255, 193, 7, 0.1);
  color: #ffc107;
}

.slots-indicator.low {
  background: rgba(220, 53, 69, 0.1);
  color: #dc3545;
}

.slots-indicator i {
  font-size: 0.7rem;
}

.schedule-action {
  color: #6c757d;
  opacity: 0.6;
  transition: all 0.3s ease;
  margin-left: 0.5rem;
  font-size: 0.8rem;
}

.schedule-item:hover .schedule-action {
  opacity: 1;
  transform: translateX(3px);
  color: #495057;
}

/* ===== EMPTY STATES ===== */
.empty-state, .schedule-empty {
  text-align: center;
  padding: 2rem 1rem;
  color: #6c757d;
}

.empty-state i, .empty-icon {
  font-size: 2rem;
  margin-bottom: 0.5rem;
  opacity: 0.5;
  display: block;
}

.empty-state span, .empty-title {
  font-size: 0.9rem;
}

.empty-icon {
  font-size: 2.5rem;
}

.empty-title {
  font-size: 1rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
}

.empty-subtitle {
  font-size: 0.8rem;
  opacity: 0.7;
}

.text-muted {
  padding: 1.5rem;
  text-align: center;
  color: #6c757d !important;
  font-style: italic;
}

/* ===== SCROLLBAR STYLING ===== */
#recentRequestsList, #scheduleList {
  flex-grow: 1;
  overflow-y: auto;
  margin-top: 0.5rem;
  max-height: 400px;
}

#recentRequestsList::-webkit-scrollbar,
#scheduleList::-webkit-scrollbar {
  width: 6px;
}

#recentRequestsList::-webkit-scrollbar-track,
#scheduleList::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 10px;
}

#recentRequestsList::-webkit-scrollbar-thumb,
#scheduleList::-webkit-scrollbar-thumb {
  background: #c1c1c1;
  border-radius: 10px;
}

#recentRequestsList::-webkit-scrollbar-thumb:hover,
#scheduleList::-webkit-scrollbar-thumb:hover {
  background: #a8a8a8;
}

/* ===== ANIMATIONS ===== */
@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* ===== RESPONSIVE DESIGN ===== */
@media (max-width: 1200px) {
  .dashboard-grid {
    grid-template-areas:
      "overview trend"
      "revenue schedule"
      "recent recent";
    grid-template-columns: 1fr 1fr;
    grid-template-rows: auto auto auto;
    height: auto;
    min-height: auto;
    padding: 0.25rem 0;
  }
  
  .stat-card {
    min-height: 160px;
  }
}

@media (max-width: 768px) {
  .dashboard-grid {
   grid-template-areas:
  "overview trend"
  "revenue schedule"
  "recent recent";
    grid-template-columns: 1fr;
    grid-template-rows: repeat(5, auto);
    gap: 1rem;
    padding: 0.25rem 0;
  }
  
  .overview-metrics {
    flex-direction: column;
    gap: 0.75rem;
  }
  
  .stat-body {
    font-size: 1.5rem;
  }
  
  .chart-container {
    height: 200px;
  }
  
  .stat-card {
    min-height: 140px;
  }
  
  .container {
    margin-top: 0.25rem !important;
  }
  
  .chart-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
  }
  
  .trend-indicator-top {
    align-self: flex-start;
  }
  
  .compact-item {
    padding: 0.6rem;
    min-height: 55px;
  }
  
  .item-details {
    flex-direction: column;
    gap: 0.25rem;
  }
  
  .doc-name {
    font-size: 0.85rem;
  }
  
  .status-badge {
    font-size: 0.6rem;
    padding: 0.15rem 0.4rem;
    margin-right: 0.5rem;
  }
  
  .revenue-metrics {
    flex-direction: column;
    gap: 0.4rem;
  }
  
  .revenue-metric {
    padding: 0.6rem 0.4rem;
  }
  
  .metric-value {
    font-size: 1rem;
  }
  
  .total-revenue-container .stat-body,
  .total-request-container .stat-body {
    font-size: 2.2rem;
  }
  
  .schedule-item {
    padding: 0.7rem;
    min-height: 65px;
  }
  
  .schedule-date {
    min-width: 45px;
    margin-right: 0.6rem;
    padding: 0.4rem;
  }
  
  .date-day {
    font-size: 1.1rem;
  }
  
  .date-month {
    font-size: 0.65rem;
  }
  
  .date-year {
    font-size: 0.55rem;
  }
  
  .schedule-details {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.3rem;
  }
  
  .schedule-slots {
    margin-left: 0;
    align-self: flex-start;
  }
  
  .slots-indicator {
    font-size: 0.7rem;
    padding: 0.25rem 0.5rem;
  }
}

@media (max-width: 480px) {
grid-template-areas:
  "overview"
  "trend"
  "revenue"
  "schedule"
  "recent";

  .recent-header {
    margin-bottom: 0.75rem;
  }
  
  .compact-list {
    gap: 0.4rem;
  }
  
  .item-action {
    display: none;
  }
  
  .metric-header {
    flex-direction: column;
    gap: 0.25rem;
    align-items: center;
  }
  
  .trend-indicator {
    font-size: 0.65rem;
  }
  
  .schedule-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
  }
  
  .schedule-count {
    align-self: flex-start;
  }
  
  .schedule-item {
    padding: 0.6rem;
  }
  
  .schedule-action {
    display: none;
  }
  
  .schedule-empty {
    padding: 1.5rem 1rem;
  }
  
  .empty-icon {
    font-size: 2rem;
  }
}



/* ===== ENHANCED MOBILE RESPONSIVENESS ===== */
@media (max-width: 768px) {
  .dashboard-grid {
    grid-template-areas:
      "overview trend"
      "revenue schedule"
      "recent recent";
    grid-template-columns: 1fr 1fr;
    grid-template-rows: auto auto auto;
    gap: 1rem;
    padding: 0.25rem 0;
    height: auto;
    min-height: auto;
  }
  
  .stat-card {
    min-height: 160px;
    padding: 1rem;
  }
  
  .stat-body {
    font-size: 1.6rem;
  }
  
  .total-request-container .stat-body,
  .total-revenue-container .stat-body {
    font-size: 2rem;
  }
  
  .overview-metrics {
    flex-direction: row;
    gap: 0.4rem;
  }
  
  .overview-metrics .stat-body {
    font-size: 1.6rem;
  }
  
  .chart-container {
    height: 150px;
  }
  
  .chart-header {
    flex-direction: row;
    align-items: center;
    gap: 0.5rem;
  }
  
  .trend-indicator-top {
    align-self: auto;
  }
  
  .compact-item {
    padding: 0.6rem;
    min-height: 55px;
  }
  
  .item-details {
    flex-direction: row;
    gap: 0.75rem;
  }
  
  .doc-name {
    font-size: 0.85rem;
  }
  
  .status-badge {
    font-size: 0.6rem;
    padding: 0.15rem 0.4rem;
    margin-right: 0.5rem;
  }
  
  .revenue-metrics {
    flex-direction: row;
    gap: 0.4rem;
  }
  
  .revenue-metric {
    padding: 0.6rem 0.4rem;
  }
  
  .metric-value {
    font-size: 0.9rem;
  }
  
  .schedule-item {
    padding: 0.7rem;
    min-height: 65px;
  }
  
  .schedule-date {
    min-width: 45px;
    margin-right: 0.6rem;
    padding: 0.4rem;
  }
  
  .date-day {
    font-size: 1.1rem;
  }
  
  .date-month {
    font-size: 0.65rem;
  }
  
  .date-year {
    font-size: 0.55rem;
  }
  
  .schedule-details {
    flex-direction: row;
    align-items: center;
    gap: 0;
  }
  
  .schedule-slots {
    margin-left: 0.5rem;
    align-self: auto;
  }
  
  .slots-indicator {
    font-size: 0.7rem;
    padding: 0.25rem 0.5rem;
  }
}

/* ===== SMALL MOBILE (480px and below) ===== */
@media (max-width: 480px) {
  .dashboard-grid {
    grid-template-areas:
      "overview"
      "trend"
      "revenue"
      "schedule"
      "recent";
    grid-template-columns: 1fr;
    grid-template-rows: repeat(5, auto);
    gap: 0.75rem;
  }
  
  .stat-card {
    min-height: 140px;
    padding: 0.75rem;
  }
  
  .stat-body {
    font-size: 1.5rem;
  }
  
  .total-request-container .stat-body,
  .total-revenue-container .stat-body {
    font-size: 1.8rem;
  }
  
  .overview-metrics {
    flex-direction: column;
    gap: 0.5rem;
  }
  
  .overview-metrics .stat-body {
    font-size: 1.5rem;
  }
  
  .chart-container {
    height: 140px;
  }
  
  .chart-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
  }
  
  .trend-indicator-top {
    align-self: flex-start;
  }
  
  .compact-item {
    padding: 0.5rem;
    min-height: 50px;
  }
  
  .item-details {
    flex-direction: column;
    gap: 0.2rem;
  }
  
  .item-action {
    display: none;
  }
  
  .revenue-metrics {
    flex-direction: column;
    gap: 0.3rem;
  }
  
  .metric-header {
    flex-direction: column;
    gap: 0.25rem;
    align-items: center;
  }
  
  .trend-indicator {
    font-size: 0.65rem;
  }
  
  .schedule-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
  }
  
  .schedule-count {
    align-self: flex-start;
  }
  
  .schedule-item {
    padding: 0.6rem;
  }
  
  .schedule-action {
    display: none;
  }
  
  .schedule-details {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.3rem;
  }
  
  .schedule-slots {
    margin-left: 0;
    align-self: flex-start;
  }
}

/* ===== TABLET OPTIMIZATION (769px - 1024px) ===== */
@media (min-width: 769px) and (max-width: 1024px) {
  .dashboard-grid {
    grid-template-areas:
      "overview trend"
      "revenue schedule"
      "recent recent";
    grid-template-columns: 1fr 1fr;
    grid-template-rows: auto auto auto;
    gap: 1.25rem;
  }
  
  .stat-card {
    min-height: 170px;
  }
  
  .stat-body {
    font-size: 1.8rem;
  }
}

/* ===== LANDSCAPE MOBILE OPTIMIZATION ===== */
@media (max-width: 768px) and (orientation: landscape) {
  .dashboard-grid {
    grid-template-areas:
      "overview trend"
      "revenue schedule"
      "recent recent";
    grid-template-columns: 1fr 1fr;
    grid-template-rows: auto auto auto;
    gap: 0.75rem;
  }
  
  .stat-card {
    min-height: 140px;
    padding: 0.75rem;
  }
  
  #recentRequestsList, 
  #scheduleList {
    max-height: 120px;
  }
}

/* ===== ENSURE CONTENT FITS MOBILE SCREENS ===== */
@media (max-width: 768px) {
  .container {
    padding: 0 0.75rem;
  }
  
  .stat-card.recent {
    min-height: 250px;
  }
}

/* ===== TOUCH FRIENDLY INTERACTIONS ===== */
@media (hover: none) and (pointer: coarse) {
  .stat-card:hover,
  .compact-item:hover,
  .schedule-item:hover,
  .total-request-container:hover,
  .total-revenue-container:hover,
  .overview-metrics .metric:hover,
  .revenue-metric:hover {
    transform: none;
    box-shadow: none;
  }
  
  .btn-sort:active {
    background: #e9ecef;
    transform: scale(0.95);
  }
}


</style>

<!-- LIFTED UP Container with minimal top margin -->
<div class="container">
  <div class="dashboard-grid">
<div class="stat-card overview">
  <div class="fw-bold mb-2">Request Overview</div>
  
  <!-- Total Request with Hierarchy Background -->
  <div class="total-request-container mb-2">
    <div class="stat-body"><?= $overview['total_requests'] ?></div>
    <div class="stat-footer">Total Requests</div>
  </div>

  <!-- Pending / In Progress / Ready -->
  <div class="overview-metrics">
    <div class="metric">
      <div class="stat-body"><?= $overview['pending'] ?></div>
      <div class="stat-footer">Pending</div>
    </div>
    <div class="metric">
      <div class="stat-body"><?= $overview['in_progress'] ?></div>
      <div class="stat-footer">In Progress</div>
    </div>
    <div class="metric">
      <div class="stat-body"><?= $overview['ready'] ?></div>
      <div class="stat-footer">Ready</div>
    </div>
  </div>
</div>

   <!-- 📈 Request Trend -->
<div class="stat-card trend">
  <div class="chart-header">
    <div class="chart-title-wrapper">
      <div class="fw-bold">Request Trend</div>
      <div class="chart-subtitle">Last 7 Days</div>
    </div>
    <div class="trend-indicator-top">
      <span class="trend-label">Weekly Activity</span>
      <span class="trend-value"><?= array_sum(array_values($chartData)) ?> completed</span>
    </div>
  </div>
  <div class="chart-container">
    <canvas id="requestTrendChart"></canvas>
  </div>
</div>

    <!-- 📄 Recent Request -->
   <div class="stat-card recent">
  <div class="recent-header">
    <div class="fw-bold">Recent Requests</div>
    <div class="sort-controls">
      <button id="sortToggleBtn" class="btn btn-sm btn-sort" title="Toggle sort order">
        <i class="fa fa-sort-amount-desc"></i>
      </button>
    </div>
  </div>
  <div class="list-group compact-list" id="recentRequestsList">
    <?php if ($recent_requests): ?>
      <?php foreach ($recent_requests as $req): ?>
        <a href="request_history.php?request_id=<?= urlencode($req['request_id']) ?>" 
           class="list-item compact-item"
           data-date="<?= htmlspecialchars($req['date_requested']) ?>">
          
          <!-- Status Badge -->
          <?php
            $status = strtolower($req['request_status']);
            $badgeClass = match($status) {
              'pending' => 'status-badge pending',
              'in-progress', 'approved' => 'status-badge progress',
              'printed' => 'status-badge printed',
              'signed' => 'status-badge signed',
              default => 'status-badge',
            };
          ?>
          <span class="<?= $badgeClass ?>"><?= ucfirst($status) ?></span>

          <!-- Main Content -->
          <div class="item-content">
            <div class="item-title">
              <span class="doc-name"><?= htmlspecialchars($req['doc_name']) ?></span>
            </div>
            <div class="item-details">
              <span class="schedule">
                <i class="fa fa-calendar-alt me-1"></i>
                <?= $req['schedule_date'] ? date('M d, Y', strtotime($req['schedule_date'])) : 'Not scheduled' ?>
              </span>
              <span class="requested">
                <i class="fa fa-clock me-1"></i>
                <?= date('M d, Y', strtotime($req['date_requested'])) ?>
              </span>
            </div>
          </div>

          <!-- Action Arrow -->
          <div class="item-action">
            <i class="fa fa-chevron-right"></i>
          </div>
        </a>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state">
        <i class="fa fa-inbox"></i>
        <span>No recent requests</span>
      </div>
    <?php endif; ?>
  </div>
</div>

    <!-- 💰 Revenue -->
<!-- 💰 Revenue -->
<div class="stat-card revenue">
  <div class="fw-bold mb-2">My Total Spending</div>
  
  <!-- Total Revenue with Hierarchy -->
  <div class="total-revenue-container mb-2">

    <div class="stat-body">₱<?= formatNumberShort($revenue['total_spent'] ?? 0, 2) ?></div>
    <div class="stat-footer"><?= $revenue['total_docs'] ?> documents requested</div>
  </div>

  <!-- Weekly & Monthly Revenue -->
  <div class="revenue-metrics">
    <div class="revenue-metric">
      <div class="metric-header">
        <span class="metric-label">Weekly</span>
        <span class="trend-indicator <?= ($weekly_revenue['weekly_spent'] ?? 0) > 0 ? 'positive' : 'negative' ?>">
          <i class="fas fa-arrow-<?= ($weekly_revenue['weekly_spent'] ?? 0) > 0 ? 'up' : 'down' ?>"></i>
          <span><?= ($weekly_revenue['weekly_spent'] ?? 0) > 0 ? '12%' : '0%' ?></span>
        </span>
      </div>
      <div class="metric-value">₱<?= formatNumberShort($weekly_revenue['weekly_spent'] ?? 0, 2) ?></div>
    </div>
    
    <div class="revenue-metric">
      <div class="metric-header">
        <span class="metric-label">Monthly</span>
        <span class="trend-indicator <?= ($monthly_revenue['monthly_spent'] ?? 0) > 0 ? 'positive' : 'negative' ?>">
          <i class="fas fa-arrow-<?= ($monthly_revenue['monthly_spent'] ?? 0) > 0 ? 'up' : 'down' ?>"></i>
          <span><?= ($monthly_revenue['monthly_spent'] ?? 0) > 0 ? '8%' : '0%' ?></span>
        </span>
      </div>
      <div class="metric-value">₱<?= formatNumberShort($monthly_revenue['monthly_spent'] ?? 0, 2) ?></div>
    </div>
  </div>
</div>

    <!-- 📅 Next Schedule -->
<div class="stat-card schedule">
  <div class="schedule-header">
    <div class="fw-bold">Available Schedule</div>
    <div class="schedule-count">
      <span class="badge bg-primary"><?= count($active_schedule_list) ?> available</span>
    </div>
  </div>
  <div class="schedule-list" id="scheduleList">
    <?php if ($active_schedule_list): ?>
      <?php foreach ($active_schedule_list as $sched): ?>
        <a href="request_document.php?schedule_id=<?= urlencode($sched['schedule_id']) ?>" 
           class="schedule-item">
          
          <!-- Date Section -->
          <div class="schedule-date">
            <div class="date-day"><?= date('d', strtotime($sched['schedule_date'])) ?></div>
            <div class="date-month"><?= date('M', strtotime($sched['schedule_date'])) ?></div>
            <div class="date-year"><?= date('Y', strtotime($sched['schedule_date'])) ?></div>
          </div>

          <!-- Schedule Details -->
          <div class="schedule-details">
            <div class="schedule-info">
              <div class="schedule-title">Available Schedule</div>
              <div class="schedule-day"><?= date('l', strtotime($sched['schedule_date'])) ?></div>
            </div>
            <div class="schedule-slots">
              <div class="slots-indicator <?= $sched['schedule_slots'] > 5 ? 'high' : ($sched['schedule_slots'] > 2 ? 'medium' : 'low') ?>">
                <i class="fas fa-users"></i>
                <span><?= $sched['schedule_slots'] ?> slots</span>
              </div>
            </div>
          </div>

          <!-- Action Arrow -->
          <div class="schedule-action">
            <i class="fas fa-chevron-right"></i>
          </div>
        </a>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="schedule-empty">
        <div class="empty-icon">
          <i class="fas fa-calendar-times"></i>
        </div>
        <div class="empty-text">
          <div class="empty-title">No upcoming schedules</div>
          <div class="empty-subtitle">Check back later for new schedules</div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('requestTrendChart').getContext('2d');


const trendData = <?= json_encode(array_values($chartData)) ?>;
const totalThisWeek = trendData.reduce((a, b) => a + b, 0);
const averageThisWeek = totalThisWeek / trendData.length;




new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode(array_keys($chartData)) ?>,
    datasets: [{
      label: 'Completed Requests',
      data: trendData,
      borderColor: '#2563eb',
      backgroundColor: 'rgba(37, 99, 235, 0.02)',
      borderWidth: 2,
      fill: true,
      tension: 0.4,
      pointBackgroundColor: '#2563eb',
      pointBorderColor: '#ffffff',
      pointBorderWidth: 2,
      pointRadius: 4,
      pointHoverRadius: 6
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { 
        display: false 
      },
      tooltip: {
        backgroundColor: 'rgba(0, 0, 0, 0.8)',
        titleColor: '#ffffff',
        bodyColor: '#ffffff',
        borderColor: '#2563eb',
        borderWidth: 1,
        cornerRadius: 6,
        displayColors: false,
        callbacks: {
          label: function(context) {
            return `Completed: ${context.parsed.y} request${context.parsed.y !== 1 ? 's' : ''}`;
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
          color: '#6b7280',
          font: {
            size: 11
          }
        }
      },
      y: { 
        beginAtZero: true,
        grid: {
          color: 'rgba(0, 0, 0, 0.05)',
          drawBorder: false
        },
        ticks: {
          color: '#6b7280',
          font: {
            size: 11
          },
          stepSize: 1,
          precision: 0
        },
        border: {
          display: false
        }
      } 
    },
    interaction: {
      intersect: false,
      mode: 'index'
    },
    elements: {
      line: {
        tension: 0.4
      }
    }
  }
});

// Update trend indicator
document.addEventListener

// Sorting functionality
let sortOrder = 'desc';

function sortRequests(order = 'desc') {
  const list = document.getElementById('recentRequestsList');
  const items = Array.from(list.querySelectorAll('.compact-item'));

  items.sort((a, b) => {
    const dateA = new Date(a.dataset.date);
    const dateB = new Date(b.dataset.date);
    return order === 'asc' ? dateA - dateB : dateB - dateA;
  });

  list.innerHTML = '';
  items.forEach(i => list.appendChild(i));
}

document.getElementById('sortToggleBtn').addEventListener('click', function() {
  sortOrder = sortOrder === 'desc' ? 'asc' : 'desc';
  sortRequests(sortOrder);

  // Update icon based on sort order
  const icon = this.querySelector('i');
  if (sortOrder === 'desc') {
    icon.className = 'fa fa-sort-amount-desc';
    this.title = 'Sort: Newest to Oldest';
  } else {
    icon.className = 'fa fa-sort-amount-asc';
    this.title = 'Sort: Oldest to Newest';
  }
});

document.addEventListener('DOMContentLoaded', () => sortRequests('desc'));
</script>

<?php include 'footer.php'; ?>