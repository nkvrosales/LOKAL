<?php
require_once "auth.php";
require_once "db.php";
require_login();

$userId = (int) ($_SESSION["user_id"] ?? 0);
$isStore = ($_SESSION["account_type"] ?? "") === "store";
$orders = [];

$sql = $isStore
    ? "SELECT id, customer_name, delivery_address, store_name, store_address, total_amount, status,
              order_type, subtotal_amount, delivery_fee, delivery_distance_km,
              created_at, accepted_at, pickup_at, delivered_at
       FROM orders
       WHERE store_user_id = ?
       ORDER BY created_at DESC, id DESC
       LIMIT 100"
    : "SELECT id, customer_name, delivery_address, store_name, store_address, total_amount, status,
              order_type, subtotal_amount, delivery_fee, delivery_distance_km,
              created_at, accepted_at, pickup_at, delivered_at
       FROM orders
       WHERE customer_user_id = ?
       ORDER BY created_at DESC, id DESC
       LIMIT 100";

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result(
        $orderId,
        $customerName,
        $deliveryAddress,
        $storeName,
        $storeAddress,
        $totalAmount,
        $status,
        $orderType,
        $subtotalAmount,
        $deliveryFee,
        $deliveryDistanceKm,
        $createdAt,
        $acceptedAt,
        $pickupAt,
        $deliveredAt
    );
    while ($stmt->fetch()) {
        $orders[(int) $orderId] = [
            "id" => (int) $orderId,
            "customer_name" => (string) ($customerName ?? "Customer"),
            "delivery_address" => (string) ($deliveryAddress ?? ""),
            "store_name" => (string) ($storeName ?? "Store"),
            "store_address" => (string) ($storeAddress ?? ""),
            "total_amount" => $totalAmount !== null ? (float) $totalAmount : 0,
            "status" => (string) ($status ?? ""),
            "order_type" => (string) ($orderType ?? "delivery"),
            "subtotal_amount" => $subtotalAmount !== null ? (float) $subtotalAmount : 0,
            "delivery_fee" => $deliveryFee !== null ? (float) $deliveryFee : 0,
            "delivery_distance_km" => $deliveryDistanceKm !== null ? (float) $deliveryDistanceKm : 0,
            "created_at" => (string) ($createdAt ?? ""),
            "accepted_at" => (string) ($acceptedAt ?? ""),
            "pickup_at" => (string) ($pickupAt ?? ""),
            "delivered_at" => (string) ($deliveredAt ?? ""),
            "items" => [],
        ];
    }
    $stmt->close();
}

if ($orders) {
    $itemResult = $mysqli->query(
        "SELECT order_id, product_name, unit_price, quantity, line_total
         FROM order_items
         WHERE order_id IN (" . implode(",", array_keys($orders)) . ")
         ORDER BY id ASC"
    );
    if ($itemResult) {
        while ($row = $itemResult->fetch_assoc()) {
            $orderId = (int) $row["order_id"];
            if (!isset($orders[$orderId])) {
                continue;
            }
            $orders[$orderId]["items"][] = [
                "product_name" => (string) ($row["product_name"] ?? "Product"),
                "unit_price" => $row["unit_price"] !== null ? (float) $row["unit_price"] : null,
                "quantity" => isset($row["quantity"]) ? (int) $row["quantity"] : 0,
                "line_total" => isset($row["line_total"]) ? (float) $row["line_total"] : 0,
            ];
        }
        $itemResult->close();
    }
}

function format_order_money(float $amount): string
{
    return "PHP " . number_format($amount, 2);
}

function format_order_status(string $status): string
{
    $labels = [
        "pending" => "Pending",
        "for_pickup" => "For Pickup",
        "delivering" => "Delivering",
        "completed" => "Completed",
        "declined" => "Declined",
    ];

    return $labels[$status] ?? ($status !== "" ? ucwords(str_replace("_", " ", $status)) : "Unknown");
}

