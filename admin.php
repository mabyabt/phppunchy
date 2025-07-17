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

    // Settings table for app configuration
    $conn->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");

    // Create indexes for performance
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_attendance_uid_scantime ON attendance(uid, scan_time)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_attendance_scantime ON attendance(scan_time)");

    // Set default settings if they don't exist
    $conn->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('show_clocked_in', '1')");

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
            ROUND((JULIANDAY(da.last_check_out) - JULIANDAY(da.first_check_in)) * 24, 2) as total_hours,
            da.first_check_in,
            da.last_check_out
        FROM 
            daily_attendance da
        WHERE 
            da.first_check_in IS NOT NULL AND da.last_check_out IS NOT NULL
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
$message = '';
$message_type = '';

try {
    // Add New User
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"])) {
        switch ($_POST["action"]) {
            case "add_user":
                if (empty($_POST["new_uid"]) || empty($_POST["new_name"])) {
                    throw new Exception("UID and Name are required");
                }
                $stmt = $conn->prepare("INSERT INTO users (uid, name) VALUES (:uid, :name)");
                $stmt->bindValue(':uid', trim($_POST["new_uid"]), SQLITE3_TEXT);
                $stmt->bindValue(':name', trim($_POST["new_name"]), SQLITE3_TEXT);
                $stmt->execute();
                $message = "User added successfully!";
                $message_type = "success";
                break;

            // Delete User
            case "delete_user":
                $stmt = $conn->prepare("DELETE FROM users WHERE uid = :uid");
                $stmt->bindValue(':uid', $_POST["uid"], SQLITE3_TEXT);
                $stmt->execute();
                $message = "User deleted successfully!";
                $message_type = "success";
                break;

            // Log Attendance
            case "log_attendance":
                $stmt = $conn->prepare("INSERT INTO attendance (uid, scan_type, location, notes) VALUES (:uid, :scan_type, :location, :notes)");
                $stmt->bindValue(':uid', $_POST['scan_uid'], SQLITE3_TEXT);
                $stmt->bindValue(':scan_type', $_POST['scan_type'], SQLITE3_TEXT);
                $stmt->bindValue(':location', $_POST['location'] ?? null, SQLITE3_TEXT);
                $stmt->bindValue(':notes', $_POST['notes'] ?? null, SQLITE3_TEXT);
                $stmt->execute();
                $message = "Attendance logged successfully!";
                $message_type = "success";
                break;

            // Toggle display setting
            case "toggle_display":
                $show_clocked_in = isset($_POST["show_clocked_in"]) ? 1 : 0;
                $stmt = $conn->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('show_clocked_in', ?)");
                $stmt->bindValue(1, $show_clocked_in, SQLITE3_INTEGER);
                $stmt->execute();
                $message = "Display setting updated successfully!";
                $message_type = "success";
                break;

            // Export CSV
            case "export_csv":
                $start_date = $_POST['start_date'] ?? null;
                $end_date = $_POST['end_date'] ?? null;

                // Validate date range
                if ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
                    throw new Exception("Start date must be before or equal to end date.");
                }

                $daily_hours = exportAttendanceReport($conn, $start_date, $end_date);

                // If no data found
                if (empty($daily_hours)) {
                    throw new Exception("No attendance records found for the selected date range.");
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
                
                // CSV Headers
                fputcsv($output, [
                    'Date', 
                    'Name', 
                    'UID', 
                    'Check-In Time', 
                    'Check-Out Time', 
                    'Total Hours Worked'
                ]);

                // Write data rows
                foreach ($daily_hours as $entry) {
                    fputcsv($output, [
                        $entry['work_date'],
                        $entry['name'],
                        $entry['uid'],
                        $entry['first_check_in'],
                        $entry['last_check_out'],
                        $entry['total_hours']
                    ]);
                }

                fclose($output);
                exit();
        }
    }
} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
    $message_type = "error";
    error_log("Submission Error: " . $e->getMessage());
}

