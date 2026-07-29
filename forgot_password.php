<?php
require_once "auth.php";
require_once "db.php";

if (is_logged_in()) {
    header("Location: home.php");
    exit;
}

$errors = [];
$notice = "";
$email_value = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email_value = trim($_POST["email"] ?? "");

    if (!filter_var($email_value, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Enter a valid email.";
    }

    if (!$errors) {
        $stmt = $mysqli->prepare("SELECT id, first_name, email, account_type FROM users WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $email_value);
            $stmt->execute();
            $stmt->bind_result($userId, $firstName, $email, $accountType);
            $found = $stmt->fetch();
            $stmt->close();

            if ($found && $accountType !== "admin") {
                $code = (string) random_int(100000, 999999);

                $update = $mysqli->prepare(
                    "UPDATE users
                     SET password_reset_token = ?,
                         password_reset_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                     WHERE id = ?"
                );
                if ($update) {
                    $update->bind_param("si", $code, $userId);
                    $update->execute();
                    $update->close();

                    $msg = "Hello " . ($firstName ?: "there") . ",\n\n";
                    $msg .= "Your Lokal password reset code is: " . $code . "\n\n";
                    $msg .= "This code expires in 15 minutes. If you did not request this, ignore this email.";

                    mail($email, "Your Lokal password reset code", $msg);
                }
            }
        }

        $notice = "If that email exists, a 6-digit reset code has been sent.";
        $email_value = "";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
</head>
<body>
    <main class="auth-shell">
        <div class="auth-grid">
            <section class="brand-panel">
                <div class="badge"><span></span> Lokal Secure</div>
                <div>
                    <h1>Reset password</h1>
                    <p>Enter your account email and we will send a 6-digit reset code.</p>
                </div>
                <p>Remembered it? <a class="text-link" href="login.php">Back to login</a>.</p>
            </section>
            <section class="card">
                <h2>Forgot Password</h2>
                <p class="status-text">Reset codes expire after 15 minutes.</p>
                <?php if ($notice !== ""): ?>
                    <div class="notice"><?php echo escape($notice); ?></div>
                    <p class="status-text"><a class="text-link" href="reset_password.php">Enter reset code</a></p>
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
                    <button class="btn" type="submit">Send reset code</button>
                </form>
            </section>
        </div>
    </main>
</body>
</html>
