<?php
require_once "auth.php";
require_once "db.php";

if (is_logged_in()) {
    $accountType = ($_SESSION["account_type"] ?? "");
    if ($accountType === "admin") {
        header("Location: admin/dashboard.php");
        exit;
    }
    if ($accountType === "store") {
        header("Location: store_products.php");
        exit;
    }
    if ($accountType === "driver") {
        header("Location: driver_dashboard.php");
        exit;
    }
    header("Location: home.php");
    exit;
}

$errors = [];
$email_value = "";
$show_registered = isset($_GET["registered"]) && $_GET["registered"] === "1";
$show_deleted = isset($_GET["deleted"]) && $_GET["deleted"] === "1";
$first_name = "";
$last_name = "";
$account_type = "";
$password_hash = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email_value = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (!filter_var($email_value, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Enter a valid email.";
    }
    if ($password === "") {
        $errors[] = "Password is required.";
    }

    if (!$errors) {
        $stmt = $mysqli->prepare("SELECT id, first_name, last_name, account_type, password_hash, profile_image FROM users WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $email_value);
            $stmt->execute();
            $stmt->bind_result($id, $first_name, $last_name, $account_type, $password_hash, $profile_image);
            if ($stmt->fetch() && password_verify($password, $password_hash)) {
                if ($account_type === "driver") {
                    $errors[] = "Use the delivery rider login page for rider accounts.";
                } else {
                    session_regenerate_id(true);
                    $_SESSION["user_id"] = $id;
                    $_SESSION["user_name"] = $first_name . " " . $last_name;
                    $_SESSION["account_type"] = $account_type;
                    $_SESSION["profile_image"] = (string) ($profile_image ?? "");
                    if ($account_type === "admin") {
                        header("Location: admin/dashboard.php");
                        exit;
                    }
                    if ($account_type === "store") {
                        header("Location: store_products.php");
                        exit;
                    }
                    header("Location: home.php");
                    exit;
                }
            }
            $stmt->close();
        }
        if (!$errors) {
            $errors[] = "Email or password is incorrect.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="assets/styles.css?v=large-logo-1">
</head>
<body class="login-page">
    <main class="auth-shell">
        <div class="auth-grid login-grid">
            <section class="card login-card">
                <div class="login-brand">
                    <img src="732961553_1045061465131627_5347302832846310517_n.png" alt="Logo">
                    <p>Sign in to continue.</p>
                </div>
                <?php if ($show_registered): ?>
                    <div class="notice">Registration complete. Please sign in.</div>
                <?php endif; ?>
                <?php if ($show_deleted): ?>
                    <div class="notice">Your account has been deleted successfully.</div>
                <?php endif; ?>
                <?php if ($errors): ?>
                    <div class="notice error">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo escape($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="post" class="form-stack">
                    <div class="field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo escape($email_value); ?>" required>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Toggle password visibility">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="eye-icon"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>
                    <button class="btn" type="submit" id="login-btn">
                        <span class="btn-text">Sign in</span>
                        <span class="btn-spinner">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10" opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10"/></svg>
                        </span>
                    </button>
                    <script>
                    function togglePassword() {
                        const input = document.getElementById("password");
                        const icon = document.getElementById("eye-icon");
                        if (input.type === "password") {
                            input.type = "text";
                            icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
                        } else {
                            input.type = "password";
                            icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
                        }
                    }
                    document.querySelector(".form-stack").addEventListener("submit", function() {
                        document.getElementById("login-btn").classList.add("btn-loading");
                    });
                    </script>
                    <div class="login-links">
                        <a class="text-link" href="forgot_password.php">Forgot password?</a>
                        <a class="text-link" href="register.php">Create account</a>
                        <a class="text-link" href="driver_login.php">Delivery rider login</a>
                    </div>
                </form>
            </section>
        </div>
    </main>
</body>
</html>
