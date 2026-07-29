<?php
require_once "auth.php";
require_once "db.php";
require_login();

header("Content-Type: application/json; charset=UTF-8");

if (($_SESSION["account_type"] ?? "") === "store") {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "Store accounts do not have customer orders."]);
    exit;
}

$userId = (int) ($_SESSION["user_id"] ?? 0);
$orders = [];

$stmt = $mysqli->prepare(
    "SELECT id, store_name, store_address, store_lat, store_lng, delivery_address,
            delivery_lat, delivery_lng, total_amount, status, created_at, accepted_at, pickup_at
     FROM orders
     WHERE customer_user_id = ?
       AND order_type = 'delivery'
       AND status IN ('pending', 'for_pickup', 'delivering')
     ORDER BY created_at DESC
     LIMIT 10"
);

if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result(
        $orderId,
        $storeName,
        $storeAddress,
        $storeLat,
        $storeLng,
        $deliveryAddress,
        $deliveryLat,
        $deliveryLng,
        $totalAmount,
        $status,
        $createdAt,
        $acceptedAt,
        $pickupAt
    );
    while ($stmt->fetch()) {
        $orders[(int) $orderId] = [
            "id" => (int) $orderId,
            "store_name" => (string) ($storeName ?? "Store"),
            "store_address" => (string) ($storeAddress ?? ""),
            "store_lat" => $storeLat !== null ? (float) $storeLat : null,
            "store_lng" => $storeLng !== null ? (float) $storeLng : null,
            "delivery_address" => (string) ($deliveryAddress ?? ""),
            "delivery_lat" => $deliveryLat !== null ? (float) $deliveryLat : null,
            "delivery_lng" => $deliveryLng !== null ? (float) $deliveryLng : null,
            "total_amount" => $totalAmount !== null ? (float) $totalAmount : 0,
            "status" => (string) ($status ?? "pending"),
            "created_at" => (string) ($createdAt ?? ""),
            "accepted_at" => (string) ($acceptedAt ?? ""),
            "pickup_at" => (string) ($pickupAt ?? ""),
            "items" => [],
        ];
    }
    $stmt->close();
}

if ($orders) {
    $itemResult = $mysqli->query(
        "SELECT order_id, product_name, quantity
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
                "quantity" => isset($row["quantity"]) ? (int) $row["quantity"] : 0,
            ];
        }
        $itemResult->close();
    }
}

$driver = null;
$gpsResult = $mysqli->query(
    "SELECT id, device, lat, lng, created_at
     FROM gps_logs
     WHERE lat IS NOT NULL
       AND lng IS NOT NULL
     ORDER BY id DESC
     LIMIT 1"
);
if ($gpsResult && ($row = $gpsResult->fetch_assoc())) {
    $driver = [
        "id" => (int) ($row["id"] ?? 0),
        "device" => (string) ($row["device"] ?? "Driver"),
        "lat" => isset($row["lat"]) ? (float) $row["lat"] : null,
        "lng" => isset($row["lng"]) ? (float) $row["lng"] : null,
        "created_at" => (string) ($row["created_at"] ?? ""),
    ];
}
if ($gpsResult) {
    $gpsResult->close();
}

echo json_encode([
    "ok" => true,
    "orders" => array_values($orders),
    "driver" => $driver,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
