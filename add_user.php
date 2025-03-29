<?php
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $users = json_decode(file_get_contents("users.json"), true);
    $username = $_POST["username"];
    $password = password_hash($_POST["password"], PASSWORD_BCRYPT);

    if (!isset($users[$username])) {
        $users[$username] = $password;
        file_put_contents("users.json", json_encode($users, JSON_PRETTY_PRINT));
        echo "User added successfully!";
    } else {
        echo "Username already exists!";
    }
}
?>

<form method="post">
    <input type="text" name="username" placeholder="New Username" required><br>
    <input type="password" name="password" placeholder="New Password" required><br>
    <button type="submit">Add User</button>
</form>
