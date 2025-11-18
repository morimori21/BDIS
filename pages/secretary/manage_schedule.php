<?php 
require_once '../../includes/config.php';
global $pdo;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_schedule'])) {
    $date = $_POST['date'];
    $slots = intval($_POST['slots']);

    // Prevent 0 or negative slots
    if ($slots < 0) {
    $message = "<div class='alert alert-warning'>Slots cannot be negative.</div>";
    } else {
        // Check if date already exists
        $check = $pdo->prepare("SELECT * FROM schedule WHERE schedule_date = ?");
        $check->execute([$date]);

        if ($check->rowCount() > 0) {
            // Update existing schedule
            $stmt = $pdo->prepare("UPDATE schedule SET schedule_slots = ? WHERE schedule_date = ?");
            $stmt->execute([$slots, $date]);
            $message = "<div class='alert alert-success'>Schedule updated.</div>";
        } else {
            // Insert new schedule
            $stmt = $pdo->prepare("INSERT INTO schedule (schedule_date, schedule_slots) VALUES (?, ?)");
            $stmt->execute([$date, $slots]);
            $message = "<div class='alert alert-success'>Schedule added.</div>";
        }

        // Reload page to show updated calendar
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}



// LETTTS AJAX!!!!!
if (!isset($_GET['ajax_calendar'])) {
    include 'header.php';
} else {
    // make sure $pdo exists for AJAX calls too
    require_once '../../includes/config.php'; 
    global $pdo;
}

if (isset($_GET['ajax_calendar'])) {
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    $year  = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

    echo build_calendar($month, $year, true);
    exit;
}

function build_calendar($month, $year) {
    global $pdo;
    $daysOfWeek = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    $firstDayOfMonth = mktime(0, 0, 0, $month, 1, $year);
    $numberDays = date('t', $firstDayOfMonth);
    $dateComponents = getdate($firstDayOfMonth);
    $monthName = $dateComponents['month'];
    $dayOfWeek = $dateComponents['wday'];
    $today = date('Y-m-d');
    //CONTROLS
    $prev_month = date('n', mktime(0, 0, 0, $month - 1, 1, $year));
    $prev_year  = date('Y', mktime(0, 0, 0, $month - 1, 1, $year));
    $next_month = date('n', mktime(0, 0, 0, $month + 1, 1, $year));
    $next_year  = date('Y', mktime(0, 0, 0, $month + 1, 1, $year));
    //STRUCTURES
    $calendar = "
        <center>
            <h2>$monthName $year</h2>
            <a href='#' class='btn btn-sm btn-primary calendar-nav' data-month='$prev_month' data-year='$prev_year'>Prev</a>
            <a href='#' class='btn btn-sm btn-primary calendar-nav' data-month='".date('n')."' data-year='".date('Y')."'>Current</a>
            <a href='#' class='btn btn-sm btn-primary calendar-nav' data-month='$next_month' data-year='$next_year'>Next</a>
        </center><br>
        <table class='table table-bordered text-center'>
        <thead><tr>";

    //DATE IN THE CALENDAR
    foreach ($daysOfWeek as $day) {
        $calendar .= "<th class='bg-primary text-white'>$day</th>";
    }
    $calendar .= "</tr></thead><tbody><tr>";

    if ($dayOfWeek > 0) {
        for ($k = 0; $k < $dayOfWeek; $k++) {
            $calendar .= "<td class='empty'></td>";
        }
    }

    $currentDay = 1;
    while ($currentDay <= $numberDays) {
        if ($dayOfWeek == 7) {
            $dayOfWeek = 0;
            $calendar .= "</tr><tr>";
        }

        $monthPadded = str_pad($month, 2, "0", STR_PAD_LEFT);
        $dayPadded = str_pad($currentDay, 2, "0", STR_PAD_LEFT);
        $date = "$year-$monthPadded-$dayPadded";

        $stmt = $pdo->prepare("SELECT schedule_id, schedule_slots FROM schedule WHERE schedule_date = ? LIMIT 1");
        $stmt->execute([$date]);
        $row = $stmt->fetch();

        $classes = ["selectable-day"];
        $slotsText = "";
        $disabled = false;

        if ($date < $today) {
    $classes[] = "day-past";
    $classes[] = "disabled-day"; // <- MUST be separate class
}
elseif ($row) {
    $slots = (int)$row['schedule_slots'];
    if ($slots === 0) {
    $classes[] = "day-full";
    $slotsText = "<small class='text-danger'>Full</small>";
    $disabled = false; // allow admin to edit
} else {
    $classes[] = "day-available";
    $slotsText = "<small>{$slots} slots</small>";
    $disabled = false;
}

}
 else {
    $classes[] = "day-noschedule";
    $disabled = false;
}

// Add a "disabled-day" class if the day is non-clickable
if ($disabled) {
    $classes[] = "disabled-day";
}

        $disabledAttr = $disabled ? "disabled" : "";
        $scheduleId = $row ? $row['schedule_id'] : "";

        $calendar .= "
    <td class='".implode(" ", $classes)."' 
        data-date='$date' 
        data-id='$scheduleId'>
        <div class='p-2'>
            <h6 class='mb-0'>$currentDay</h6>
            $slotsText
        </div>
    </td>
";


        $currentDay++;
        $dayOfWeek++;
    }

    if ($dayOfWeek != 7) {
        for ($i = 0; $i < 7 - $dayOfWeek; $i++) {
            $calendar .= "<td class='empty'></td>";
        }
    }

    $calendar .= "</tr></tbody></table>";
    return $calendar;
}

