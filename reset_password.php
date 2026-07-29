<?php
require_once "auth.php";
require_once "db.php";

if (is_logged_in()) {
    header("Location: home.php");
    exit;
}

$errors = [];
$notice = "";
$email_value = trim($_POST["email"] ?? $_GET["email"] ?? "");
$code_value = trim($_POST["code"] ?? "");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if (!filter_var($email_value, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Enter a valid email.";
    }
    if (!preg_match("/^[0-9]{6}$/", $code_value)) {
        $errors[] = "Enter the 6-digit reset code.";
    }
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    if (!$errors) {
        $userId = 0;
        $storedCode = "";
        $codeIsNotExpired = 0;

        $stmt = $mysqli->prepare(
            "SELECT id, COALESCE(password_reset_token, ''), password_reset_expires > NOW()
             FROM users
             WHERE email = ?
               AND account_type <> 'admin'
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("s", $email_value);
            $stmt->execute();
            $stmt->bind_result($userId, $storedCode, $codeIsNotExpired);
            $stmt->fetch();
            $stmt->close();
        }

        if ($userId <= 0 || !hash_equals((string) $storedCode, $code_value) || (int) $codeIsNotExpired !== 1) {
            $errors[] = "The reset code is invalid or expired.";
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $update = $mysqli->prepare(
                "UPDATE users
                 SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL
                 WHERE id = ?"
            );
            if ($update) {
                $update->bind_param("si", $passwordHash, $userId);
                if ($update->execute()) {
                    $notice = "Your password has been updated. You can now sign in.";
                    $email_value = "";
                    $code_value = "";
                } else {
                    $errors[] = "Unable to update password.";
                }
                $update->close();
            } else {
                $errors[] = "Unable to update password.";
            }
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
    <title>Reset Password | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
</head>
<body>
    <main class="auth-shell">
        <div class="auth-grid">
            <section class="brand-panel">
                <div class="badge"><span></span> Lokal Secure</div>
                <div>
                    <h1>New password</h1>
                    <p>Enter the 6-digit code from your email and create a new password.</p>
                </div>
                <p>Need a code? <a class="text-link" href="forgot_password.php">Send reset code</a>.</p>
            </section>
            <section class="card">
                <h2>Reset Password</h2>
                <?php if ($notice !== ""): ?>
                    <div class="notice"><?php echo escape($notice); ?></div>
                    <p class="status-text"><a class="text-link" href="login.php">Sign in with new password</a></p>
                <?php endif; ?>
                <?php if ($errors): ?>
                    <div class="notice error">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo escape($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($notice === ""): ?>
                    <form method="post" class="form-stack">
                        <div class="field">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" value="<?php echo escape($email_value); ?>" required>
                        </div>
                        <div class="field">
                            <label for="code">6-digit code</label>
                            <input type="text" id="code" name="code" value="<?php echo escape($code_value); ?>" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                        </div>
                        <div class="field">
                            <label for="password">New password</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <div class="field">
                            <label for="confirm_password">Confirm password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                        </div>
                        <button class="btn" type="submit">Update password</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
