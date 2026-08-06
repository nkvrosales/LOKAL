<?php
require_once __DIR__ . "/../auth.php";
require_once __DIR__ . "/../db.php";

function admin_has_admin_account(mysqli $mysqli): bool
{
    $result = $mysqli->query("SELECT COUNT(*) AS total FROM users WHERE account_type = 'admin'");
    if (!$result) {
        return false;
    }
    $row = $result->fetch_assoc();
    $result->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function admin_require_admin(): void
{
    require_login();

    if (($_SESSION["account_type"] ?? "") !== "admin") {
        header("Location: ../home.php");
        exit;
    }
}

function admin_money($amount): string
{
    return "PHP " . number_format((float) $amount, 2);
}

function admin_status_label(string $status): string
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

function admin_account_name(array $account, string $fallbackPrefix = "Account"): string
{
    $name = trim(($account["first_name"] ?? "") . " " . ($account["middle_name"] ?? "") . " " . ($account["last_name"] ?? ""));
    if ($name !== "") {
        return $name;
    }

    return $fallbackPrefix . " #" . (int) ($account["id"] ?? 0);
}

function admin_store_name(array $store): string
{
    $storeName = trim((string) ($store["store_name"] ?? ""));
    if ($storeName !== "") {
        return $storeName;
    }

    return admin_account_name($store, "Store");
}

function admin_fetch_accounts(mysqli $mysqli): array
{
    $accounts = [];
    $result = $mysqli->query(
        "SELECT id, account_type, store_name, store_address, store_lat, store_lng,
                user_address, user_lat, user_lng, first_name, middle_name, last_name,
                contact, email, created_at
         FROM users
         ORDER BY FIELD(account_type, 'admin', 'store', 'user'), created_at DESC, id DESC"
    );

    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $accounts[] = [
            "id" => (int) $row["id"],
            "account_type" => (string) ($row["account_type"] ?? "user"),
            "store_name" => (string) ($row["store_name"] ?? ""),
            "store_address" => (string) ($row["store_address"] ?? ""),
            "store_lat" => $row["store_lat"] !== null ? (float) $row["store_lat"] : null,
            "store_lng" => $row["store_lng"] !== null ? (float) $row["store_lng"] : null,
            "user_address" => (string) ($row["user_address"] ?? ""),
            "user_lat" => $row["user_lat"] !== null ? (float) $row["user_lat"] : null,
            "user_lng" => $row["user_lng"] !== null ? (float) $row["user_lng"] : null,
            "first_name" => (string) ($row["first_name"] ?? ""),
            "middle_name" => (string) ($row["middle_name"] ?? ""),
            "last_name" => (string) ($row["last_name"] ?? ""),
            "contact" => (string) ($row["contact"] ?? ""),
            "email" => (string) ($row["email"] ?? ""),
            "created_at" => (string) ($row["created_at"] ?? ""),
        ];
    }
    $result->close();

    return $accounts;
}

function admin_filter_accounts(array $accounts, string $type): array
{
    return array_values(array_filter($accounts, static function ($account) use ($type): bool {
        return ($account["account_type"] ?? "") === $type;
    }));
}

function admin_fetch_categories(mysqli $mysqli): array
{
    $categories = [];
    $result = $mysqli->query(
        "SELECT id, name, slug, is_active, sort_order, created_at
         FROM categories
         ORDER BY sort_order ASC, name ASC"
    );

    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $categories[] = [
            "id"         => (int) $row["id"],
            "name"       => (string) ($row["name"] ?? ""),
            "slug"       => (string) ($row["slug"] ?? ""),
            "is_active"  => (int) ($row["is_active"] ?? 0),
            "sort_order" => (int) ($row["sort_order"] ?? 0),
            "created_at" => (string) ($row["created_at"] ?? ""),
        ];
    }
    $result->close();

    return $categories;
}

function admin_fetch_products_by_store(mysqli $mysqli): array
{
    $products = [];
    $result = $mysqli->query(
        "SELECT id, store_user_id, product_name, product_description, product_price, created_at
         FROM store_products
         ORDER BY store_user_id ASC, id DESC"
    );

    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $storeId = (int) $row["store_user_id"];
        if (!isset($products[$storeId])) {
            $products[$storeId] = [];
        }
        $products[$storeId][] = [
            "id" => (int) $row["id"],
            "name" => (string) ($row["product_name"] ?? "Product"),
            "description" => (string) ($row["product_description"] ?? ""),
            "price" => $row["product_price"] !== null ? (float) $row["product_price"] : null,
            "created_at" => (string) ($row["created_at"] ?? ""),
        ];
    }
    $result->close();

    return $products;
}