?>

<?php
// Ensure the schedules table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schedule (
        schedule_id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL,
        slots INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage();
}
?>
<style>
    .overlay {
  display: none;
  position: fixed;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: rgba(0,0,0,0.55);
  backdrop-filter: blur(3px);
  z-index: 9999;
  align-items: center;
  justify-content: center;
  transition: opacity 0.25s ease;
}

.overlay.show {
  display: flex;
  opacity: 1;
}
.overlay-content {
  width: 90%;
  max-width: 800px;
  min-height: 500px;   
  max-height: 600px;  
  border-radius: 1rem;
  overflow: hidden;   
  display: flex;
  flex-direction: column;
  background-color: #fff;
}
.overlay-body {
  flex: 1 1 auto;
  padding: 1rem;
}
.selectable-row {
  cursor: pointer;
  transition: background-color 0.15s ease;
}
.selectable-row:hover {
  background-color: #e8f2ff;
}

.selectable-day {
  cursor: pointer;
  transition: background-color 0.2s ease, transform 0.1s ease;
  border-radius: 0.5rem;
}

.selectable-day:hover:not(.disabled) {
  transform: scale(1.05);
  box-shadow: 0 0 5px rgba(0,0,0,0.15);
}


.day-past {
  background-color: #d6d8db !important;
  color: #555;
  cursor: not-allowed;
}


.day-noschedule {
  background-color: #ffffff !important;
  color: #999;
  cursor: not-allowed;
}


.day-full {
  background-color: #f5c6cb !important;
  color: #721c24;
  cursor: not-allowed;
}


.day-available {
  background-color: #d4edda !important;
  color: #155724;
}


.day-today {
  border: 2px solid #007bff !important;
}
#overlayCalendarWrapper, 
#overlayCalendarWrapper * {
  user-select: none;
  -webkit-user-select: none;
  -moz-user-select: none;
  -ms-user-select: none;
}

.selected-day {
    border: 2px solid #ffc107 !important;
}
</style>

<div class="container">
    <h2>Manage Schedule</h2>
    <p>Set dates for document pickup. Residents can select from available slots.</p>
<div id="calendarWrapper">
    <?php
    $dateComponents = getdate();
    $month = $dateComponents['mon'];
    $year  = $dateComponents['year'];
    echo build_calendar($month, $year);
    ?>
