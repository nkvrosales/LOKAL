<?php
require_once "auth.php";
require_once "db.php";

if (is_logged_in()) {
    if (($_SESSION["account_type"] ?? "") === "driver") {
        header("Location: driver_dashboard.php");
        exit;
    }
    header("Location: home.php");
    exit;
}

$errors = [];
$email_value = "";

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
        $stmt = $mysqli->prepare("SELECT id, first_name, last_name, account_type, password_hash, is_approved FROM users WHERE email = ?");
        if ($stmt) {
            $stmt->bind_param("s", $email_value);
            $stmt->execute();
            $stmt->bind_result($id, $first_name, $last_name, $account_type, $password_hash, $is_approved);
            if ($stmt->fetch() && password_verify($password, $password_hash)) {
                if ($account_type !== "driver") {
                    $errors[] = "This login is only for delivery riders.";
                } elseif ((int) $is_approved !== 1) {
                    $errors[] = "Your account is pending admin approval.";
                } else {
                    session_regenerate_id(true);
                    $_SESSION["user_id"] = $id;
                    $_SESSION["user_name"] = $first_name . " " . $last_name;
                    $_SESSION["account_type"] = $account_type;
                    header("Location: driver_dashboard.php");
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
    <title>Delivery Rider Login</title>
    <link rel="stylesheet" href="assets/styles.css?v=driver-login-1">
</head>
<body class="login-page">
    <main class="auth-shell">
        <div class="auth-grid login-grid">
            <section class="card login-card">
                <div class="login-brand">
                    <img src="732961553_1045061465131627_5347302832846310517_n.png" alt="Logo">
                    <p>Delivery rider sign in.</p>
                </div>
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
                        <input type="password" id="password" name="password" required>
                    </div>
                    <button class="btn" type="submit">Sign in</button>
                    <div class="login-links">
                        <a class="text-link" href="forgot_password.php">Forgot password?</a>
                        <a class="text-link" href="register.php">Create account</a>
                    </div>
                </form>
            </section>
        </div>
    </main>
</body>
</html>
