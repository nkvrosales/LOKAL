<?php
require_once "auth.php";
require_once "db.php";
require_login();

header("Content-Type: application/json; charset=UTF-8");

if (($_SESSION["account_type"] ?? "") === "store") {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "Store accounts cannot checkout."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "message" => "POST is required."]);
    exit;
}

$payload = json_decode(file_get_contents("php://input"), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Invalid checkout payload."]);
    exit;
}

$items = $payload["items"] ?? [];
$orderType = strtolower(trim((string) ($payload["order_type"] ?? "delivery")));
$scheduledTime = trim((string) ($payload["scheduled_time"] ?? "ASAP"));
if ($scheduledTime === "") {
    $scheduledTime = "ASAP";
}
$deliveryLat = $payload["delivery_lat"] ?? null;
$deliveryLng = $payload["delivery_lng"] ?? null;
$deliveryFeePerKm = 40.0;

if (!in_array($orderType, ["delivery", "pickup"], true)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Choose pickup or delivery."]);
    exit;
}

if (!is_array($items) || !$items) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Cart is empty."]);
    exit;
}

$customerUserId = (int) ($_SESSION["user_id"] ?? 0);
$customerName = trim((string) ($_SESSION["user_name"] ?? "Customer"));
$deliveryAddress = "";
$registeredDeliveryLat = null;
$registeredDeliveryLng = null;

$userStmt = $mysqli->prepare("SELECT user_address, user_lat, user_lng FROM users WHERE id = ? LIMIT 1");
if ($userStmt) {
    $userStmt->bind_param("i", $customerUserId);
    $userStmt->execute();
    $userStmt->bind_result($savedAddress, $savedLat, $savedLng);
    if ($userStmt->fetch()) {
        $deliveryAddress = trim((string) ($savedAddress ?? ""));
        $registeredDeliveryLat = $savedLat !== null ? (float) $savedLat : null;
        $registeredDeliveryLng = $savedLng !== null ? (float) $savedLng : null;
    }
    $userStmt->close();
}

if ($orderType === "delivery" && is_numeric($registeredDeliveryLat) && is_numeric($registeredDeliveryLng)) {
    $deliveryLat = $registeredDeliveryLat;
    $deliveryLng = $registeredDeliveryLng;
}

if ($orderType === "delivery" && $deliveryAddress === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Please add your delivery address in registration."]);
    exit;
}

if ($orderType === "delivery" && (!is_numeric($deliveryLat) || !is_numeric($deliveryLng))) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Delivery location is required."]);
    exit;
}

$deliveryLat = is_numeric($deliveryLat) ? (float) $deliveryLat : null;
$deliveryLng = is_numeric($deliveryLng) ? (float) $deliveryLng : null;
if ($orderType === "delivery" && ($deliveryLat < -90 || $deliveryLat > 90 || $deliveryLng < -180 || $deliveryLng > 180)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Delivery coordinates are invalid."]);
    exit;
}

$requested = [];
foreach ($items as $item) {
    $productId = (int) ($item["product_id"] ?? 0);
    $quantity = (int) ($item["quantity"] ?? 0);
    if ($productId <= 0 || $quantity <= 0) {
        continue;
    }
    $requested[$productId] = ($requested[$productId] ?? 0) + min($quantity, 99);
}

if (!$requested) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Cart is empty."]);
    exit;
}

$productRows = [];

$productStmt = $mysqli->prepare(
    "SELECT sp.id, sp.store_user_id, sp.product_name, sp.product_price,
            u.store_name, u.first_name, u.last_name, u.store_address, u.store_lat, u.store_lng
     FROM store_products sp
     INNER JOIN users u ON u.id = sp.store_user_id
     WHERE sp.id = ?
       AND u.account_type = 'store'
       AND u.store_lat IS NOT NULL
       AND u.store_lng IS NOT NULL
     LIMIT 1"
);

if (!$productStmt) {
    http_response_code(500);
    echo json_encode(["ok" => false, "message" => "Unable to prepare checkout."]);
    exit;
}

foreach ($requested as $productId => $quantity) {
    $productStmt->bind_param("i", $productId);
    $productStmt->execute();
    $productStmt->bind_result(
        $rowProductId,
        $storeUserId,
        $productName,
        $productPrice,
        $storeName,
        $storeFirstName,
        $storeLastName,
        $storeAddress,
        $storeLat,
        $storeLng
    );
    if ($productStmt->fetch()) {
        $fallbackStoreName = trim((string) $storeFirstName . " " . (string) $storeLastName);
        $displayStoreName = trim((string) $storeName);
        if ($displayStoreName === "") {
            $displayStoreName = $fallbackStoreName !== "" ? $fallbackStoreName : "Store #" . $storeUserId;
        }

        $unitPrice = $productPrice !== null ? (float) $productPrice : 0.0;
        $productRows[] = [
            "product_id" => (int) $rowProductId,
            "store_user_id" => (int) $storeUserId,
            "product_name" => trim((string) $productName),
            "unit_price" => $productPrice !== null ? $unitPrice : null,
            "quantity" => $quantity,
            "line_total" => $unitPrice * $quantity,
            "store_name" => $displayStoreName,
            "store_address" => (string) ($storeAddress ?? ""),
            "store_lat" => (float) $storeLat,
            "store_lng" => (float) $storeLng,
        ];
    }
    $productStmt->free_result();
}
$productStmt->close();

