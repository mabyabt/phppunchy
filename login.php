<?php
session_start();

// Load users from JSON file
$usersFile = "users.json";
if (!file_exists($usersFile)) {
    die("Error: Users file not found!");
}

$users = json_decode(file_get_contents($usersFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Invalid JSON format - " . json_last_error_msg());
}

$error = ""; // Store error messages

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if (isset($users[$username]) && password_verify($password, $users[$username])) {
        // Authentication successful
        $_SESSION["user"] = $username;
        $_SESSION["last_activity"] = time(); // Track session activity
        header("Location: admin.php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
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
    </style>
</head>
    <title>Login</title>
</head>
<body>
    <h2>Login</h2>
    <?php if ($error): ?>
        <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    
    <form method="post">
        <input type="text" name="username" placeholder="Username" required><br>
        <input type="password" name="password" placeholder="Password" required><br>

        <div style="text-align: center;">
        <button type="submit" >Login</button></div>
    </form>
</body>
</html>