function admin_fetch_orders(mysqli $mysqli): array
{
    $orders = [];
    $result = $mysqli->query(
        "SELECT id, customer_user_id, store_user_id, status, customer_name, delivery_address,
                delivery_lat, delivery_lng, store_name, store_address, store_lat, store_lng,
                total_amount, created_at, accepted_at, pickup_at, delivered_at
         FROM orders
         ORDER BY created_at DESC, id DESC
         LIMIT 300"
    );

    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $orders[(int) $row["id"]] = [
            "id" => (int) $row["id"],
            "customer_user_id" => (int) $row["customer_user_id"],
            "store_user_id" => (int) $row["store_user_id"],
            "status" => (string) ($row["status"] ?? ""),
            "customer_name" => (string) ($row["customer_name"] ?? "Customer"),
            "delivery_address" => (string) ($row["delivery_address"] ?? ""),
            "delivery_lat" => $row["delivery_lat"] !== null ? (float) $row["delivery_lat"] : null,
            "delivery_lng" => $row["delivery_lng"] !== null ? (float) $row["delivery_lng"] : null,
            "store_name" => (string) ($row["store_name"] ?? "Store"),
            "store_address" => (string) ($row["store_address"] ?? ""),
            "store_lat" => $row["store_lat"] !== null ? (float) $row["store_lat"] : null,
            "store_lng" => $row["store_lng"] !== null ? (float) $row["store_lng"] : null,
            "total_amount" => $row["total_amount"] !== null ? (float) $row["total_amount"] : 0,
            "created_at" => (string) ($row["created_at"] ?? ""),
            "accepted_at" => (string) ($row["accepted_at"] ?? ""),
            "pickup_at" => (string) ($row["pickup_at"] ?? ""),
            "delivered_at" => (string) ($row["delivered_at"] ?? ""),
            "items" => [],
        ];
    }
    $result->close();

    if (!$orders) {
        return [];
    }

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
                "quantity" => (int) ($row["quantity"] ?? 0),
                "line_total" => $row["line_total"] !== null ? (float) $row["line_total"] : 0,
            ];
        }
        $itemResult->close();
    }

    return array_values($orders);
}

function admin_group_orders(array $orders, string $key): array
{
    $grouped = [];
    foreach ($orders as $order) {
        $grouped[(int) ($order[$key] ?? 0)][] = $order;
    }

    return $grouped;
}

function admin_fetch_live_payload(mysqli $mysqli, array $accounts = null, array $orders = null): array
{
    $accounts = $accounts ?? admin_fetch_accounts($mysqli);
    $orders = $orders ?? admin_fetch_orders($mysqli);
    $driver = null;

    $gpsResult = $mysqli->query(
        "SELECT id, device, status, device_time, valid, lat, lng, created_at
         FROM gps_logs
         WHERE lat IS NOT NULL
           AND lng IS NOT NULL
           AND NOT (lat = 0 AND lng = 0)
         ORDER BY id DESC
         LIMIT 1"
    );
    if ($gpsResult && ($row = $gpsResult->fetch_assoc())) {
        $driver = [
            "id" => (int) $row["id"],
            "device" => (string) ($row["device"] ?? "GPS"),
            "status" => (string) ($row["status"] ?? ""),
            "device_time" => (string) ($row["device_time"] ?? ""),
            "valid" => $row["valid"] !== null ? (int) $row["valid"] : null,
            "lat" => $row["lat"] !== null ? (float) $row["lat"] : null,
            "lng" => $row["lng"] !== null ? (float) $row["lng"] : null,
            "created_at" => (string) ($row["created_at"] ?? ""),
        ];
    }
    if ($gpsResult) {
        $gpsResult->close();
    }

    $stores = [];
    $users = [];
    foreach ($accounts as $account) {
        $name = trim($account["first_name"] . " " . $account["last_name"]);
        if ($account["account_type"] === "store" && $account["store_lat"] !== null && $account["store_lng"] !== null) {
            $stores[] = [
                "id" => $account["id"],
                "name" => admin_store_name($account),
                "address" => $account["store_address"],
                "lat" => $account["store_lat"],
                "lng" => $account["store_lng"],
            ];
        }
        if ($account["account_type"] === "user" && $account["user_lat"] !== null && $account["user_lng"] !== null) {
            $users[] = [
                "id" => $account["id"],
                "name" => $name !== "" ? $name : "User #" . $account["id"],
                "address" => $account["user_address"],
                "lat" => $account["user_lat"],
                "lng" => $account["user_lng"],
            ];
        }
    }

    $activeOrders = array_values(array_filter($orders, static function ($order): bool {
        return in_array($order["status"], ["pending", "for_pickup", "delivering"], true);
    }));

    return [
        "ok" => true,
        "generated_at" => date(DATE_ATOM),
        "driver" => $driver,
        "stores" => $stores,
        "users" => $users,
        "active_orders" => array_slice($activeOrders, 0, 25),
    ];
}

function admin_nav(string $active): string
{
    $items = [
        "dashboard"  => ["href" => "dashboard.php",  "label" => "Dashboard"],
        "accounts"   => ["href" => "accounts.php",   "label" => "Accounts"],
        "stores"     => ["href" => "stores.php",     "label" => "Stores"],
        "users"      => ["href" => "users.php",      "label" => "Users"],
        "categories" => ["href" => "categories.php", "label" => "Categories"],
        "profile"    => ["href" => "profile.php",    "label" => "Profile"],
    ];

    $html = '<nav class="store-admin-nav" aria-label="Admin pages">';
    foreach ($items as $key => $item) {
        $class = "store-admin-tab" . ($key === $active ? " active" : "");
        $html .= '<a class="' . $class . '" href="' . escape($item["href"]) . '">' . escape($item["label"]) . '</a>';
    }
    $html .= '<a class="store-admin-tab" href="../logout.php?redirect=admin">Log out</a>';
    $html .= '</nav>';

    return $html;
}
