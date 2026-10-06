<?php
require_once "common.php";
admin_require_admin();

$notice = "";
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["store_verification_update"])) {
    $storeId = filter_input(INPUT_POST, "store_id", FILTER_VALIDATE_INT);
    $isVerified = ($_POST["is_verified"] ?? "0") === "1" ? 1 : 0;
    $documentsReviewed = ($_POST["id_reviewed"] ?? "0") === "1" && ($_POST["permit_reviewed"] ?? "0") === "1";
    if (!$storeId) {
        $errors[] = "Invalid store selected.";
    } elseif ($isVerified && !$documentsReviewed) {
        $errors[] = "Review and confirm both the valid ID and business permit before verifying a store.";
    } else {
        $update = $mysqli->prepare("UPDATE users SET is_verified = ? WHERE id = ? AND account_type = 'store' AND ( ? = 0 OR (id_image IS NOT NULL AND id_image <> '' AND business_permit_image IS NOT NULL AND business_permit_image <> '')) LIMIT 1");
        if (!$update) {
            $errors[] = "Unable to update store verification.";
        } else {
            $update->bind_param("iii", $isVerified, $storeId, $isVerified);
            if ($update->execute() && (!$isVerified || $update->affected_rows === 1)) {
                $notice = $isVerified ? "Store verified successfully." : "Store verification removed.";
            } else {
                $errors[] = "The store must have both a valid ID and business permit before it can be verified.";
            }
            $update->close();
        }
    }
}

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
    <link rel="stylesheet" href="../assets/store-admin.css?v=hover-effects-1">
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
                    <p>Verify stores after reviewing their submitted business details. Verified stores receive a public trust badge.</p>
                </div>
            </div>
            <?php if ($notice !== ""): ?><p class="admin-notice success"><?php echo escape($notice); ?></p><?php endif; ?>
            <?php foreach ($errors as $error): ?><p class="admin-notice error"><?php echo escape($error); ?></p><?php endforeach; ?>

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
                            <th>Verification</th>
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
                                $verificationModalId = "store-verification-" . (int) $store["id"];
                            ?>
                            <tr>
                                <td><strong><?php echo escape(admin_store_name($store)); ?></strong></td>
                                <td><?php echo escape($store["email"]); ?></td>
                                <td><?php echo escape($store["contact"]); ?></td>
                                <td><?php echo escape($store["store_address"] !== "" ? $store["store_address"] : "--"); ?></td>
                                <td><?php echo count($storeProducts); ?></td>
                                <td><?php echo count($storeOrders); ?></td>
                                <td><?php if ($store["is_verified"]): ?><form method="post" class="admin-verification-form"><input type="hidden" name="store_verification_update" value="1"><input type="hidden" name="store_id" value="<?php echo (int) $store["id"]; ?>"><input type="hidden" name="is_verified" value="0"><span class="verified-store-badge">✓ Verified</span><button type="submit" class="admin-unverify-btn">Remove</button></form><?php else: ?><button type="button" class="admin-verify-btn" data-modal-open="<?php echo escape($verificationModalId); ?>">Review &amp; verify</button><?php endif; ?></td>
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
                    $verificationModalId = "store-verification-" . (int) $store["id"];
                ?>
                <section class="admin-modal" id="<?php echo escape($verificationModalId); ?>" hidden>
                    <div class="admin-modal-backdrop" data-modal-close></div>
                    <div class="admin-modal-panel admin-verification-modal" role="dialog" aria-modal="true" aria-labelledby="<?php echo escape($verificationModalId); ?>-title">
                        <div class="admin-modal-head">
                            <div><h2 id="<?php echo escape($verificationModalId); ?>-title">Verify <?php echo escape(admin_store_name($store)); ?></h2><p>Review each submitted document before confirming this store.</p></div>
                            <button type="button" data-modal-close aria-label="Close">&times;</button>
                        </div>
                        <form method="post" class="verification-review-form">
                            <input type="hidden" name="store_verification_update" value="1">
                            <input type="hidden" name="store_id" value="<?php echo (int) $store["id"]; ?>">
                            <input type="hidden" name="is_verified" value="1">
                            <div class="verification-document-grid">
                                <article class="verification-document-card">
                                    <h3>Valid ID</h3>
                                    <?php if ($store["id_image"] !== ""): ?><a href="../uploads/ids/<?php echo urlencode($store["id_image"]); ?>" target="_blank" rel="noopener"><img src="../uploads/ids/<?php echo escape($store["id_image"]); ?>" alt="Submitted ID for <?php echo escape(admin_store_name($store)); ?>"></a><?php else: ?><p class="verification-doc-missing">No ID was submitted.</p><?php endif; ?>
                                    <label class="verification-check"><input type="checkbox" name="id_reviewed" value="1" <?php echo $store["id_image"] === "" ? "disabled" : ""; ?>> I reviewed this ID and it is valid.</label>
                                </article>
                                <article class="verification-document-card">
                                    <h3>Business permit</h3>
                                    <?php if ($store["business_permit_image"] !== ""): ?><a href="../uploads/business_permits/<?php echo urlencode($store["business_permit_image"]); ?>" target="_blank" rel="noopener"><img src="../uploads/business_permits/<?php echo escape($store["business_permit_image"]); ?>" alt="Business permit for <?php echo escape(admin_store_name($store)); ?>"></a><?php else: ?><p class="verification-doc-missing">No business permit was submitted.</p><?php endif; ?>
                                    <label class="verification-check"><input type="checkbox" name="permit_reviewed" value="1" <?php echo $store["business_permit_image"] === "" ? "disabled" : ""; ?>> I reviewed this permit and it is valid.</label>
                                </article>
                            </div>
                            <div class="verification-modal-actions"><button type="button" class="admin-cancel-btn" data-modal-close>Cancel</button><button type="submit" class="admin-confirm-verify-btn" disabled>Confirm verification</button></div>
                        </form>
                    </div>
                </section>
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
    <script>
        document.querySelectorAll(".verification-review-form").forEach((form) => {
            const confirmations = form.querySelectorAll("input[type='checkbox']");
            const confirmButton = form.querySelector(".admin-confirm-verify-btn");
            const updateButton = () => { confirmButton.disabled = confirmations.length !== 2 || !Array.from(confirmations).every((input) => input.checked && !input.disabled); };
            confirmations.forEach((input) => input.addEventListener("change", updateButton));
            updateButton();
        });
    </script>
</body>
</html>