// Get current display setting
$show_clocked_in = true; // Default
try {
    $setting_result = $conn->query("SELECT value FROM settings WHERE key = 'show_clocked_in'");
    if ($setting_result) {
        $setting_row = $setting_result->fetchArray(SQLITE3_ASSOC);
        if ($setting_row) {
            $show_clocked_in = (bool)$setting_row['value'];
        }
    }
} catch (Exception $e) {
    $show_clocked_in = true; // Default on error
}

// Fetch Users and Attendance Logs
$users = $conn->query("SELECT * FROM users ORDER BY name");
$attendance_logs = $conn->query("SELECT a.*, u.name FROM attendance a LEFT JOIN users u ON a.uid = u.uid ORDER BY a.scan_time DESC LIMIT 50");

// Get currently clocked-in users
$clocked_in_users = getCurrentlyClockedInUsers($conn);
?>

<!DOCTYPE html>
<html>
<head>
    <title>RFID Attendance Management System - Admin Panel</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 0;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 1.2em;
            opacity: 0.9;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.3em;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        }

        .btn-success {
            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
        }

        .btn-small {
            padding: 5px 10px;
            font-size: 12px;
        }

        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: 500;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #667eea;
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        .table tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-badge.checked-in {
            background-color: #d4edda;
            color: #155724;
        }

        .status-badge.checked-out {
            background-color: #f8d7da;
            color: #721c24;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .stat-card .number {
            font-size: 2.5em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }

        .stat-card .label {
            color: #666;
            font-size: 0.9em;
        }

        .export-form {
            display: flex;
            gap: 10px;
            align-items: end;
            flex-wrap: wrap;
        }

        .export-form .form-group {
            margin-bottom: 0;
        }

        @media (max-width: 768px) {
            .dashboard {
                grid-template-columns: 1fr;
            }
            
            .export-form {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="fas fa-users-cog"></i> RFID Attendance Admin Panel</h1>
        <p>Manage users, track attendance, and configure system settings</p>
    </div>

    <div class="container">
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $conn->query("SELECT COUNT(*) FROM users")->fetchArray()[0]; ?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo count($clocked_in_users); ?></div>
                <div class="label">Currently Clocked In</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $conn->query("SELECT COUNT(*) FROM attendance WHERE date(scan_time) = date('now')")->fetchArray()[0]; ?></div>
                <div class="label">Today's Scans</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $conn->query("SELECT COUNT(*) FROM attendance WHERE date(scan_time) >= date('now', '-7 days')")->fetchArray()[0]; ?></div>
                <div class="label">This Week's Scans</div>
            </div>
        </div>

        <div class="dashboard">
            <!-- Display Settings -->
            <div class="card">
                <h3><i class="fas fa-cog"></i> Display Settings</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="toggle_display">
                    <div class="form-group">
                        <label>
                            <strong>Show Currently Clocked-In Users on Scanner Interface:</strong>
                        </label>
                        <div style="margin-top: 10px;">
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_clocked_in" <?php echo $show_clocked_in ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <span style="margin-left: 10px;">
                                <?php echo $show_clocked_in ? 'Currently Shown' : 'Currently Hidden'; ?>
                            </span>
                        </div>
                        <small style="color: #666; display: block; margin-top: 5px;">
                            Toggle this to show/hide the "Currently At Work" section on the main scanner interface.
                        </small>
                    </div>
                    <button type="submit" class="btn">
                        <i class="fas fa-save"></i> Update Setting
                    </button>
                </form>
            </div>

            <!-- Add New User -->
            <div class="card">
                <h3><i class="fas fa-user-plus"></i> Add New User</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add_user">
                    <div class="form-group">
                        <label>RFID UID:</label>
                        <input type="text" name="new_uid" required placeholder="Enter RFID UID">
                    </div>
                    <div class="form-group">
                        <label>Full Name:</label>
                        <input type="text" name="new_name" required placeholder="Enter full name">
                    </div>
                    <button type="submit" class="btn">
                        <i class="fas fa-plus"></i> Add User
                    </button>
                </form>
            </div>

            <!-- Manual Attendance Log -->
            <div class="card">
                <h3><i class="fas fa-clock"></i> Manual Attendance Entry</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="log_attendance">
                    <div class="form-group">
                        <label>Select User:</label>
                        <select name="scan_uid" required>
                            <option value="">Choose a user...</option>
                            <?php 
                            $users_for_select = $conn->query("SELECT * FROM users ORDER BY name");
                            while ($user = $users_for_select->fetchArray(SQLITE3_ASSOC)): 
                            ?>
                                <option value="<?php echo htmlspecialchars($user['uid']); ?>">
                                    <?php echo htmlspecialchars($user['name']); ?> (<?php echo htmlspecialchars($user['uid']); ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Scan Type:</label>
                        <select name="scan_type" required>
                            <option value="">Select type...</option>
                            <option value="check-in">Check In</option>
                            <option value="check-out">Check Out</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Location (Optional):</label>
                        <input type="text" name="location" placeholder="Enter location">
                    </div>
                    <div class="form-group">
                        <label>Notes (Optional):</label>
                        <textarea name="notes" placeholder="Enter any notes..."></textarea>
                    </div>
                    <button type="submit" class="btn">
                        <i class="fas fa-plus"></i> Log Attendance
                    </button>
                </form>
            </div>

            <!-- Export Reports -->
            <div class="card">
                <h3><i class="fas fa-download"></i> Export Reports</h3>
                <form method="POST" class="export-form">
                    <input type="hidden" name="action" value="export_csv">
                    <div class="form-group">
                        <label>Start Date:</label>
                        <input type="date" name="start_date" value="<?php echo date('Y-m-01'); ?>">
                    </div>
                    <div class="form-group">
                        <label>End Date:</label>
                        <input type="date" name="end_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Registered Users -->
        <div class="card">
            <h3><i class="fas fa-users"></i> Registered Users</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>RFID UID</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $users_list = $conn->query("SELECT * FROM users ORDER BY name");
                    while ($user = $users_list->fetchArray(SQLITE3_ASSOC)): 
                        // Check if user is currently clocked in
                        $is_clocked_in = false;
                        foreach ($clocked_in_users as $clocked_user) {
                            if ($clocked_user['uid'] == $user['uid']) {
                                $is_clocked_in = true;
                                break;
                            }
                        }
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                            <td><?php echo htmlspecialchars($user['uid']); ?></td>
                            <td>
                                <span class="status-badge <?php echo $is_clocked_in ? 'checked-in' : 'checked-out'; ?>">
                                    <?php echo $is_clocked_in ? 'Clocked In' : 'Clocked Out'; ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="uid" value="<?php echo htmlspecialchars($user['uid']); ?>">
                                    <button type="submit" class="btn btn-danger btn-small" 
                                            onclick="return confirm('Are you sure you want to delete this user?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent Attendance Logs -->
        <div class="card">
            <h3><i class="fas fa-history"></i> Recent Attendance Logs (Last 50)</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Name</th>
                        <th>UID</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($log = $attendance_logs->fetchArray(SQLITE3_ASSOC)): ?>
                        <tr>
                            <td><?php echo date('M j, Y g:i A', strtotime($log['scan_time'])); ?></td>
                            <td><?php echo htmlspecialchars($log['name'] ?? 'Unknown'); ?></td>
                            <td><?php echo htmlspecialchars($log['uid']); ?></td>
                            <td>
                                <span class="status-badge <?php echo $log['scan_type'] == 'check-in' ? 'checked-in' : 'checked-out'; ?>">
                                    <?php echo ucfirst($log['scan_type']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($log['location'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($log['notes'] ?? '-'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Auto-refresh every 60 seconds to keep data current
        setInterval(function() {
            // Only refresh if no forms are currently being filled
            const activeElement = document.activeElement;
            if (activeElement.tagName !== 'INPUT' && activeElement.tagName !== 'TEXTAREA' && activeElement.tagName !== 'SELECT') {
                location.reload();
            }
        }, 60000);
    </script>