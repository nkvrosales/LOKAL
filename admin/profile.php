<?php
require_once "common.php";
admin_require_admin();

$userId = (int) ($_SESSION["user_id"] ?? 0);
$errors = [];
$notice = "";
$profile = [
    "first_name" => "",
    "middle_name" => "",
    "last_name" => "",
    "contact" => "",
    "email" => "",
];

$stmt = $mysqli->prepare(
    "SELECT first_name, middle_name, last_name, contact, email
     FROM users
     WHERE id = ?
       AND account_type = 'admin'
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($firstName, $middleName, $lastName, $contact, $email);
    if ($stmt->fetch()) {
        $profile = [
            "first_name" => trim((string) ($firstName ?? "")),
            "middle_name" => trim((string) ($middleName ?? "")),
            "last_name" => trim((string) ($lastName ?? "")),
            "contact" => trim((string) ($contact ?? "")),
            "email" => trim((string) ($email ?? "")),
        ];
    }
    $stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $currentPassword = $_POST["current_password"] ?? "";
    $newPassword = $_POST["new_password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if ($currentPassword === "") {
        $errors[] = "Current password is required.";
    }
    if (strlen($newPassword) < 6) {
        $errors[] = "New password must be at least 6 characters.";
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = "New passwords do not match.";
    }

    if (!$errors) {
        $stmt = $mysqli->prepare("SELECT password_hash FROM users WHERE id = ? AND account_type = 'admin' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->bind_result($currentHash);
            $found = $stmt->fetch();
            $stmt->close();

            if (!$found || !password_verify($currentPassword, (string) $currentHash)) {
                $errors[] = "Current password is incorrect.";
            }
        } else {
            $errors[] = "Unable to verify current password.";
        }
    }

    if (!$errors) {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ? AND account_type = 'admin'");
        if ($stmt) {
            $stmt->bind_param("si", $newHash, $userId);
            if ($stmt->execute()) {
                $notice = "Password changed.";
            } else {
                $errors[] = "Unable to change password. Please try again.";
            }
            $stmt->close();
        } else {
            $errors[] = "Unable to change password. Please try again.";
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
    <title>Admin Profile</title>
    <link rel="stylesheet" href="../assets/styles.css?v=large-logo-1">
    <link rel="stylesheet" href="../assets/store-admin.css?v=hover-effects-1">
    <link rel="stylesheet" href="assets/admin.css?v=large-logo-1">
</head>
<body class="store-admin-body admin-body">
    <header class="top-bar">
        <a class="logo admin-header-logo" href="dashboard.php" aria-label="Admin dashboard">
            <img src="../732961553_1045061465131627_5347302832846310517_n.png" alt="Logo">
        </a>
        <?php echo admin_nav("profile"); ?>
    </header>

    <main class="admin-shell">
        <section class="admin-section">
            <div class="admin-section-head">
                <div>
                    <h1>Admin Profile</h1>
                    <p>Signed in as <?php echo escape(admin_account_name($profile, "Admin")); ?>.</p>
                </div>
            </div>

            <div class="admin-detail-grid">
                <article class="admin-detail-card">
                    <h3>Account Details</h3>
                    <div class="admin-contact-list">
                        <p>Name: <?php echo escape(admin_account_name($profile, "Admin")); ?></p>
                        <p>Email: <?php echo escape($profile["email"] !== "" ? $profile["email"] : "--"); ?></p>
                        <p>Contact: <?php echo escape($profile["contact"] !== "" ? $profile["contact"] : "--"); ?></p>
                    </div>
                </article>
            </div>
        </section>

        <section class="admin-section">
            <div class="admin-section-head">
                <div>
                    <h2>Change Password</h2>
                    <p>Update the password used for admin login.</p>
                </div>
            </div>

            <?php if ($notice !== ""): ?>
                <div class="notice success"><?php echo escape($notice); ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="notice error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo escape($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="admin-add-form">
                <div class="field">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="field">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                <div class="field">
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <button class="btn" type="submit">Change Password</button>
            </form>
        </section>
    </main>
</body>
</html>
