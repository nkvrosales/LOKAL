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
    // Admin actions: approve or revoke driver approval
    if (isset($_POST['driver_action'], $_POST['driver_id'])) {
        $driverId = (int) ($_POST['driver_id'] ?? 0);
        $action = $_POST['driver_action'] === 'approve' ? 'approve' : 'revoke';
        if ($driverId > 0) {
            $approveVal = $action === 'approve' ? 1 : 0;
            $upd = $mysqli->prepare("UPDATE users SET is_approved = ? WHERE id = ? AND account_type = 'driver' LIMIT 1");
            if ($upd) {
                $upd->bind_param('ii', $approveVal, $driverId);
                if ($upd->execute() && $upd->affected_rows > 0) {
                    $notice = $approveVal ? 'Driver approved.' : 'Driver approval revoked.';
                } else {
                    $errors[] = 'Unable to update driver status.';
                }
                $upd->close();
            } else {
                $errors[] = 'Unable to update driver status.';
            }
        }
    }

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
                header("Location: accounts.php?notice=" . urlencode("Admin account created."));
                exit;
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
$notice = $notice !== "" ? $notice : htmlspecialchars(urldecode($_GET["notice"] ?? ""));
$showAddModal = !empty($errors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Accounts</title>
    <link rel="stylesheet" href="../assets/styles.css?v=large-logo-1">
    <link rel="stylesheet" href="../assets/store-admin.css?v=hover-effects-1">
    <link rel="stylesheet" href="assets/admin.css?v=large-logo-1">
    <style>
        .acct-modal-backdrop { position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;display:flex;align-items:center;justify-content:center; }
        .acct-modal-backdrop[hidden] { display:none !important; }
        .acct-modal-panel { background:#fff;border-radius:18px;padding:26px 28px;width:min(520px,94vw);display:grid;gap:14px;position:relative; }
        .acct-modal-panel h2 { margin:0;font-family:"Cinzel","Georgia",serif;font-size:17px;color:#FF5B2E; }
        .acct-field { display:grid;gap:4px; }
        .acct-field label { font-size:12px;font-weight:600;color:rgba(0,0,0,.6); }
        .acct-field input { height:42px;padding:0 13px;border:1px solid rgba(255,91,46,.22);border-radius:10px;font-size:13.5px;outline:none;width:100%;box-sizing:border-box;transition:border-color .15s; }
        .acct-field input:focus { border-color:#FF5B2E; }
        .acct-split { display:grid;grid-template-columns:1fr 1fr;gap:10px; }
        .acct-modal-actions { display:flex;gap:10px;justify-content:flex-end;margin-top:4px; }
        .acct-modal-close { position:absolute;top:14px;right:14px;width:30px;height:30px;border:0;border-radius:8px;background:rgba(0,0,0,.06);cursor:pointer;font-size:17px;display:flex;align-items:center;justify-content:center;color:rgba(0,0,0,.55); }
        .acct-modal-close:hover { background:rgba(0,0,0,.12); }
        .acct-btn-save { height:40px;padding:0 22px;background:#FF5B2E;color:#fff;border:0;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;transition:background .15s; }
        .acct-btn-save:hover { background:#e04a1f; }
        .acct-btn-cancel { height:40px;padding:0 18px;background:rgba(0,0,0,.07);color:rgba(0,0,0,.7);border:0;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer; }
        .acct-btn-cancel:hover { background:rgba(0,0,0,.12); }
        .acct-btn-add { height:38px;padding:0 20px;background:#FF5B2E;color:#fff;border:0;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;transition:background .15s; }
        .acct-btn-add:hover { background:#e04a1f; }
    </style>
</head>
<body class="store-admin-body admin-body">
    <header class="top-bar">
        <a class="logo admin-header-logo" href="dashboard.php" aria-label="Admin dashboard">
            <img src="../732961553_1045061465131627_5347302832846310517_n.png" alt="Logo">
        </a>
        <?php echo admin_nav("accounts"); ?>
    </header>

    <main class="admin-shell">
        <section class="admin-section" style="padding:24px 22px;display:grid;gap:20px;">
            <div class="admin-section-head">
                <div>
                    <h1>User Management</h1>
                    <p>All store, user, and admin accounts are listed here. This page only creates admin accounts.</p>
                </div>
                <div>
                    <button type="button" class="acct-btn-add" onclick="openAddAdminModal()">+ Add Admin</button>
                </div>
            </div>

            <?php if ($notice !== ""): ?>
                <div class="notice success"><?php echo escape($notice); ?></div>
            <?php endif; ?>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <?php $location = $account["account_type"] === "store" ? $account["store_address"] : $account["user_address"]; ?>
                                    <tr>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:8px;">
                                                    <?php if (!empty($account["profile_image"]) && file_exists(__DIR__ . "/../uploads/profiles/" . $account["profile_image"])): ?>
                                                        <img src="../uploads/profiles/<?php echo escape($account["profile_image"]); ?>" alt="Avatar" style="width:28px; height:28px; border-radius:50%; object-fit:cover; border:1px solid #CBD5E1;">
                                                    <?php endif; ?>
                                                    <span><?php echo escape(admin_account_name($account)); ?></span>
                                                </div>
                                            </td>
                                            <td><span class="admin-type-pill <?php echo escape($account["account_type"]); ?>"><?php echo escape(ucfirst($account["account_type"])); ?></span></td>
                                            <td><?php echo escape($account["email"]); ?></td>
                                            <td><?php echo escape($account["contact"]); ?></td>
                                            <td><?php echo escape($location !== "" ? $location : "--"); ?></td>
                                            <?php if ($account["account_type"] === 'driver'): ?>
                                                <td>
                                                    <?php if ($account["is_approved"]): ?>
                                                        <span class="admin-type-pill approved">Approved</span>
                                                    <?php else: ?>
                                                        <span class="admin-type-pill pending">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($account["profile_image"] !== ""): ?>
                                                        <a class="text-link" href="../uploads/profiles/<?php echo urlencode($account["profile_image"]); ?>" target="_blank" rel="noopener">Photo</a>
                                                    <?php endif; ?>
                                                    <?php if ($account["id_image"] !== ""): ?>
                                                        <a class="text-link" style="margin-left:6px;" href="../uploads/ids/<?php echo urlencode($account["id_image"]); ?>" target="_blank" rel="noopener">ID</a>
                                                    <?php endif; ?>
                                                    <?php if ($account["orcr_image"] !== ""): ?>
                                                        <a class="text-link" style="margin-left:6px;" href="../uploads/orcr/<?php echo urlencode($account["orcr_image"]); ?>" target="_blank" rel="noopener">OR/CR</a>
                                                    <?php endif; ?>
                                                    <form method="post" style="display:inline;margin-left:8px;">
                                                        <input type="hidden" name="driver_id" value="<?php echo (int) $account['id']; ?>">
                                                         <?php if (!$account["is_approved"]): ?>
                                                            <button type="submit" name="driver_action" value="approve" class="acct-btn-save">Approve</button>
                                                        <?php else: ?>
                                                            <button type="submit" name="driver_action" value="revoke" class="acct-btn-cancel">Revoke</button>
                                                        <?php endif; ?>
                                                    </form>
                                                </td>
                                            <?php else: ?>
                                                <td></td>
                                                <td></td>
                                            <?php endif; ?>
                                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <!-- ── Add Admin Modal ─────────────────────────────────────────────────── -->
    <div id="add-admin-modal" class="acct-modal-backdrop" <?php echo !empty($errors) ? '' : 'hidden'; ?>>
        <div class="acct-modal-panel" role="dialog" aria-modal="true" aria-labelledby="add-admin-title">
            <button type="button" class="acct-modal-close" onclick="closeAddAdminModal()" aria-label="Close">&times;</button>
            <h2 id="add-admin-title">Add Admin Account</h2>
            <?php if ($errors): ?>
                <div class="notice error" style="margin:0;">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo escape($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <div style="display:grid;gap:12px;">
                    <div class="acct-split">
                        <div class="acct-field">
                            <label for="first_name">First name <span style="color:#FF5B2E">*</span></label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo escape($newAdmin['first_name']); ?>" required>
                        </div>
                        <div class="acct-field">
                            <label for="middle_name">Middle name <span style="color:#FF5B2E">*</span></label>
                            <input type="text" id="middle_name" name="middle_name" value="<?php echo escape($newAdmin['middle_name']); ?>" required>
                        </div>
                    </div>
                    <div class="acct-field">
                        <label for="last_name">Last name <span style="color:#FF5B2E">*</span></label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo escape($newAdmin['last_name']); ?>" required>
                    </div>
                    <div class="acct-split">
                        <div class="acct-field">
                            <label for="contact">Contact <span style="color:#FF5B2E">*</span></label>
                            <input type="text" id="contact" name="contact" value="<?php echo escape($newAdmin['contact']); ?>" required>
                        </div>
                        <div class="acct-field">
                            <label for="email">Email <span style="color:#FF5B2E">*</span></label>
                            <input type="email" id="email" name="email" value="<?php echo escape($newAdmin['email']); ?>" required>
                        </div>
                    </div>
                    <div class="acct-split">
                        <div class="acct-field">
                            <label for="password">Password <span style="color:#FF5B2E">*</span></label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <div class="acct-field">
                            <label for="confirm_password">Confirm password <span style="color:#FF5B2E">*</span></label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                        </div>
                    </div>
                </div>
                <div class="acct-modal-actions">
                    <button type="button" class="acct-btn-cancel" onclick="closeAddAdminModal()">Cancel</button>
                    <button type="submit" class="acct-btn-save">Create Admin</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const addAdminModal = document.getElementById("add-admin-modal");
        function openAddAdminModal()  { addAdminModal.hidden = false; document.getElementById("first_name").focus(); }
        function closeAddAdminModal() { addAdminModal.hidden = true; }
        addAdminModal.addEventListener("click", e => { if (e.target === addAdminModal) closeAddAdminModal(); });
        document.addEventListener("keydown", e => { if (e.key === "Escape") closeAddAdminModal(); });
    </script>
</body>
</html>
