<?php
session_start();
require_once 'config.php';

// اگر قبلا لاگین شده، به index.php برو
// If already logged in, redirect to index.php
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    if ($username === $LOGIN_USERNAME && $password === $LOGIN_PASSWORD) {
        $_SESSION['logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Incorrect username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login to S3 File Manager</title>
    <link rel="stylesheet" href="assets/bootstrap.min.css">
    <style>
        body {
            background: #f8f9fa;
        }

        .login-box {
            max-width: 400px;
            margin: 80px auto;
            padding: 32px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px #0001;
        }
    </style>
</head>

<body>
    <div class="login-box">
        <h3 class="mb-4 text-center">Login to S3 File Manager</h3>
        <?php if ($error): ?>
            <div class="alert alert-danger text-center"> <?= htmlspecialchars($error) ?> </div>
        <?php endif; ?>
        <form method="post">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" required autofocus>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>
    </div>
</body>

</html>