<?php
session_start();
// Use the same database connection method as the main system
try {
    $conn = new SQLite3('rfid_system.db');
    $conn->enableExceptions(true);
} catch (Exception $e) {
    die("Database Connection Error: " . $e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["rfid_uid"])) {
    $rfid_uid = trim($_POST["rfid_uid"]);
    
    try {
        // More robust query to check user and get last scan type
        $query = "
            WITH last_scan AS (
                SELECT scan_type, scan_time
                FROM attendance 
                WHERE uid = :uid 
                ORDER BY scan_time DESC 
                LIMIT 1
            )
            SELECT 
                u.name, 
                COALESCE((SELECT scan_type FROM last_scan), 'check-out') AS next_scan_type,
                COALESCE((SELECT scan_time FROM last_scan), '1970-01-01 00:00:00') AS last_scan_time
            FROM users u
            WHERE u.uid = :uid
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':uid', $rfid_uid, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Determine scan type (alternate between check-in and check-out)
            $scan_type = $row['next_scan_type'] == 'check-in' ? 'check-out' : 'check-in';
            
            // Optional: Add minimum time between scans (e.g., 5 minutes)
            $last_scan_time = new DateTime($row['last_scan_time']);
            $current_time = new DateTime();
            $interval = $last_scan_time->diff($current_time);
            $minutes_diff = $interval->i + ($interval->h * 60) + ($interval->days * 24 * 60);
            
            // Prevent rapid successive scans
            if ($minutes_diff < 5 && $scan_type == $row['next_scan_type']) {
                throw new Exception("Please wait at least 5 minutes between scans.");
            }
            
            // Prepare insert statement
            $insert_stmt = $conn->prepare("
                INSERT INTO attendance (uid, scan_type, location) 
                VALUES (:uid, :scan_type, :location)
            ");
            
            $insert_stmt->bindValue(':uid', $rfid_uid, SQLITE3_TEXT);
            $insert_stmt->bindValue(':scan_type', $scan_type, SQLITE3_TEXT);
            $insert_stmt->bindValue(':location', gethostname(), SQLITE3_TEXT); // Optional: Add hostname as location
            $insert_stmt->execute();
            
            // Set session variables
            $_SESSION['success'] = ($scan_type == 'check-in' 
                ? "Welcome, " . htmlspecialchars($row["name"]) . "! Check-in recorded." 
                : "Goodbye, " . htmlspecialchars($row["name"]) . "! Check-out recorded.");
            $_SESSION['timestamp'] = "Time: " . date('Y-m-d H:i:s');
            $_SESSION['icon'] = "✅";
        } else {
            // Unknown RFID
            throw new Exception("Unknown RFID UID. Please register first.");
        }
    } catch (Exception $e) {
        // Handle various exceptions
        $_SESSION['success'] = $e->getMessage();
        $_SESSION['icon'] = "❌";
    }
    
    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Retrieve and clear session messages
$success = $_SESSION['success'] ?? null;
$icon = $_SESSION['icon'] ?? null;
$timestamp = $_SESSION['timestamp'] ?? null;
// Clear session messages
unset($_SESSION['success'], $_SESSION['icon'], $_SESSION['timestamp']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>RFID Attendance Scanner</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
            margin: 0; 
            background-color: #f0f0f0; 
        }
        .container { 
            text-align: center; 
            background: white; 
            padding: 30px; 
            border-radius: 10px; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.1); 
            width: 300px; 
        }
        input { 
            padding: 10px; 
            font-size: 16px; 
            width: 100%; 
            margin: 20px 0; 
            box-sizing: border-box; 
        }
        .success { color: green; }
        .error { color: red; }
        #timestamp {
            color: #666;
            font-size: 0.8em;
            margin-top: 10px;
        }
    </style>
    <script>
        window.onload = function() {
            const input = document.getElementById("rfid_input");
            input.focus();
            
            const messageElement = document.getElementById("message");
            if (messageElement && messageElement.innerHTML.trim() !== "") {
                setTimeout(() => {
                    messageElement.innerHTML = "";
                    document.getElementById("timestamp").innerHTML = "";
                }, 30000);
            }
        }
    </script>
</head>
<body>
    <div class="container">
        <h2>Scan your RFID card</h2>
        <form method="POST">
            <input type="text" id="rfid_input" name="rfid_uid" autofocus required placeholder="Tap RFID Card">
        </form>
        <?php if ($success): ?>
        <h3 id="message" class="<?php echo $icon == '✅' ? 'success' : 'error'; ?>">
            <?php echo $icon . " " . $success; ?>
        </h3>
        <div id="timestamp">
            <?php echo $timestamp; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>