function format_order_type(string $type): string
{
    return $type === "pickup" ? "Pickup" : "Delivery";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/store-admin.css?v=hover-effects-1">
</head>
<body class="store-admin-body">
    <header class="top-bar">
        <a class="logo" href="home.php" style="text-decoration:none">Lokal</a>
        <nav class="store-admin-nav" aria-label="Account pages">
            <a class="store-admin-tab" href="home.php">Home</a>
            <a class="store-admin-tab" href="account_profile.php">Profile</a>
            <a class="store-admin-tab active" href="order_history.php">Orders</a>
            <?php if ($isStore): ?>
                <a class="store-admin-tab" href="store_products.php">Product</a>
            <?php else: ?>
                <a class="store-admin-tab" href="cart.php">Cart</a>
            <?php endif; ?>
            <a class="store-admin-tab" href="logout.php">Log out</a>
        </nav>
    </header>

    <main class="store-admin-shell">
        <section class="store-admin-card order-history-card">
            <h1><?php echo $isStore ? "Store Orders" : "My Orders"; ?></h1>
            <p class="status-text">Latest orders first.</p>

            <?php if (!$orders): ?>
                <div class="empty-admin-state">No orders found.</div>
            <?php else: ?>
                <div class="order-history-list">
                    <?php foreach ($orders as $order): ?>
                        <article class="order-history-item">
                            <div class="order-history-top">
                                <div>
                                    <strong>Order #<?php echo (int) $order["id"]; ?></strong>
                                    <span><?php echo escape(format_order_status($order["status"])); ?></span>
                                </div>
                                <b><?php echo escape(format_order_money($order["total_amount"])); ?></b>
                            </div>

                            <?php if ($isStore): ?>
                                <p>Customer: <?php echo escape($order["customer_name"]); ?></p>
                                <p>Checkout: <?php echo escape(format_order_type($order["order_type"])); ?></p>
                                <?php if ($order["order_type"] === "delivery"): ?>
                                    <p>Delivery: <?php echo escape($order["delivery_address"] !== "" ? $order["delivery_address"] : "Address not available"); ?></p>
                                <?php else: ?>
                                    <p>Pickup at: <?php echo escape($order["store_address"] !== "" ? $order["store_address"] : "Store address not available"); ?></p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p>Store: <?php echo escape($order["store_name"]); ?></p>
                                <p>Checkout: <?php echo escape(format_order_type($order["order_type"])); ?></p>
                                <p>Store location: <?php echo escape($order["store_address"] !== "" ? $order["store_address"] : "Address not available"); ?></p>
                                <?php if ($order["order_type"] === "delivery"): ?>
                                    <p>Delivery: <?php echo escape($order["delivery_address"] !== "" ? $order["delivery_address"] : "Address not available"); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>

                            <div class="order-history-items">
                                <?php if ($order["items"]): ?>
                                    <?php foreach ($order["items"] as $item): ?>
                                        <p>
                                            <?php echo escape($item["product_name"]); ?>
                                            x <?php echo (int) $item["quantity"]; ?>
                                            <span><?php echo escape(format_order_money($item["line_total"])); ?></span>
                                        </p>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>No items listed.</p>
                                <?php endif; ?>
                            </div>

                            <div class="order-history-items">
                                <p>
                                    Subtotal
                                    <span><?php echo escape(format_order_money($order["subtotal_amount"])); ?></span>
                                </p>
                                <p>
                                    Delivery fee<?php echo $order["order_type"] === "delivery" ? " (" . escape(number_format($order["delivery_distance_km"], 2)) . " km x PHP 40)" : ""; ?>
                                    <span><?php echo escape(format_order_money($order["delivery_fee"])); ?></span>
                                </p>
                                <p>
                                    Total
                                    <span><?php echo escape(format_order_money($order["total_amount"])); ?></span>
                                </p>
                            </div>

                            <div class="order-history-times">
                                <span>Ordered: <?php echo escape($order["created_at"] !== "" ? $order["created_at"] : "--"); ?></span>
                                <?php if ($order["accepted_at"] !== ""): ?><span>Accepted: <?php echo escape($order["accepted_at"]); ?></span><?php endif; ?>
                                <?php if ($order["pickup_at"] !== ""): ?><span>Pickup: <?php echo escape($order["pickup_at"]); ?></span><?php endif; ?>
                                <?php if ($order["delivered_at"] !== ""): ?><span>Delivered: <?php echo escape($order["delivered_at"]); ?></span><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
