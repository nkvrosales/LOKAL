<?php
require_once "common.php";
admin_require_admin();

$accounts = admin_fetch_accounts($mysqli);
$stores = admin_filter_accounts($accounts, "store");
$productsByStore = admin_fetch_products_by_store($mysqli);
$orders = admin_fetch_orders($mysqli);
$ordersByStore = admin_group_orders($orders, "store_user_id");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Stores</title>
    <link rel="stylesheet" href="../assets/styles.css?v=large-logo-1">
    <link rel="stylesheet" href="../assets/store-admin.css?v=large-logo-1">
    <link rel="stylesheet" href="assets/admin.css?v=large-logo-1">
</head>
<body class="store-admin-body admin-body">
    <header class="top-bar">
        <a class="logo admin-header-logo" href="dashboard.php" aria-label="Admin dashboard">
            <img src="../732961553_1045061465131627_5347302832846310517_n.png" alt="Logo">
        </a>
        <?php echo admin_nav("stores"); ?>
    </header>

    <main class="admin-shell">
        <section class="admin-section">
            <div class="admin-section-head">
                <div>
                    <h1>Stores</h1>
                    <p>Store accounts are listed here. Products and past transactions open in separate modals.</p>
                </div>
            </div>

            <div class="admin-table-wrap">
                <table class="admin-table admin-action-table">
                    <thead>
                        <tr>
                            <th>Store</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Address</th>
                            <th>Products</th>
                            <th>Transactions</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stores as $store): ?>
                            <?php
                                $storeProducts = $productsByStore[$store["id"]] ?? [];
                                $storeOrders = $ordersByStore[$store["id"]] ?? [];
                                $productModalId = "store-products-" . (int) $store["id"];
                                $transactionModalId = "store-transactions-" . (int) $store["id"];
                            ?>
                            <tr>
                                <td><strong><?php echo escape(admin_store_name($store)); ?></strong></td>
                                <td><?php echo escape($store["email"]); ?></td>
                                <td><?php echo escape($store["contact"]); ?></td>
                                <td><?php echo escape($store["store_address"] !== "" ? $store["store_address"] : "--"); ?></td>
                                <td><?php echo count($storeProducts); ?></td>
                                <td><?php echo count($storeOrders); ?></td>
                                <td>
                                    <div class="admin-table-actions">
                                        <button type="button" data-modal-open="<?php echo escape($productModalId); ?>">Products</button>
                                        <button type="button" data-modal-open="<?php echo escape($transactionModalId); ?>">Transactions</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php foreach ($stores as $store): ?>
                <?php
                    $storeProducts = $productsByStore[$store["id"]] ?? [];
                    $storeOrders = $ordersByStore[$store["id"]] ?? [];
                    $productModalId = "store-products-" . (int) $store["id"];
                    $transactionModalId = "store-transactions-" . (int) $store["id"];
                ?>
                <section class="admin-modal" id="<?php echo escape($productModalId); ?>" hidden>
                        <div class="admin-modal-backdrop" data-modal-close></div>
                        <div class="admin-modal-panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo escape($productModalId); ?>-title">
                            <div class="admin-modal-head">
                                <h2 id="<?php echo escape($productModalId); ?>-title"><?php echo escape(admin_store_name($store)); ?> Products</h2>
                                <button type="button" data-modal-close aria-label="Close">&times;</button>
                            </div>
                            <div class="admin-mini-list">
                                <?php if ($storeProducts): ?>
                                    <?php foreach ($storeProducts as $product): ?>
                                        <p>
                                            <strong><?php echo escape($product["name"]); ?></strong>
                                            <span><?php echo $product["price"] !== null ? escape(admin_money($product["price"])) : "No price"; ?></span>
                                        </p>
                                        <?php if ($product["description"] !== ""): ?>
                                            <p class="admin-muted-row"><?php echo escape($product["description"]); ?></p>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>No products listed.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                </section>

                <section class="admin-modal" id="<?php echo escape($transactionModalId); ?>" hidden>
                        <div class="admin-modal-backdrop" data-modal-close></div>
                        <div class="admin-modal-panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo escape($transactionModalId); ?>-title">
                            <div class="admin-modal-head">
                                <h2 id="<?php echo escape($transactionModalId); ?>-title"><?php echo escape(admin_store_name($store)); ?> Transactions</h2>
                                <button type="button" data-modal-close aria-label="Close">&times;</button>
                            </div>
                            <div class="admin-mini-list">
                                <?php if ($storeOrders): ?>
                                    <?php foreach ($storeOrders as $order): ?>
                                        <p>
                                            <strong>#<?php echo (int) $order["id"]; ?> <?php echo escape(admin_status_label($order["status"])); ?></strong>
                                            <span><?php echo escape(admin_money($order["total_amount"])); ?></span>
                                        </p>
                                        <p class="admin-muted-row">
                                            Customer: <?php echo escape($order["customer_name"]); ?> | Ordered: <?php echo escape($order["created_at"] !== "" ? $order["created_at"] : "--"); ?>
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
