<?php
require_once "common.php";
admin_require_admin();

$errors = [];
$notice = "";
$newAdmin = [
    "first_name" => "",
    "middle_name" => "",
    "last_name" => "",
    "contact" => "",
    "email" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($newAdmin as $field => $_) {
        $newAdmin[$field] = trim($_POST[$field] ?? "");
    }
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if ($newAdmin["first_name"] === "" || $newAdmin["middle_name"] === "" || $newAdmin["last_name"] === "") {
        $errors[] = "Complete admin name is required.";
    }
    if ($newAdmin["contact"] === "") {
        $errors[] = "Contact is required.";
    }
    if (!filter_var($newAdmin["email"], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email is required.";
    }
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    if (!$errors) {
        $check = $mysqli->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if ($check) {
            $check->bind_param("s", $newAdmin["email"]);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $errors[] = "Email is already registered.";
            }
            $check->close();
        }
    }

    if (!$errors) {
        $accountType = "admin";
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare(
            "INSERT INTO users (account_type, first_name, middle_name, last_name, contact, email, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param(
                "sssssss",
                $accountType,
                $newAdmin["first_name"],
                $newAdmin["middle_name"],
                $newAdmin["last_name"],
                $newAdmin["contact"],
                $newAdmin["email"],
                $hash
            );
            if ($stmt->execute()) {
                $notice = "Admin account created.";
                foreach ($newAdmin as $field => $_) {
                    $newAdmin[$field] = "";
                }
            } else {
                $errors[] = "Unable to create admin account.";
            }
            $stmt->close();
        } else {
            $errors[] = "Unable to create admin account.";
        }
    }
}

$accounts = admin_fetch_accounts($mysqli);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Accounts</title>
    <link rel="stylesheet" href="../assets/styles.css?v=large-logo-1">
    <link rel="stylesheet" href="../assets/store-admin.css?v=large-logo-1">
    <link rel="stylesheet" href="assets/admin.css?v=large-logo-1">
</head>
<body class="store-admin-body admin-body">
    <header class="top-bar">
        <a class="logo admin-header-logo" href="dashboard.php" aria-label="Admin dashboard">
            <img src="../732961553_1045061465131627_5347302832846310517_n.png" alt="Logo">
        </a>
        <?php echo admin_nav("accounts"); ?>
    </header>

    <main class="admin-shell">
        <section class="admin-section">
            <div class="admin-section-head">
                <div>
                    <h1>User Management</h1>
                    <p>All store, user, and admin accounts are listed here. This page only creates admin accounts.</p>
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
                <div class="split">
                    <div class="field">
                        <label for="first_name">First name</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo escape($newAdmin["first_name"]); ?>" required>
                    </div>
                    <div class="field">
                        <label for="middle_name">Middle name</label>
                        <input type="text" id="middle_name" name="middle_name" value="<?php echo escape($newAdmin["middle_name"]); ?>" required>
                    </div>
                    <div class="field">
                        <label for="last_name">Last name</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo escape($newAdmin["last_name"]); ?>" required>
                    </div>
                </div>
                <div class="split">
                    <div class="field">
                        <label for="contact">Contact</label>
                        <input type="text" id="contact" name="contact" value="<?php echo escape($newAdmin["contact"]); ?>" required>
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo escape($newAdmin["email"]); ?>" required>
                    </div>
                </div>
                <div class="split">
                    <div class="field">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>
                <button class="btn" type="submit">Add Admin</button>
            </form>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <?php $location = $account["account_type"] === "store" ? $account["store_address"] : $account["user_address"]; ?>
                            <tr>
                                <td><?php echo escape(admin_account_name($account)); ?></td>
                                <td><span class="admin-type-pill <?php echo escape($account["account_type"]); ?>"><?php echo escape(ucfirst($account["account_type"])); ?></span></td>
                                <td><?php echo escape($account["email"]); ?></td>
                                <td><?php echo escape($account["contact"]); ?></td>
                                <td><?php echo escape($location !== "" ? $location : "--"); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