</div>

 <!-- Overlay Modal add new schedule -->
    <div class="modal fade" id="addScheduleModal" tabindex="-1" aria-labelledby="addScheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addScheduleModalLabel">Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Date</label>
                            <input type="date" name="date" class="form-control" required readonly style="pointer-events: none;">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Number of Slots</label>
                            <input type="number" name="slots" class="form-control" placeholder="Number of slots" required>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" name="add_schedule" class="btn btn-success">Save Schedule</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  function loadCalendar(month, year) {
    const wrapper = document.getElementById('calendarWrapper');
    wrapper.innerHTML = '<div class="text-center p-3">Loading...</div>';

    fetch('<?php echo $_SERVER["PHP_SELF"]; ?>?ajax_calendar=1&month=' + month + '&year=' + year, {
      credentials: 'same-origin'
    })
    .then(resp => resp.text())
    .then(html => {
      wrapper.innerHTML = html;
      attachNavHandlers();
    })
    .catch(err => {
      wrapper.innerHTML = '<div class="text-danger p-3">Failed to load calendar.</div>';
      console.error(err);
    });
  }

  function attachNavHandlers() {
    document.querySelectorAll('#calendarWrapper .calendar-nav').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        loadCalendar(this.dataset.month, this.dataset.year);
      });
    });
  }

  // Initialize nav handlers
  attachNavHandlers();
});
</script>




    <?php

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_schedule'])) {
        $date = $_POST['date'];
        $slots = intval($_POST['slots']);

        // Check if date already exists
        $check = $pdo->prepare("SELECT * FROM schedule WHERE schedule_date = ?");
        $check->execute([$date]);
        if ($check->rowCount() > 0) {
            echo "<div class='alert alert-warning'>Date already exists.</div>";
        } else {
            $stmt = $pdo->prepare("INSERT INTO schedule (schedule_date, schedule_slots) VALUES (?, ?)");
            $stmt->execute([$date, $slots]);
            echo "<div class='alert alert-success'>Schedule added.</div>";
        }
    }

    // Delete a schedule
    if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
        $del = $_GET['delete'];
        $stmt = $pdo->prepare("DELETE FROM schedule WHERE schedule_id = ?");
        $stmt->execute([$del]);
        echo "<div class='alert alert-success'>Schedule removed.</div>";
    }
    ?>

   
    <!-- <h4>Upcoming Schedules</h4>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Date</th>
                <th>Slots</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $stmt = $pdo->query("SELECT * FROM schedule WHERE schedule_date >= CURDATE() ORDER BY schedule_date ASC");
            while ($row = $stmt->fetch()) {
                $delUrl = '?delete=' . $row['schedule_id'];
                $delConfirm = "return confirm('Delete this schedule?')";
                echo "<tr>
                    <td>{$row['schedule_date']}</td>
                    <td>{$row['schedule_slots']}</td>
                    <td><a href='{$delUrl}' class='btn btn-sm btn-danger' onclick=\"{$delConfirm}\">Delete</a></td>
                </tr>";
            }
            ?>
        </tbody>
    </table> -->
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const calendarWrapper = document.getElementById("calendarWrapper");
    const modalEl = document.getElementById('addScheduleModal');
    const modal = new bootstrap.Modal(modalEl);
    const dateInput = modalEl.querySelector('input[name="date"]');
    const slotsInput = modalEl.querySelector('input[name="slots"]'); 

    // Function to attach click handlers to selectable days
    function attachDayClickHandlers() {
    calendarWrapper.querySelectorAll('.selectable-day').forEach(cell => {
    if (cell.classList.contains('disabled-day')) return;

    cell.addEventListener('click', () => {
        calendarWrapper.querySelectorAll('.selectable-day')
            .forEach(c => c.classList.remove('selected-day'));
        cell.classList.add('selected-day');

        const date = cell.dataset.date;
        dateInput.value = date; // fill date readonly

        const slotsText = cell.querySelector('small')?.textContent || '';
        if(slotsText.includes('Full')) {
            slotsInput.value = 0;
        } else {
            const slots = slotsText.match(/\d+/);
            slotsInput.value = slots ? slots[0] : '';
        }


        modal.show();
    });
});

}

    // Reload calendar via AJAX
    function loadCalendar(month, year) {
        calendarWrapper.innerHTML = '<div class="text-center p-3">Loading...</div>';

        fetch(`<?php echo $_SERVER["PHP_SELF"]; ?>?ajax_calendar=1&month=${month}&year=${year}`)
            .then(resp => resp.text())
            .then(html => {
                calendarWrapper.innerHTML = html;
                attachNavHandlers();
                attachDayClickHandlers();
            })
            .catch(err => {
                calendarWrapper.innerHTML = '<div class="text-danger p-3">Failed to load calendar.</div>';
                console.error(err);
            });
    }

    // Attach prev/next/current buttons
    function attachNavHandlers() {
        calendarWrapper.querySelectorAll('.calendar-nav').forEach(btn => {
            btn.addEventListener('click', e => {
                e.preventDefault();
                loadCalendar(btn.dataset.month, btn.dataset.year);
            });
        });
    }

    attachNavHandlers();
    attachDayClickHandlers();
});
</script>
<?php include 'footer.php'; ?>