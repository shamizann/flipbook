<?php
require_once 'security.php';
configureSecureSession();
require 'db.php';
require_once 'csrf.php';
require_once 'audit.php';
session_start();
sendSecurityHeaders();

$error = '';
$csrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? null;
    if (!isValidCsrfToken(is_string($postedToken) ? $postedToken : null)) {
        $error = 'Invalid session token. Please refresh and try again.';
    } else {
        $clientIp = getClientIp();

        // Rate limiting: block after too many failed attempts
        if (isLoginRateLimited($clientIp)) {
            $error = 'Too many failed login attempts. Please try again later.';
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            try {
                $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    clearFailedLogins($clientIp);
                    session_regenerate_id(true);
                    $adminUserId = (int) $user['id'];
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_user_id'] = $adminUserId;
                    $_SESSION['last_activity'] = time();
                    writeAdminAuditLog($pdo, 'login', $adminUserId, null, ['username' => $username]);
                    header('Location: admin.php');
                    exit;
                }

                recordFailedLogin($clientIp);
                $error = 'Invalid username or password';
            } catch (PDOException $e) {
                $error = 'Login is temporarily unavailable. Please try again later.';
            }
        }
    }

    // Occasionally clean up stale lockout files
    if (random_int(1, 50) === 1) {
        cleanupStaleLockoutFiles();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background-color: #f4f4f4;
            color: #333;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .btn-login {
            width: 100%;
            background: #007bff;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
        }

        .btn-login:hover {
            background: #0069d9;
        }

        .error {
            color: red;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <h2>Admin Login</h2>
        <?php if ($error): ?>
            <div class="error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" autocomplete="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn-login">Login</button>
        </form>
    </div>
</body>

</html>
