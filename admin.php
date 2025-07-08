<?php

//password protection 
session_start();
if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}
// Error reporting and display
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database Connection and Setup
try {
    // Check if SQLite3 extension is enabled
    if (!class_exists('SQLite3')) {
        throw new Exception('SQLite3 extension is not enabled. Please enable it in php.ini');
    }

    // Open database connection
    $conn = new SQLite3('rfid_system.db');
    $conn->enableExceptions(true);

    // Create tables with improved error handling
    $conn->exec("PRAGMA foreign_keys = ON");

    // Users Table
    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        uid TEXT UNIQUE NOT NULL, 
        name TEXT NOT NULL
    )");

    // Attendance Table with more detailed tracking
    $conn->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        uid TEXT NOT NULL, 
        scan_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        scan_type TEXT CHECK(scan_type IN ('check-in', 'check-out')) NOT NULL,
        location TEXT,
        notes TEXT,
        FOREIGN KEY (uid) REFERENCES users(uid) ON DELETE CASCADE
    )");

    // Pre-calculated work hours table
    $conn->exec("CREATE TABLE IF NOT EXISTS daily_work_hours (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL,
        work_date DATE NOT NULL,
        total_hours REAL NOT NULL,
        first_check_in DATETIME,
        last_check_out DATETIME,
        UNIQUE(uid, work_date),
        FOREIGN KEY (uid) REFERENCES users(uid) ON DELETE CASCADE
    )");

    // Create indexes for performance
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_attendance_uid_scantime ON attendance(uid, scan_time)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_attendance_scantime ON attendance(scan_time)");

} catch (Exception $e) {
    die("Database Initialization Error: " . $e->getMessage());
}

// Function to get currently clocked-in users
function getCurrentlyClockedInUsers($conn) {
    $query = "
        WITH latest_scans AS (
            SELECT 
                uid,
                scan_type,
                scan_time,
                ROW_NUMBER() OVER (PARTITION BY uid ORDER BY scan_time DESC) as rn
            FROM attendance
            WHERE date(scan_time) = date('now')
        )
        SELECT 
            u.name,
            u.uid,
            ls.scan_time as last_scan_time,
            ls.scan_type
        FROM users u
        JOIN latest_scans ls ON u.uid = ls.uid
        WHERE ls.rn = 1 AND ls.scan_type = 'check-in'
        ORDER BY ls.scan_time DESC
    ";
    
    $result = $conn->query($query);
    $clocked_in_users = [];
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $clocked_in_users[] = $row;
    }
    
    return $clocked_in_users;
}

// Function to update pre-calculated daily work hours
function updateDailyWorkHours($conn) {
    // Clear existing data to recalculate
    $conn->exec("DELETE FROM daily_work_hours");

    $query = "
        WITH daily_attendance AS (
            SELECT 
                uid, 
                date(scan_time) as work_date,
                MIN(CASE WHEN scan_type = 'check-in' THEN scan_time END) as first_check_in,
                MAX(CASE WHEN scan_type = 'check-out' THEN scan_time END) as last_check_out
            FROM 
                attendance
            GROUP BY 
                uid, work_date
        )
        INSERT INTO daily_work_hours (uid, work_date, total_hours, first_check_in, last_check_out)
        SELECT 
            da.uid,
            da.work_date,
            ROUND(JULIANDAY(da.last_check_out) - JULIANDAY(da.first_check_in)) * 24 as total_hours,
            da.first_check_in,
            da.last_check_out
        FROM 
            daily_attendance da
    ";

    $conn->exec($query);
}

// Enhanced Attendance Export Function
function exportAttendanceReport($conn, $start_date = null, $end_date = null) {
    // If no pre-calculation exists, update first
    $check_table = $conn->query("SELECT COUNT(*) as count FROM daily_work_hours")->fetchArray();
    if ($check_table['count'] == 0) {
        updateDailyWorkHours($conn);
    }

    $query = "
        SELECT 
            dwh.work_date,
            u.name,
            u.uid,
            dwh.first_check_in,
            dwh.last_check_out,
            dwh.total_hours
        FROM 
            daily_work_hours dwh
        JOIN 
            users u ON dwh.uid = u.uid
    ";

    // Add date range filtering
    $conditions = [];
    $params = [];

    if ($start_date) {
        $conditions[] = "dwh.work_date >= ?";
        $params[] = $start_date;
    }
    if ($end_date) {
        $conditions[] = "dwh.work_date <= ?";
        $params[] = $end_date;
    }

    if (!empty($conditions)) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }

    $query .= " ORDER BY dwh.work_date DESC, u.name";

    // Prepare and execute query with parameters
    $stmt = $conn->prepare($query);
    
    // Bind parameters if they exist
    foreach ($params as $i => $param) {
        $stmt->bindValue($i + 1, $param, SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    $daily_hours = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $daily_hours[] = $row;
    }

    return $daily_hours;
}