if (!$productRows) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "No valid products found."]);
    exit;
}

$distanceKmBetween = static function (float $fromLat, float $fromLng, float $toLat, float $toLng): float {
    $earthRadiusKm = 6371.0;
    $latDelta = deg2rad($toLat - $fromLat);
    $lngDelta = deg2rad($toLng - $fromLng);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;
    return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
};

$calculateDeliveryFee = static function (float $distanceKm) use ($deliveryFeePerKm): float {
    if ($distanceKm < 0) {
        return 0.0;
    }
    return max(1, (int) floor($distanceKm)) * $deliveryFeePerKm;
};

$ordersByStore = [];
foreach ($productRows as $row) {
    $ordersByStore[$row["store_user_id"]][] = $row;
}

$mysqli->begin_transaction();

try {
    $createdOrderIds = [];
    $orderStmt = $mysqli->prepare(
        "INSERT INTO orders
            (customer_user_id, store_user_id, status, order_type, scheduled_time, customer_name, delivery_address, delivery_lat, delivery_lng,
             store_name, store_address, store_lat, store_lng, subtotal_amount, delivery_fee, delivery_distance_km, total_amount)
         VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $itemStmt = $mysqli->prepare(
        "INSERT INTO order_items
            (order_id, product_id, product_name, unit_price, quantity, line_total)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    if (!$orderStmt || !$itemStmt) {
        throw new RuntimeException("Unable to prepare order insert.");
    }

    foreach ($ordersByStore as $storeUserId => $rows) {
        $first = $rows[0];
        $subtotal = 0.0;
        foreach ($rows as $row) {
            $subtotal += $row["line_total"];
        }

        $orderDeliveryLat = $orderType === "delivery" ? (float) $deliveryLat : (float) $first["store_lat"];
        $orderDeliveryLng = $orderType === "delivery" ? (float) $deliveryLng : (float) $first["store_lng"];
        $orderDeliveryAddress = $orderType === "delivery"
            ? $deliveryAddress
            : "Customer pickup at store";
        $deliveryDistanceKm = $orderType === "delivery"
            ? round($distanceKmBetween((float) $first["store_lat"], (float) $first["store_lng"], $orderDeliveryLat, $orderDeliveryLng), 2)
            : 0.0;
        $deliveryFee = $orderType === "delivery" ? round($calculateDeliveryFee($deliveryDistanceKm), 2) : 0.0;
        $total = $subtotal + $deliveryFee;

        $storeUserIdValue = (int) $storeUserId;
        $storeNameValue = $first["store_name"];
        $storeAddressValue = $first["store_address"];
        $storeLatValue = $first["store_lat"];
        $storeLngValue = $first["store_lng"];
        $orderStmt->bind_param(
            "iissssddssdddddd",
            $customerUserId,
            $storeUserIdValue,
            $orderType,
            $scheduledTime,
            $customerName,
            $orderDeliveryAddress,
            $orderDeliveryLat,
            $orderDeliveryLng,
            $storeNameValue,
            $storeAddressValue,
            $storeLatValue,
            $storeLngValue,
            $subtotal,
            $deliveryFee,
            $deliveryDistanceKm,
            $total
        );
        if (!$orderStmt->execute()) {
            throw new RuntimeException("Unable to create order.");
        }

        $orderId = $mysqli->insert_id;
        $createdOrderIds[] = $orderId;

        foreach ($rows as $row) {
            $productIdValue = $row["product_id"];
            $productNameValue = $row["product_name"];
            $unitPriceValue = $row["unit_price"];
            $quantityValue = $row["quantity"];
            $lineTotalValue = $row["line_total"];
            $itemStmt->bind_param(
                "iisdid",
                $orderId,
                $productIdValue,
                $productNameValue,
                $unitPriceValue,
                $quantityValue,
                $lineTotalValue
            );
            if (!$itemStmt->execute()) {
                throw new RuntimeException("Unable to create order item.");
            }
        }
    }

    $orderStmt->close();
    $itemStmt->close();
    $mysqli->commit();

    echo json_encode([
        "ok" => true,
        "message" => $orderType === "pickup"
            ? (count($createdOrderIds) === 1 ? "Pickup order placed." : "Pickup orders placed.")
            : (count($createdOrderIds) === 1 ? "Order sent to driver." : "Orders sent to driver."),
        "order_ids" => $createdOrderIds,
    ]);
} catch (Throwable $exception) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode(["ok" => false, "message" => "Checkout failed. Please try again."]);
}
