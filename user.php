<?php
session_start();
$conn = new SQLite3('rfid_system.db');

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["rfid_uid"])) {
    $rfid_uid = trim($_POST["rfid_uid"]);
    
    // Query to check user and get last scan type
    $query = "
        WITH last_scan AS (
            SELECT scan_type 
            FROM attendance 
            WHERE uid = :uid 
            ORDER BY scan_time DESC 
            LIMIT 1
        )
        SELECT 
            u.name, 
            COALESCE((SELECT scan_type FROM last_scan), 'check-out') AS next_scan_type
        FROM users u
        WHERE u.uid = :uid
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':uid', $rfid_uid, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Determine scan type (alternate between check-in and check-out)
        $scan_type = $row['next_scan_type'] == 'check-in' ? 'check-out' : 'check-in';
        
        // Prepare insert statement
        $insert_stmt = $conn->prepare("
            INSERT INTO attendance (uid, scan_type) 
            VALUES (:uid, :scan_type)
        ");
        
        $insert_stmt->bindValue(':uid', $rfid_uid, SQLITE3_TEXT);
        $insert_stmt->bindValue(':scan_type', $scan_type, SQLITE3_TEXT);
        $insert_stmt->execute();
        
        // Set session variables
        $_SESSION['success'] = ($scan_type == 'check-in' 
            ? "Welcome, " . $row["name"] . "! Check-in recorded." 
            : "Goodbye, " . $row["name"] . "! Check-out recorded.");
        $_SESSION['timestamp'] = "Time: " . date('Y-m-d H:i:s');
        $_SESSION['icon'] = "✅";
        
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        // Unknown RFID
        $_SESSION['success'] = "Access Denied! Unknown RFID UID.";
        $_SESSION['icon'] = "❌";
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
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
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background-color: #f0f0f0; }
        .container { text-align: center; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        input { padding: 10px; font-size: 16px; width: 250px; margin: 20px 0; }
        .success { color: green; }
        .error { color: red; }
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
            <input type="text" id="rfid_input" name="rfid_uid" autofocus required>
        </form>
        <?php if ($success): ?>
        <h3 id="message" class="<?php echo $icon == '✅' ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($icon . " " . $success); ?>
        </h3>
        <div id="timestamp">
            <?php echo htmlspecialchars($timestamp); ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>