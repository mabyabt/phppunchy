<?php
session_start();
// Use the same database connection method as the main system
try {
    $conn = new SQLite3('rfid_system.db');
    $conn->enableExceptions(true);
} catch (Exception $e) {
    die("Database Connection Error: " . $e->getMessage());
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

// Get currently clocked-in users
$clocked_in_users = getCurrentlyClockedInUsers($conn);
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
            min-height: 100vh; 
            margin: 0; 
            background-color: #f0f0f0; 
            padding: 20px;
            box-sizing: border-box;
        }
        .main-container {
            display: flex;
            gap: 30px;
            max-width: 1200px;
            width: 100%;
            align-items: flex-start;
        }
        .scanner-container { 
            text-align: center; 
            background: white; 
            padding: 30px; 
            border-radius: 10px; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.1); 
            width: 300px;
            flex-shrink: 0;
        }
        .status-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            flex: 1;
            max-width: 400px;
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
        .clocked-in-section {
            background: #e8f5e8;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #4CAF50;
        }
        .clocked-in-count {
            font-weight: bold;
            color: #2e7d32;
        }
        .user-list {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 10px;
        }
        .user-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
        }
        .user-item:last-child {
            border-bottom: none;
        }
        .user-name {
            font-weight: bold;
            color: #333;
        }
        .user-time {
            font-size: 0.9em;
            color: #666;
        }
        .hours-worked {
            font-size: 0.8em;
            color: #2e7d32;
            font-weight: bold;
        }
        .no-users {
            color: #666;
            font-style: italic;
            text-align: center;
            padding: 20px;
        }
        .refresh-btn {
            background-color: #2196F3;
            color: white;
            border: none;
            padding: 8px 16px;
            margin-left: 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .refresh-btn:hover {
            background-color: #1976D2;
        }
        @media (max-width: 768px) {
            .main-container {
                flex-direction: column;
                align-items: center;
            }
            .scanner-container,
            .status-container {
                width: 100%;
                max-width: 400px;
            }
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
        
        function refreshStatus() {
            location.reload();
        }
        
        // Auto-refresh every 30 seconds to keep status current
        setInterval(function() {
            // Only refresh if no message is currently displayed
            const messageElement = document.getElementById("message");
            if (!messageElement || messageElement.innerHTML.trim() === "") {
                location.reload();
            }
        }, 30000);
    </script>
</head>
<body>
    <div class="main-container">
        <!-- Scanner Section -->
        <div class="scanner-container">
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
        
        <!-- Currently Clocked-In Users Section -->
        <div class="status-container">
            <div class="clocked-in-section">
                <h3>
                    Currently At Work 
                    <span class="clocked-in-count">(<?php echo count($clocked_in_users); ?>)</span>
                    <button class="refresh-btn" onclick="refreshStatus()">
                        🔄 Refresh
                    </button>
                </h3>
                
                <?php if (count($clocked_in_users) > 0): ?>
                    <div class="user-list">
                        <?php foreach ($clocked_in_users as $user): ?>
                            <div class="user-item">
                                <div>
                                    <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                    <div class="user-time">
                                        In: <?php echo date('g:i A', strtotime($user['last_scan_time'])); ?>
                                    </div>
                                </div>
                                <div class="hours-worked">
                                    <?php 
                                    // Calculate hours worked so far today
                                    $check_in_time = new DateTime($user['last_scan_time']);
                                    $current_time = new DateTime();
                                    $interval = $check_in_time->diff($current_time);
                                    $hours_worked = $interval->h + ($interval->days * 24) + ($interval->i / 60);
                                    echo number_format($hours_worked, 1) . "h";
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-users">
                        No one is currently clocked in
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// Close database connection
$conn->close();
?>
