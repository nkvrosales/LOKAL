<?php
require_once "common.php";
admin_require_admin();

$accounts = admin_fetch_accounts($mysqli);
$users = admin_filter_accounts($accounts, "user");
$orders = admin_fetch_orders($mysqli);
$ordersByUser = admin_group_orders($orders, "customer_user_id");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Users</title>
    <link rel="stylesheet" href="../assets/styles.css?v=large-logo-1">
    <link rel="stylesheet" href="../assets/store-admin.css?v=large-logo-1">
    <link rel="stylesheet" href="assets/admin.css?v=large-logo-1">
</head>
<body class="store-admin-body admin-body">
    <header class="top-bar">
        <a class="logo admin-header-logo" href="dashboard.php" aria-label="Admin dashboard">
            <img src="../732961553_1045061465131627_5347302832846310517_n.png" alt="Logo">
        </a>
        <?php echo admin_nav("users"); ?>
    </header>

    <main class="admin-shell">
        <section class="admin-section">
            <div class="admin-section-head">
                <div>
                    <h1>Users</h1>
                    <p>User accounts are listed here. Past transactions open in a modal.</p>
                </div>
            </div>

            <div class="admin-table-wrap">
                <table class="admin-table admin-action-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Delivery Address</th>
                            <th>Transactions</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                                $userOrders = $ordersByUser[$user["id"]] ?? [];
                                $transactionModalId = "user-transactions-" . (int) $user["id"];
                            ?>
                            <tr>
                                <td><strong><?php echo escape(admin_account_name($user, "User")); ?></strong></td>
                                <td><?php echo escape($user["email"]); ?></td>
                                <td><?php echo escape($user["contact"]); ?></td>
                                <td><?php echo escape($user["user_address"] !== "" ? $user["user_address"] : "--"); ?></td>
                                <td><?php echo count($userOrders); ?></td>
                                <td>
                                    <div class="admin-table-actions">
                                        <button type="button" data-modal-open="<?php echo escape($transactionModalId); ?>">Transactions</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php foreach ($users as $user): ?>
                <?php
                    $userOrders = $ordersByUser[$user["id"]] ?? [];
                    $transactionModalId = "user-transactions-" . (int) $user["id"];
                ?>
                <section class="admin-modal" id="<?php echo escape($transactionModalId); ?>" hidden>
                        <div class="admin-modal-backdrop" data-modal-close></div>
                        <div class="admin-modal-panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo escape($transactionModalId); ?>-title">
                            <div class="admin-modal-head">
                                <h2 id="<?php echo escape($transactionModalId); ?>-title"><?php echo escape(admin_account_name($user, "User")); ?> Transactions</h2>
                                <button type="button" data-modal-close aria-label="Close">&times;</button>
                            </div>
                            <div class="admin-mini-list">
                                <?php if ($userOrders): ?>
                                    <?php foreach ($userOrders as $order): ?>
                                        <p>
                                            <strong>#<?php echo (int) $order["id"]; ?> <?php echo escape($order["store_name"]); ?></strong>
                                            <span><?php echo escape(admin_status_label($order["status"])); ?></span>
                                        </p>
                                        <p class="admin-muted-row">
                                            Total: <?php echo escape(admin_money($order["total_amount"])); ?> | Ordered: <?php echo escape($order["created_at"] !== "" ? $order["created_at"] : "--"); ?>
                                        </p>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>No transactions yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                </section>
            <?php endforeach; ?>
        </section>
    </main>
    <script src="assets/admin-modals.js?v=admin-pages-1"></script>
</body>
</html>