// Handle Form Submissions
try {
    // Add New User
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"])) {
        switch ($_POST["action"]) {
            case "add_user":
                $stmt = $conn->prepare("INSERT INTO users (uid, name) VALUES (:uid, :name)");
                $stmt->bindValue(':uid', $_POST["new_uid"], SQLITE3_TEXT);
                $stmt->bindValue(':name', $_POST["new_name"], SQLITE3_TEXT);
                $stmt->execute();
                break;

            // Delete User
            case "delete_user":
                $stmt = $conn->prepare("DELETE FROM users WHERE uid = :uid");
                $stmt->bindValue(':uid', $_POST["uid"], SQLITE3_TEXT);
                $stmt->execute();
                break;

            // Log Attendance
            case "log_attendance":
                $stmt = $conn->prepare("INSERT INTO attendance (uid, scan_type, location, notes) VALUES (:uid, :scan_type, :location, :notes)");
                $stmt->bindValue(':uid', $_POST['scan_uid'], SQLITE3_TEXT);
                $stmt->bindValue(':scan_type', $_POST['scan_type'], SQLITE3_TEXT);
                $stmt->bindValue(':location', $_POST['location'] ?? null, SQLITE3_TEXT);
                $stmt->bindValue(':notes', $_POST['notes'] ?? null, SQLITE3_TEXT);
                $stmt->execute();
                break;

            // Export CSV
            case "export_csv":
                $start_date = $_POST['start_date'] ?? null;
                $end_date = $_POST['end_date'] ?? null;

                // Validate date range
                if ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
                    echo "Error: Start date must be before or equal to end date.";
                    exit;
                }

                $daily_hours = exportAttendanceReport($conn, $start_date, $end_date);

                // If no data found
                if (empty($daily_hours)) {
                    echo "No attendance records found for the selected date range.";
                    exit;
                }

                header('Content-Type: text/csv; charset=utf-8');
                $filename = 'attendance_report_';
                if ($start_date && $end_date) {
                    $filename .= $start_date . '_to_' . $end_date;
                } else {
                    $filename .= date('Y-m-d');
                }
                $filename .= '.csv';
                header('Content-Disposition: attachment; filename=' . $filename);
                
                $output = fopen('php://output', 'w');
                
                // CSV Headers - Fixed: Added escape parameter
                fputcsv($output, [
                    'Date', 
                    'Name', 
                    'UID', 
                    'Check-In Time', 
                    'Check-Out Time', 
                    'Total Hours Worked'
                ], ',', '"', '\\');

                // Write data rows - Fixed: Added escape parameter
                foreach ($daily_hours as $entry) {
                    fputcsv($output, [
                        $entry['work_date'],
                        $entry['name'],
                        $entry['uid'],
                        $entry['first_check_in'],
                        $entry['last_check_out'],
                        $entry['total_hours']
                    ], ',', '"', '\\');
                }

                fclose($output);
                exit();
        }
    }
} catch (Exception $e) {
    error_log("Submission Error: " . $e->getMessage());
}

// Fetch Users and Attendance Logs
$users = $conn->query("SELECT * FROM users");
$attendance_logs = $conn->query("SELECT a.*, u.name FROM attendance a LEFT JOIN users u ON a.uid = u.uid ORDER BY a.scan_time DESC");

// Get currently clocked-in users
$clocked_in_users = getCurrentlyClockedInUsers($conn);
?>

<!DOCTYPE html>
<html>
<head>
    <title>RFID Attendance Management System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            font-family: Arial, sans-serif; 
            max-width: 1000px; 
            margin: 0 auto; 
            padding: 20px; 
        }
        form { 
            background: #f4f4f4; 
            padding: 20px; 
            margin-bottom: 20px; 
            border-radius: 5px; 
        }
        input, select { 
            margin: 5px 0; 
            padding: 8px; 
            width: 100%; 
            box-sizing: border-box; 
        }
        button { 
            background-color: #4CAF50; 
            color: white; 
            border: none; 
            padding: 10px 20px; 
            margin: 10px 0; 
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        th, td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            text-align: left; 
        }
        th { 
            background-color: #f2f2f2; 
        }
        .date-range-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .date-range-form label {
            margin-right: 10px;
        }
        .delete-btn {
            background: none;
            border: none;
            color: red;
            cursor: pointer;
            padding: 5px;
        }
        .delete-btn:hover {
            color: darkred;
        }
        .clocked-in-section {
            background: #e8f5e8;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            border-left: 4px solid #4CAF50;
        }
        .clocked-in-count {
            font-weight: bold;
            color: #2e7d32;
        }
        .no-clocked-in {
            color: #666;
            font-style: italic;
        }
        .refresh-btn {
            background-color: #2196F3;
            color: white;
            border: none;
            padding: 8px 16px;
            margin-left: 10px;
            border-radius: 4px;
            cursor: pointer;
        }
        .refresh-btn:hover {
            background-color: #1976D2;
        }
    </style>
