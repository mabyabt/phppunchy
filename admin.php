<?php
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

    // Users Table (removed email field)
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

} catch (Exception $e) {
    die("Database Initialization Error: " . $e->getMessage());
}

// Attendance Calculation Function
function calculateDailyWorkHours($conn) {
    $daily_hours = [];

    try {
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
            SELECT 
                da.uid, 
                da.work_date,
                da.first_check_in,
                da.last_check_out,
                u.name
            FROM 
                daily_attendance da
            JOIN 
                users u ON da.uid = u.uid
            ORDER BY 
                da.work_date DESC, u.name
        ";

        $result = $conn->query($query);
        
        if ($result === false) {
            throw new Exception("Query failed: " . $conn->lastErrorMsg());
        }

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['first_check_in'] && $row['last_check_out']) {
                $check_in = new DateTime($row['first_check_in']);
                $check_out = new DateTime($row['last_check_out']);
                
                $interval = $check_in->diff($check_out);
                $hours = $interval->h + ($interval->i / 60) + ($interval->s / 3600);
                
                $daily_hours[] = [
                    'date' => $row['work_date'],
                    'name' => $row['name'],
                    'uid' => $row['uid'],
                    'check_in' => $row['first_check_in'],
                    'check_out' => $row['last_check_out'],
                    'total_hours' => round($hours, 2)
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Calculation Error: " . $e->getMessage());
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
                $daily_hours = calculateDailyWorkHours($conn);

                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=attendance_hours_log_' . date('Y-m-d') . '.csv');
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
                        $entry['date'],
                        $entry['name'],
                        $entry['uid'],
                        $entry['check_in'],
                        $entry['check_out'],
                        $entry['total_hours']
                    ]);
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
?>

<!DOCTYPE html>
<html>
<head>
    <title>RFID Attendance Management System</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 0 auto; padding: 20px; }
        form { background: #f4f4f4; padding: 20px; margin-bottom: 20px; border-radius: 5px; }
        input, select { margin: 5px 0; padding: 8px; width: 100%; box-sizing: border-box; }
        button { background-color: #4CAF50; color: white; border: none; padding: 10px 20px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>RFID Attendance Management System</h1>

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

    <!-- Export Attendance Hours -->
    <form method="POST">
        <input type="hidden" name="action" value="export_csv">
        <button type="submit">Download Detailed Attendance Report (CSV)</button>
    </form>

    <!-- Registered Users List -->
    <h2>Registered Users</h2>
    <table>
        <tr>
            <th>Name</th>
            <th>UID</th>
        </tr>
        <?php 
        $users_reset = $conn->query("SELECT * FROM users");
        while ($row = $users_reset->fetchArray(SQLITE3_ASSOC)) {
            echo "<tr>
                <td>{$row['name']}</td>
                <td>{$row['uid']}</td>
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