<?php
require_once __DIR__ . "/../auth.php";
require_once __DIR__ . "/../db.php";

if (is_logged_in()) {
    if (($_SESSION["account_type"] ?? "") === "admin") {
        header("Location: dashboard.php");
        exit;
    }
    header("Location: ../home.php");
    exit;
}

$errors = [];
$email_value = "";
$show_registered = isset($_GET["registered"]) && $_GET["registered"] === "1";

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
        $stmt = $mysqli->prepare("SELECT id, first_name, last_name, account_type, password_hash FROM users WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $email_value);
            $stmt->execute();
            $stmt->bind_result($id, $first_name, $last_name, $account_type, $password_hash);
            if ($stmt->fetch() && $account_type === "admin" && password_verify($password, $password_hash)) {
                session_regenerate_id(true);
                $_SESSION["user_id"] = $id;
                $_SESSION["user_name"] = $first_name . " " . $last_name;
                $_SESSION["account_type"] = $account_type;
                header("Location: dashboard.php");
                exit;
            }
            $stmt->close();
        }
        $errors[] = "Admin email or password is incorrect.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In</title>
    <link rel="stylesheet" href="../assets/styles.css?v=large-logo-1">
    <link rel="stylesheet" href="../assets/store-admin.css?v=large-logo-1">
    <link rel="stylesheet" href="assets/admin.css?v=large-logo-1">
</head>
<body class="store-admin-body admin-body">
    <main class="auth-shell">
        <div class="auth-grid">
            <section class="brand-panel">
                <img class="admin-login-logo" src="../732961553_1045061465131627_5347302832846310517_n.png" alt="Logo">
                <p>Sign in with an administrator account to manage users, stores, and live routes.</p>
                <p>Customer or store account? <a class="text-link" href="../login.php">Use regular login</a>.</p>
            </section>
            <section class="card">
                <h2>Admin Sign In</h2>
                <p class="status-text">Only admin accounts can sign in here.</p>
                <?php if ($show_registered): ?>
                    <div class="notice">Admin account created. Please sign in.</div>
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
                        <input type="password" id="password" name="password" required>
                    </div>
                    <button class="btn" type="submit">Sign in</button>
                    <p class="status-text">Admin access is limited to approved accounts.</p>
                </form>
            </section>
        </div>
    </main>
</body>
</html>