</head>
<body>
    <h1>RFID Attendance Management System</h1>

    <!-- Currently Clocked-In Users Section -->
    <div class="clocked-in-section">
        <h2>
            Currently Clocked-In Users 
            <span class="clocked-in-count">(<?php echo count($clocked_in_users); ?>)</span>
            <button class="refresh-btn" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </h2>
        
        <?php if (count($clocked_in_users) > 0): ?>
            <table>
                <tr>
                    <th>Name</th>
                    <th>UID</th>
                    <th>Check-In Time</th>
                    <th>Hours Worked Today</th>
                </tr>
                <?php foreach ($clocked_in_users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                        <td><?php echo htmlspecialchars($user['uid']); ?></td>
                        <td><?php echo htmlspecialchars($user['last_scan_time']); ?></td>
                        <td>
                            <?php 
                            // Calculate hours worked so far today
                            $check_in_time = new DateTime($user['last_scan_time']);
                            $current_time = new DateTime();
                            $interval = $check_in_time->diff($current_time);
                            $hours_worked = $interval->h + ($interval->days * 24) + ($interval->i / 60);
                            echo number_format($hours_worked, 2) . " hours";
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="no-clocked-in">No users are currently clocked in.</p>
        <?php endif; ?>
    </div>

    <!-- Add User Form -->
    <form method="POST">
        <h2>Add New User</h2>
        <input type="hidden" name="action" value="add_user">
        <input type="text" name="new_uid" placeholder="RFID UID" required>
        <input type="text" name="new_name" placeholder="Full Name" required>
        <button type="submit">Add User</button>
    </form>

    <!-- Attendance Logging Form -->
    <form method="POST">
        <h2>Log Attendance</h2>
        <input type="hidden" name="action" value="log_attendance">
        <select name="scan_uid" required>
            <option value="">Select User</option>
            <?php 
            $users_reset = $conn->query("SELECT * FROM users");
            while ($row = $users_reset->fetchArray(SQLITE3_ASSOC)) {
                echo "<option value='{$row['uid']}'>{$row['name']} ({$row['uid']})</option>";
            }
            ?>
        </select>
        <select name="scan_type" required>
            <option value="">Select Scan Type</option>
            <option value="check-in">Check-In</option>
            <option value="check-out">Check-Out</option>
        </select>
        <input type="text" name="location" placeholder="Location (Optional)">
        <input type="text" name="notes" placeholder="Notes (Optional)">
        <button type="submit">Log Attendance</button>
    </form>

    <!-- Export Attendance Hours with Date Range -->
    <form method="POST">
        <h2>Download Detailed Attendance Report</h2>
        <input type="hidden" name="action" value="export_csv">
        <div class="date-range-form">
            <label for="start_date">Start Date:</label>
            <input type="date" name="start_date" id="start_date">
            
            <label for="end_date">End Date:</label>
            <input type="date" name="end_date" id="end_date">
            
            <button type="submit">Download CSV Report</button>
        </div>
    </form>

    <!-- Registered Users List -->
    <h2>Registered Users</h2>
    <table>
        <tr>
            <th>Name</th>
            <th>UID</th>
            <th>Actions</th>
        </tr>
        <?php 
        $users_reset = $conn->query("SELECT * FROM users");
        while ($row = $users_reset->fetchArray(SQLITE3_ASSOC)) {
            echo "<tr>
                <td>{$row['name']}</td>
                <td>{$row['uid']}</td>
                <td>
                    <form method='POST' onsubmit='return confirm(\"Are you sure you want to delete this user?\");'>
                        <input type='hidden' name='action' value='delete_user'>
                        <input type='hidden' name='uid' value='{$row['uid']}'>
                        <button type='submit' class='delete-btn' title='Delete User'>
                            <i class='fas fa-trash-alt'></i>
                        </button>
                    </form>
                </td>
            </tr>"; 
        }
        ?>
    </table>

    <!-- Attendance Logs -->
    <h2>Recent Attendance Logs</h2>
    <table>
        <tr>
            <th>Name</th>
            <th>Scan Time</th>
            <th>Scan Type</th>
            <th>Location</th>
        </tr>
        <?php 
        $attendance_reset = $conn->query("SELECT a.*, u.name FROM attendance a LEFT JOIN users u ON a.uid = u.uid ORDER BY a.scan_time DESC LIMIT 50");
        while ($row = $attendance_reset->fetchArray(SQLITE3_ASSOC)) {
            echo "<tr>
                <td>{$row['name']}</td>
                <td>{$row['scan_time']}</td>
                <td>{$row['scan_type']}</td>
                <td>{$row['location']}</td>
            </tr>"; 
        }
        ?>
    </table>
</body>
</html>

<?php
// Close database connection
$conn->close();
?>
