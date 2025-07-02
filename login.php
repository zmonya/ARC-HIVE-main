<?php
session_start();
require 'db_connection.php'; // Assumes $pdo is initialized here

$error = ""; // Initialize error variable

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Sanitize inputs
    $username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
    $password = trim(filter_input(INPUT_POST, 'password', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');

    if (empty($username) || empty($password)) {
        $error = "Username and password are required.";
    } else {
        // Fetch user from the database
        $stmt = $pdo->prepare("SELECT id, username, password, full_name, position, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // Regenerate session ID for security
            session_regenerate_id(true);

            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['position'] = $user['position'];
            $_SESSION['role'] = $user['role'];

            // Fetch user's department affiliations
            $deptStmt = $pdo->prepare("
                SELECT department_id, sub_department_id 
                FROM user_department_affiliations 
                WHERE user_id = ?
            ");
            $deptStmt->execute([$user['id']]);
            $affiliations = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
            $_SESSION['departments'] = array_column($affiliations, 'department_id');
            $_SESSION['sub_departments'] = array_filter(array_column($affiliations, 'sub_department_id'));

            // Redirect based on role: client -> index.php, admin -> admin_dashboard.php
            $redirect = $user['role'] === 'admin' ? 'admin_dashboard.php' : 'dashboard.php';
            header("Location: $redirect");
            exit();
        } else {
            $error = "Invalid username or password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Montserrat', Arial, sans-serif;
            background-color: #f4f7f9;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .login-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            width: 350px;
            text-align: center;
        }

        .login-container h2 {
            margin-bottom: 20px;
            color: #34495e;
        }

        .login-container input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .login-container button {
            width: 100%;
            padding: 10px;
            background: #34495e;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .login-container button:hover {
            background: #50c878;
        }

        .login-container p {
            margin-top: 15px;
            font-size: 14px;
        }

        .login-container a {
            color: #34495e;
            text-decoration: none;
            font-weight: bold;
        }

        .login-container a:hover {
            color: #50c878;
        }

        .error {
            color: #cc0000;
            font-size: 14px;
            margin-bottom: 10px;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <h2>Login</h2>
        <?php if (!empty($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <form action="login.php" method="POST">
            <input type="text" name="username" placeholder="Username" value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
    </div>
</body>

</html>