<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";

require_login();

if (($_SESSION["account_type"] ?? "") !== "driver") {
    header("Location: home.php");
    exit;
}

function normalize_gps_row(array $row): array
{
    return [
        "id" => isset($row["id"]) ? (int) $row["id"] : 0,
        "device" => (string) ($row["device"] ?? ""),
        "status" => (string) ($row["status"] ?? ""),
        "device_time" => (string) ($row["device_time"] ?? ""),
        "valid" => isset($row["valid"]) && $row["valid"] !== null ? (int) $row["valid"] : null,
        "updated" => isset($row["updated"]) && $row["updated"] !== null ? (int) $row["updated"] : null,
        "sat" => isset($row["sat"]) && $row["sat"] !== null ? (int) $row["sat"] : null,
        "lat" => isset($row["lat"]) && $row["lat"] !== null ? (float) $row["lat"] : null,
        "lng" => isset($row["lng"]) && $row["lng"] !== null ? (float) $row["lng"] : null,
        "chars_count" => isset($row["chars_count"]) && $row["chars_count"] !== null ? (int) $row["chars_count"] : null,
        "hdop" => isset($row["hdop"]) && $row["hdop"] !== null ? (float) $row["hdop"] : null,
        "created_at" => (string) ($row["created_at"] ?? ""),
    ];
}

function fetch_live_gps_history(mysqli $mysqli, int $limit = 30): array
{
    $limit = max(1, min($limit, 100));
    $history = [];
    $result = $mysqli->query(
        "SELECT id, device, status, device_time, valid, updated, sat, lat, lng, chars_count, hdop, created_at
         FROM gps_logs
         WHERE lat IS NOT NULL
           AND lng IS NOT NULL
         ORDER BY id DESC
         LIMIT {$limit}"
    );

    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $history[] = normalize_gps_row($row);
    }

    $result->close();

    return array_reverse($history);
}

function fetch_driver_orders(mysqli $mysqli): array
{
    $orders = [];
    $result = $mysqli->query(
        "SELECT o.id, o.customer_name, u.contact AS customer_contact, o.delivery_address,
                o.store_name, o.store_address, o.store_lat, o.store_lng,
                o.delivery_lat, o.delivery_lng, o.total_amount, o.subtotal_amount,
                o.delivery_fee, o.delivery_distance_km, o.status, o.created_at,
                o.accepted_at, o.pickup_at, o.delivered_at,
                su.contact AS store_contact, su.store_contact AS store_alt_contact
         FROM orders o
         LEFT JOIN users u ON u.id = o.customer_user_id
         LEFT JOIN users su ON su.id = o.store_user_id
         WHERE o.order_type = 'delivery'
           AND (
             o.status IN ('pending', 'delivering')
             OR (o.status = 'completed' AND DATE(o.delivered_at) = CURRENT_DATE())
           )
         ORDER BY 
           CASE o.status
             WHEN 'delivering' THEN 1
             WHEN 'pending' THEN 2
             WHEN 'completed' THEN 3
             ELSE 4
           END,
           o.created_at DESC
         LIMIT 30"
    );

    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $orderId = (int) $row["id"];
        $storeContact = !empty($row["store_alt_contact"]) ? (string) $row["store_alt_contact"] : (string) ($row["store_contact"] ?? "");
        $orders[$orderId] = [
            "id" => $orderId,
            "customer_name" => (string) ($row["customer_name"] ?? "Customer"),
            "customer_contact" => (string) ($row["customer_contact"] ?? ""),
            "delivery_address" => (string) ($row["delivery_address"] ?? ""),
            "store_name" => (string) ($row["store_name"] ?? "Store"),
            "store_address" => (string) ($row["store_address"] ?? ""),
            "store_contact" => $storeContact,
            "store_lat" => isset($row["store_lat"]) ? (float) $row["store_lat"] : null,
            "store_lng" => isset($row["store_lng"]) ? (float) $row["store_lng"] : null,
            "delivery_lat" => isset($row["delivery_lat"]) ? (float) $row["delivery_lat"] : null,
            "delivery_lng" => isset($row["delivery_lng"]) ? (float) $row["delivery_lng"] : null,
            "subtotal_amount" => isset($row["subtotal_amount"]) ? (float) $row["subtotal_amount"] : 0,
            "delivery_fee" => isset($row["delivery_fee"]) ? (float) $row["delivery_fee"] : 0,
            "delivery_distance_km" => isset($row["delivery_distance_km"]) ? (float) $row["delivery_distance_km"] : 0,
            "total_amount" => isset($row["total_amount"]) ? (float) $row["total_amount"] : 0,
            "status" => (string) ($row["status"] ?? "pending"),
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
                "quantity" => isset($row["quantity"]) ? (int) $row["quantity"] : 0,
                "line_total" => isset($row["line_total"]) ? (float) $row["line_total"] : 0,
            ];
        }
        $itemResult->close();
    }

    return array_values($orders);
}

function fetch_driver_earnings(mysqli $mysqli): array
{
    $today = date("Y-m-d");

    // Today's completed deliveries
    $todayRes = $mysqli->query(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(delivery_fee), 0) AS total_fee
         FROM orders
         WHERE status = 'completed'
           AND DATE(delivered_at) = '{$today}'
           AND order_type = 'delivery'"
    );
    $todayRow = $todayRes ? $todayRes->fetch_assoc() : null;
    $todayCount = (int) ($todayRow["cnt"] ?? 0);
    $todayFee = (float) ($todayRow["total_fee"] ?? 0);

    // This week (Mon–today)
    $weekRes = $mysqli->query(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(delivery_fee), 0) AS total_fee
         FROM orders
         WHERE status = 'completed'
           AND YEARWEEK(delivered_at, 1) = YEARWEEK(CURRENT_DATE(), 1)
           AND order_type = 'delivery'"
    );
    $weekRow = $weekRes ? $weekRes->fetch_assoc() : null;
    $weekCount = (int) ($weekRow["cnt"] ?? 0);
    $weekFee = (float) ($weekRow["total_fee"] ?? 0);

    // All-time total deliveries completed
    $allRes = $mysqli->query(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(delivery_fee), 0) AS total_fee
         FROM orders
         WHERE status = 'completed'
           AND order_type = 'delivery'"
    );
    $allRow = $allRes ? $allRes->fetch_assoc() : null;
    $allCount = (int) ($allRow["cnt"] ?? 0);
    $allFee = (float) ($allRow["total_fee"] ?? 0);

    return [
        "today_count" => $todayCount,
        "today_fee" => $todayFee,
        "week_count" => $weekCount,
        "week_fee" => $weekFee,
        "all_count" => $allCount,
        "all_fee" => $allFee,
    ];
}

function build_live_payload(mysqli $mysqli): array
{
    $history = fetch_live_gps_history($mysqli, 30);
    $current = $history ? $history[count($history) - 1] : null;

    return [
        "ok" => true,
        "source" => "gps_logs",
        "refresh_interval_seconds" => 10,
        "generated_at" => date(DATE_ATOM),
        "current" => $current,
        "history" => $history,
        "orders" => fetch_driver_orders($mysqli),
    ];
}

function update_order_status(mysqli $mysqli, int $orderId, string $action): array
{
    $transitions = [
        "accept" => ["from" => "pending", "to" => "delivering", "time_column" => "accepted_at"],
        "decline" => ["from" => "pending", "to" => "declined", "time_column" => "declined_at"],
        "complete" => ["from" => "delivering", "to" => "completed", "time_column" => "delivered_at"],
    ];

    if (!isset($transitions[$action]) || $orderId <= 0) {
        return ["ok" => false, "message" => "Invalid order action."];
    }

    $transition = $transitions[$action];
    $timeColumn = $transition["time_column"];

    if ($action === "accept") {
        $stmt = $mysqli->prepare(
            "UPDATE orders
             SET status = 'delivering', accepted_at = CURRENT_TIMESTAMP, pickup_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'pending'
             LIMIT 1"
        );
    } else {
        $stmt = $mysqli->prepare(
            "UPDATE orders
             SET status = ?, {$timeColumn} = CURRENT_TIMESTAMP
             WHERE id = ? AND status = ?
             LIMIT 1"
        );
    }

    if (!$stmt) {
        return ["ok" => false, "message" => "Unable to update order."];
    }

    if ($action === "accept") {
        $stmt->bind_param("i", $orderId);
    } else {
        $stmt->bind_param("sis", $transition["to"], $orderId, $transition["from"]);
    }

    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();

    return [
        "ok" => $updated,
        "message" => $updated ? "Order updated." : "Order is no longer available for that action.",
    ];
}

function escape_value(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

function display_value($value, string $fallback = "--"): string
{
    if ($value === null) {
        return $fallback;
    }

    $text = trim((string) $value);

    return $text !== "" ? $text : $fallback;
}

function build_google_maps_url(?array $current): string
{
    if (!$current || $current["lat"] === null || $current["lng"] === null) {
        return "";
    }

    return "https://www.google.com/maps?q=" . $current["lat"] . "," . $current["lng"];
}

if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST") {
    header("Content-Type: application/json; charset=UTF-8");
    $input = json_decode(file_get_contents("php://input"), true);
    $action = is_array($input) ? (string) ($input["action"] ?? "") : "";
    $orderId = is_array($input) ? (int) ($input["order_id"] ?? 0) : 0;
    $result = update_order_status($conn, $orderId, $action);
    $result["payload"] = build_live_payload($conn);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = build_live_payload($conn);

if (isset($_GET["format"]) && $_GET["format"] === "json") {
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    exit;
}

$current = $payload["current"];
$googleMapsUrl = build_google_maps_url($current);
$driverName = $_SESSION["user_name"] ?? "Rider";
$driverProfileImage = $_SESSION["profile_image"] ?? "";
if ($driverProfileImage === "" && isset($_SESSION["user_id"])) {
    $p_res = $conn->query("SELECT profile_image FROM users WHERE id = " . (int) $_SESSION["user_id"] . " LIMIT 1");
    if ($p_res && $p_row = $p_res->fetch_assoc()) {
        $driverProfileImage = (string) ($p_row["profile_image"] ?? "");
        $_SESSION["profile_image"] = $driverProfileImage;
    }
}
$earnings = fetch_driver_earnings($conn);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Dashboard | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/home.css?v=driver-clean-1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        .driver-orders-sidebar {
            width: 380px;
            min-width: 380px;
            height: 100vh;
            background: #FFFFFF;
            border-right: 1px solid #E2E8F0;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            flex-shrink: 0;
            box-sizing: border-box;
            transition: margin-left 0.25s ease, box-shadow 0.2s ease;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            position: relative;
            overflow: hidden;
        }

        .sidebar-collapsed .driver-orders-sidebar {
            margin-left: -380px;
        }

        .driver-orders-sidebar .sidebar-header {
            padding: 20px 20px 16px 20px;
            border-bottom: 1px solid #F1F5F9;
            background: #FFFFFF;
            display: flex;
            flex-direction: column;
            gap: 14px;
            flex-shrink: 0;
        }

        .driver-orders-sidebar .sidebar-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 4px;
        }

        .driver-orders-sidebar .sidebar-title {
            margin: 0;
            font-family: "Outfit", sans-serif;
            font-size: 20px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.5px;
        }

        .driver-orders-sidebar .sidebar-collapse-btn {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 0;
            background: var(--primary-light);
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .driver-orders-sidebar .sidebar-collapse-btn:hover {
            background: rgba(255, 77, 46, 0.18);
            filter: brightness(0.96);
        }

        .driver-orders-sidebar .sidebar-search-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .driver-orders-sidebar .sidebar-search-input {
            flex: 1;
            height: 44px;
            padding: 0 16px;
            border: 1.5px solid #E2E8F0;
            border-radius: 14px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 500;
            color: var(--ink);
            background: #F8FAFC;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .driver-orders-sidebar .sidebar-search-input::placeholder {
            color: #94A3B8;
            font-weight: 400;
        }

        .driver-orders-sidebar .sidebar-search-input:focus {
            border-color: var(--primary);
            background: #FFFFFF;
            box-shadow: 0 0 0 3px rgba(255, 91, 46, 0.08);
        }

        .driver-orders-sidebar .sidebar-locate-btn {
            width: 44px;
            height: 44px;
            padding: 0;
            border: 1.5px solid #E2E8F0;
            border-radius: 14px;
            background: #FFFFFF;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.15s ease;
        }

        .driver-orders-sidebar .sidebar-locate-btn:hover {
            background: var(--primary-light);
            border-color: var(--primary);
        }

        .driver-orders-sidebar .sidebar-categories {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding-top: 4px;
        }

        .driver-orders-sidebar .cat-pill {
            padding: 6px 14px;
            border: 1px solid #E2E8F0;
            border-radius: 999px;
            background: #F8FAFC;
            color: #475569;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            line-height: 1.3;
            transition: all 0.15s ease;
        }

        .driver-orders-sidebar .cat-pill:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: rgba(255, 77, 46, 0.3);
        }

        .driver-orders-sidebar .cat-pill.active {
            background: var(--primary);
            color: #FFFFFF;
            border-color: var(--primary);
            box-shadow: none;
        }

        .driver-orders-sidebar .sidebar-store-list {
            flex: 1;
            overflow-y: auto;
            padding: 16px 20px 24px 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            background: linear-gradient(180deg, #FFFFFF 0%, #F8FAFC 100%);
        }

        .driver-order-card {
            padding: 16px;
            border-radius: 18px;
            border: 1.5px solid #E2E8F0;
            background: #FFFFFF;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: all 0.2s ease;
            box-sizing: border-box;
            width: 100%;
            box-shadow: 0 10px 18px rgba(15, 23, 42, 0.02);
        }

        .driver-order-card:hover {
            border-color: rgba(255, 77, 46, 0.35);
            box-shadow: 0 12px 22px rgba(15, 23, 42, 0.06);
            transform: translateY(-1px);
        }

        .driver-order-card.active {
            border-color: var(--primary);
            box-shadow: 0 0 0 1px rgba(255, 77, 46, 0.25), 0 10px 28px rgba(255, 77, 46, 0.12);
            background: linear-gradient(180deg, #FFFFFF 0%, #FFF8F6 100%);
        }

        .driver-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .driver-card-id {
            font-family: "Outfit", sans-serif;
            font-size: 16px;
            font-weight: 700;
            color: var(--ink);
            margin: 0;
        }

        .driver-status-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: #FEF3C7;
            color: #B45309;
        }

        .status-for_pickup {
            background: #DBEAFE;
            color: #1D4ED8;
        }

        .status-delivering {
            background: #D1FAE5;
            color: #047857;
        }

        .status-completed {
            background: #F1F5F9;
            color: #475569;
        }

        .driver-route-block {
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: #F8FAFC;
            border-radius: 12px;
            padding: 12px;
            border: 1px solid #F1F5F9;
        }

        .driver-route-row {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .driver-route-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-top: 4px;
            flex-shrink: 0;
        }

        .dot-store {
            background: var(--primary);
        }

        .dot-customer {
            background: var(--secondary);
        }

        .driver-route-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
            min-width: 0;
        }

        .driver-route-label {
            font-size: 11px;
            font-weight: 700;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .driver-route-val {
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
            margin: 0;
            word-break: break-word;
        }

        .driver-route-sub {
            font-size: 12px;
            color: #64748B;
            margin: 0;
            word-break: break-word;
        }

        .driver-order-amount {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            color: #475569;
            font-weight: 600;
        }

        .driver-order-total {
            font-family: "Outfit", sans-serif;
            font-size: 16px;
            font-weight: 800;
            color: var(--primary);
        }

        .driver-actions-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding-top: 4px;
        }

        .driver-btn {
            flex: 1;
            min-width: 120px;
            height: 38px;
            border-radius: 10px;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            border: 1px solid #E2E8F0;
            background: #FFFFFF;
            color: var(--ink);
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .driver-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        .driver-btn.btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #FFFFFF;
        }

        .driver-btn.btn-primary:hover {
            background: var(--primary-hover);
        }

        .driver-btn.btn-success {
            background: var(--secondary);
            border-color: var(--secondary);
            color: #FFFFFF;
        }

        .driver-btn.btn-success:hover {
            background: var(--secondary-hover);
        }

        .driver-btn.btn-decline {
            background: #FFF1F2;
            border-color: #FECDD3;
            color: #E11D48;
        }

        .driver-btn.btn-decline:hover {
            background: #FFE4E6;
        }

        .map-locate-action {
            position: absolute;
            right: 12px;
            bottom: 92px;
            z-index: 1000;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.96);
            border: 1.5px solid rgba(226, 232, 240, 0.9);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.18);
            transition: all 0.2s ease;
        }

        .map-locate-action:hover {
            background: var(--primary-light);
            border-color: var(--primary);
            transform: scale(1.05);
        }

        /* ── Delivery Action & Success Modals ── */
        .menu-toggle {
            position: absolute;
            top: 18px;
            left: 18px;
            width: 54px;
            height: 54px;
            border: 0;
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 5px;
            background: rgba(255, 91, 46, 0.93);
            box-shadow: 0 14px 32px rgba(0, 0, 0, 0.26);
            cursor: pointer;
            z-index: 1100;
            transition: transform 0.2s ease, filter 0.2s ease;
        }

        .menu-toggle:hover {
            transform: translateY(-1px);
            filter: brightness(0.98);
        }

        .menu-toggle span {
            display: block;
            width: 24px;
            height: 2px;
            border-radius: 99px;
            background: #fff;
        }

        .menu-drawer {
            position: absolute;
            top: 0;
            left: 0;
            z-index: 1300;
            width: min(340px, 86vw);
            height: 100%;
            padding: 24px 20px;
            display: flex;
            flex-direction: column;
            gap: 18px;
            color: var(--ink, #0F172A);
            background: linear-gradient(180deg, #FFFFFF 0%, #F8FAFC 100%);
            border-right: 1px solid #E2E8F0;
            transform: translateX(-100%);
            transition: transform 0.22s ease;
            box-shadow: 0 22px 48px rgba(15, 23, 42, 0.14);
            overflow-y: auto;
        }

        body.menu-open .menu-drawer {
            transform: translateX(0);
        }

        .menu-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #F1F5F9;
        }

        .menu-logo {
            display: block;
            width: auto;
            max-width: 150px;
            height: 48px;
            object-fit: contain;
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.06));
        }

        .menu-close {
            width: 36px;
            height: 36px;
            border: 1px solid #E2E8F0;
            border-radius: 50%;
            color: #64748B;
            background: #F8FAFC;
            font-size: 20px;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .menu-close:hover {
            background: var(--primary-light, rgba(255, 77, 46, 0.12));
            border-color: var(--primary, #FF4D2E);
            color: var(--primary, #FF4D2E);
        }

        .menu-section {
            border-radius: 20px;
            border: 1px solid #E2E8F0;
            background: #F8FAFC;
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: none;
        }

        .menu-section h3 {
            margin: 0 0 4px 0;
            font-family: "Outfit", sans-serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #64748B;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .menu-section h3::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--primary, #FF4D2E);
            box-shadow: none;
        }

        .menu-link {
            width: 100%;
            border: 1.5px solid #E2E8F0;
            border-radius: 14px;
            padding: 12px 16px;
            text-align: left;
            color: #1E293B;
            background: #FFFFFF;
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            transition: all 0.15s ease;
            box-sizing: border-box;
            box-shadow: none;
        }

        .menu-link svg {
            color: var(--primary, #FF4D2E);
            flex-shrink: 0;
        }

        .menu-link:hover {
            border-color: rgba(255, 77, 46, 0.4);
            background: var(--primary-light, rgba(255, 77, 46, 0.08));
            color: var(--primary-hover, #E03E22);
        }

        .user-card-info {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 4px 0 8px 0;
        }

        .menu-user-name {
            margin: 0;
            font-family: "Outfit", sans-serif;
            font-size: 16px;
            font-weight: 700;
            color: var(--ink);
        }

        .menu-user-role {
            margin: 4px 0 0;
            font-size: 12px;
            color: #64748B;
        }

        .menu-backdrop {
            position: absolute;
            inset: 0;
            z-index: 1200;
            background: rgba(15, 23, 42, 0.45);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }

        body.menu-open .menu-backdrop {
            opacity: 1;
            pointer-events: auto;
        }

        .driver-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(15, 23, 42, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.15s ease;
        }

        .driver-modal-backdrop.open {
            opacity: 1;
            pointer-events: auto;
        }

        .driver-modal-card {
            background: #FFFFFF;
            border-radius: 22px;
            border: 1px solid #CBD5E1;
            padding: 32px 26px 26px;
            width: 100%;
            max-width: 400px;
            box-shadow: none;
            text-align: center;
            transform: none;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .driver-modal-backdrop.open .driver-modal-card {
            transform: none;
        }

        .driver-modal-icon-wrap {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            flex-shrink: 0;
        }

        .driver-modal-icon-wrap.confirm-icon {
            background: #EFF6FF;
            color: #2563EB;
            border: 2px solid #DBEAFE;
        }

        .driver-modal-icon-wrap.success-icon {
            background: #ECFDF5;
            color: #059669;
            border: 2px solid #A7F3D0;
        }

        .driver-modal-title {
            font-family: "Outfit", sans-serif;
            font-size: 19px;
            font-weight: 800;
            color: #0F172A;
            margin: 0 0 10px;
            line-height: 1.35;
        }

        .driver-modal-desc {
            font-size: 14px;
            color: #64748B;
            line-height: 1.55;
            margin: 0 0 26px;
        }

        .driver-modal-actions {
            display: flex;
            gap: 12px;
            width: 100%;
        }

        .driver-modal-btn {
            flex: 1;
            height: 46px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.15s ease;
            border: none;
            text-decoration: none;
        }

        .driver-modal-btn.cancel {
            background: #F1F5F9;
            color: #475569;
            border: 1px solid #E2E8F0;
        }

        .driver-modal-btn.cancel:hover {
            background: #E2E8F0;
            color: #0F172A;
        }

        .driver-modal-btn.confirm-delivery {
            background: #10B981;
            color: #FFFFFF;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
        }

        .driver-modal-btn.confirm-delivery:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        .driver-modal-btn.success-done {
            background: #FF5B2E;
            color: #FFFFFF;
            box-shadow: 0 4px 14px rgba(255, 91, 46, 0.35);
        }

        .driver-modal-btn.success-done:hover {
            background: #E0481D;
            transform: translateY(-1px);
        }

        @media (max-width: 768px) {
            .driver-orders-sidebar {
                position: absolute;
                inset: auto 0 0 0;
                width: 100%;
                min-width: 100%;
                max-width: 100%;
                height: 46vh;
                max-height: 46vh;
                min-height: 270px;
                z-index: 1200;
                border-radius: 24px 24px 0 0;
                border-right: 0;
                border-top: 1.5px solid rgba(15, 23, 42, 0.08);
                box-shadow: 0 -10px 36px rgba(15, 23, 42, 0.2);
                transform: translateY(100%);
                transition: transform 0.32s cubic-bezier(0.16, 1, 0.3, 1);
            }

            .driver-orders-sidebar.sidebar-open {
                transform: translateY(0);
            }

            .sidebar-collapsed .driver-orders-sidebar {
                margin-left: 0;
                transform: translateY(100%);
            }

            .driver-orders-sidebar .sidebar-header {
                padding: 10px 16px 8px 16px;
                gap: 8px;
                position: relative;
            }

            .driver-orders-sidebar .sidebar-header::before {
                content: "";
                display: block;
                width: 38px;
                height: 4px;
                border-radius: 99px;
                background: #CBD5E1;
                margin: 0 auto 4px auto;
            }

            .driver-orders-sidebar .sidebar-top-bar {
                padding-bottom: 0;
            }

            .driver-orders-sidebar .sidebar-title {
                font-size: 16px;
            }

            .driver-orders-sidebar .sidebar-collapse-btn {
                width: 30px;
                height: 30px;
                border-radius: 8px;
            }

            .driver-orders-sidebar .sidebar-search-row {
                gap: 8px;
            }

            .driver-orders-sidebar .sidebar-search-input {
                height: 36px;
                font-size: 12.5px;
                padding: 0 12px;
                border-radius: 10px;
            }

            .driver-orders-sidebar .sidebar-locate-btn {
                width: 36px;
                height: 36px;
                border-radius: 10px;
            }

            .driver-orders-sidebar .sidebar-categories {
                flex-wrap: nowrap;
                overflow-x: auto;
                padding-bottom: 2px;
                gap: 6px;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }

            .driver-orders-sidebar .sidebar-categories::-webkit-scrollbar {
                display: none;
            }

            .driver-orders-sidebar .cat-pill {
                padding: 4px 11px;
                font-size: 11px;
                white-space: nowrap;
                flex-shrink: 0;
            }

            .driver-orders-sidebar .sidebar-store-list {
                padding: 8px 14px 18px 14px;
                gap: 8px;
            }

            .menu-drawer {
                width: min(340px, 88vw);
            }

            .menu-toggle {
                top: 12px;
                left: 12px;
                width: 48px;
                height: 48px;
                border-radius: 14px;
            }

            .sidebar-expand-btn {
                top: 12px;
                left: 68px;
                height: 48px;
                border-radius: 14px;
                padding: 0 14px;
                font-size: 12.5px;
                display: inline-flex !important;
            }
        }

        /* ── Earnings Panel ── */
        .earnings-panel {
            padding: 14px 16px 12px;
            background: linear-gradient(135deg, #FF5B2E 0%, #E0431A 100%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .earnings-panel-title {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.7px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.75);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .earnings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
        }

        .earnings-card {
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 12px;
            padding: 10px 10px 8px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            cursor: default;
            transition: background 0.15s;
        }

        .earnings-card:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        .earnings-card-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.7);
        }

        .earnings-card-value {
            font-family: "Outfit", sans-serif;
            font-size: 16px;
            font-weight: 800;
            color: #FFFFFF;
            line-height: 1.2;
        }

        .earnings-card-sub {
            font-size: 10.5px;
            color: rgba(255, 255, 255, 0.65);
            font-weight: 500;
        }
    </style>
</head>

<body class="home-screen account-driver">
    <div class="home-layout">

        <!-- ── Driver Orders Sidebar ── -->
        <aside class="driver-orders-sidebar" id="driver-sidebar">
            <div class="sidebar-header">
                <div class="sidebar-top-bar">
                    <h2 class="sidebar-title">Driver Orders</h2>
                    <button type="button" id="sidebar-collapse-btn" class="sidebar-collapse-btn" title="Hide sidebar"
                        aria-label="Hide sidebar">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="15 18 9 12 15 6"></polyline>
                        </svg>
                    </button>
                </div>

                <!-- ── Earnings Panel ── -->
                <div class="earnings-panel" id="earnings-panel">
                    <div class="earnings-grid">
                        <div class="earnings-card">
                            <span class="earnings-card-label">Today</span>
                            <span class="earnings-card-value"
                                id="earn-today-fee">₱<?php echo number_format($earnings["today_fee"], 2); ?></span>
                            <span class="earnings-card-sub"><?php echo $earnings["today_count"]; ?>
                                deliver<?php echo $earnings["today_count"] === 1 ? "y" : "ies"; ?></span>
                        </div>
                        <div class="earnings-card">
                            <span class="earnings-card-label">This Week</span>
                            <span class="earnings-card-value"
                                id="earn-week-fee">₱<?php echo number_format($earnings["week_fee"], 2); ?></span>
                            <span class="earnings-card-sub"><?php echo $earnings["week_count"]; ?>
                                deliver<?php echo $earnings["week_count"] === 1 ? "y" : "ies"; ?></span>
                        </div>
                        <div class="earnings-card">
                            <span class="earnings-card-label">All Time</span>
                            <span class="earnings-card-value"
                                id="earn-all-fee">₱<?php echo number_format($earnings["all_fee"], 2); ?></span>
                            <span class="earnings-card-sub"><?php echo $earnings["all_count"]; ?>
                                deliver<?php echo $earnings["all_count"] === 1 ? "y" : "ies"; ?></span>
                        </div>
                    </div>
                </div>

                <div class="sidebar-search-row">
                    <input type="text" id="driver-search-input" class="sidebar-search-input"
                        placeholder="Search order #, customer, store" autocomplete="off">
                    <button type="button" id="driver-refresh-btn" class="sidebar-locate-btn" title="Refresh data"
                        aria-label="Refresh data">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <polyline points="1 20 1 14 7 14"></polyline>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                    </button>
                </div>

                <div class="sidebar-categories" id="status-filter-pills">
                    <button type="button" class="cat-pill active" data-status="all">All</button>
                    <button type="button" class="cat-pill" data-status="pending">Pending</button>
                    <button type="button" class="cat-pill" data-status="delivering">Delivering</button>
                    <button type="button" class="cat-pill" data-status="completed">Completed</button>
                </div>
            </div>

            <div class="sidebar-store-list" id="driver-orders-list">
                <!-- Orders dynamic rendering -->
            </div>
        </aside>

        <!-- ── Main Map Shell ── -->
        <main class="home-map-shell">
            <div id="home-map"></div>

            <button id="sidebar-expand-btn" class="sidebar-expand-btn" type="button" aria-label="Show orders sidebar"
                hidden>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="9 18 15 12 9 6"></polyline>
                </svg>
                <span>Orders</span>
            </button>

            <!-- Recenter GPS Action Button -->
            <button type="button" class="map-locate-action" id="map-recenter-btn" title="Center GPS location"
                aria-label="Center GPS location">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="3 11 22 2 13 21 11 13 3 11"></polygon>
                </svg>
            </button>

            <!-- Navigation Drawer Toggle -->
            <button id="menu-toggle" class="menu-toggle" type="button" aria-controls="menu-drawer" aria-expanded="false"
                aria-label="Open menu">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <!-- Slide Menu Drawer -->
            <aside class="menu-drawer" id="menu-drawer" aria-hidden="true">
                <div class="menu-head">
                    <img class="menu-logo" src="732961553_1045061465131627_5347302832846310517_n.png" alt="Lokal">
                    <button class="menu-close" id="menu-close" type="button" aria-label="Close menu">&times;</button>
                </div>

                <section class="menu-section">
                    <h3>Rider Controls</h3>
                    <button type="button" class="menu-link" id="menu-center-live">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" />
                            <circle cx="12" cy="9" r="2.5" />
                        </svg>
                        <span>Center to latest GPS</span>
                    </button>
                    <button type="button" class="menu-link" id="menu-refresh-now">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67" />
                        </svg>
                        <span>Refresh now</span>
                    </button>
                    <a class="menu-link" id="menu-open-google" href="<?php echo escape_value($googleMapsUrl); ?>"
                        target="_blank" rel="noopener" <?php echo $googleMapsUrl === "" ? 'aria-disabled="true"' : ""; ?>>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="3 11 22 2 13 21 11 13 3 11" />
                        </svg>
                        <span>Open in Google Maps</span>
                    </a>
                </section>

                <section class="menu-section">
                    <h3>Live GPS Feed</h3>
                    <p class="menu-meta" id="drawer-feed-status">
                        <?php echo $current ? "Receiving GPS updates from gps_logs." : "No GPS rows with coordinates yet."; ?>
                    </p>
                    <p class="menu-meta" id="drawer-device">Device:
                        <?php echo escape_value(display_value($current["device"] ?? null)); ?>
                    </p>
                    <p class="menu-meta" id="drawer-status">Status:
                        <?php echo escape_value(display_value($current["status"] ?? null)); ?>
                    </p>
                    <p class="menu-meta" id="drawer-device-time">Device time:
                        <?php echo escape_value(display_value($current["device_time"] ?? null)); ?>
                    </p>
                    <p class="menu-meta" id="drawer-server-time">Server time:
                        <?php echo escape_value(display_value($current["created_at"] ?? null)); ?>
                    </p>
                </section>

                <section class="menu-section">
                    <h3>Account</h3>
                    <div class="user-card-info">
                        <?php if (!empty($driverProfileImage) && file_exists(__DIR__ . "/uploads/profiles/" . $driverProfileImage)): ?>
                            <img class="user-avatar-circle user-avatar-img"
                                src="uploads/profiles/<?php echo escape_value($driverProfileImage); ?>" alt="Driver Avatar"
                                style="object-fit:cover; width:44px; height:44px; border-radius:50%; border:2px solid var(--primary);">
                        <?php else: ?>
                            <div class="user-avatar-circle"><?php echo strtoupper(substr($driverName, 0, 1)); ?></div>
                        <?php endif; ?>
                        <div>
                            <p class="menu-user-name"><?php echo escape_value($driverName); ?></p>
                            <p class="menu-user-role">Delivery Rider</p>
                        </div>
                    </div>
                    <a class="menu-link" href="account_profile.php">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                        <span>Profile & Documents</span>
                    </a>
                    <a class="menu-link menu-logout" href="logout.php">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                            <polyline points="16 17 21 12 16 7" />
                            <line x1="21" y1="12" x2="9" y2="12" />
                        </svg>
                        <span>Log out</span>
                    </a>
                </section>
            </aside>

            <div class="menu-backdrop" id="menu-backdrop"></div>
        </main>
    </div>

    <!-- ── Delivery Confirm Modal ── -->
    <div class="driver-modal-backdrop" id="delivery-confirm-modal" role="dialog" aria-modal="true"
        aria-labelledby="confirm-modal-title">
        <div class="driver-modal-card">
            <h2 class="driver-modal-title" id="confirm-modal-title">Mark as Delivered?</h2>
            <p class="driver-modal-desc" id="confirm-modal-desc">Please confirm that Order #<strong
                    id="confirm-order-id">—</strong> has been successfully delivered to the customer.</p>
            <div class="driver-modal-actions">
                <button type="button" class="driver-modal-btn cancel" id="confirm-modal-cancel">Cancel</button>
                <button type="button" class="driver-modal-btn confirm-delivery" id="confirm-modal-ok">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12" />
                    </svg>
                    Confirm Delivery
                </button>
            </div>
        </div>
    </div>

    <!-- ── Delivery Success Modal ── -->
    <div class="driver-modal-backdrop" id="delivery-success-modal" role="dialog" aria-modal="true"
        aria-labelledby="success-modal-title">
        <div class="driver-modal-card">
            <div class="driver-modal-icon-wrap success-icon">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                    <polyline points="22 4 12 14.01 9 11.01" />
                </svg>
            </div>
            <h2 class="driver-modal-title" id="success-modal-title">Thank you for the delivery!</h2>
            <p class="driver-modal-desc">Order #<strong id="success-order-id">—</strong> has been marked as delivered.
                Great work!</p>
            <div class="driver-modal-actions">
                <button type="button" class="driver-modal-btn success-done" id="success-modal-done">Done</button>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const livePayload = <?php echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const refreshIntervalMs = (livePayload.refresh_interval_seconds || 10) * 1000;
        const initGps = livePayload.current && livePayload.current.lat !== null && livePayload.current.lng !== null
            ? [Number(livePayload.current.lat), Number(livePayload.current.lng)]
            : [14.5995, 120.9842];
        const initZoom = livePayload.current && livePayload.current.lat !== null ? 16 : 13;

        const map = L.map("home-map", { zoomControl: false }).setView(initGps, initZoom);
        L.control.zoom({ position: "bottomright" }).addTo(map);

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "&copy; OpenStreetMap contributors"
        }).addTo(map);

        const driverIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker driver rider">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="6.5" cy="17.5" r="2.5"></circle>
                        <circle cx="17.5" cy="17.5" r="2.5"></circle>
                        <path d="M9 7H6"></path>
                        <path d="M8.5 10.5 11 17.5"></path>
                        <path d="M11 10.5h4l2.5 7"></path>
                        <path d="M10.5 10.5 14 7.5"></path>
                    </svg>
                </div>`,
            iconSize: [38, 38],
            iconAnchor: [19, 19],
            popupAnchor: [0, -22]
        });

        const storeIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker store-home" style="background:#FFFFFF; color:var(--primary); border-color:var(--primary);">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                </div>`,
            iconSize: [38, 38],
            iconAnchor: [19, 19],
            popupAnchor: [0, -22]
        });

        const customerIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker customer user" style="background:#FFFFFF; color:var(--secondary); border-color:var(--secondary);">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                        <circle cx="12" cy="10" r="3"></circle>
                    </svg>
                </div>`,
            iconSize: [38, 38],
            iconAnchor: [19, 19],
            popupAnchor: [0, -22]
        });

        const menuDrawer = document.getElementById("menu-drawer");
        const menuToggle = document.getElementById("menu-toggle");
        const menuClose = document.getElementById("menu-close");
        const menuBackdrop = document.getElementById("menu-backdrop");
        const sidebar = document.getElementById("driver-sidebar");
        const sidebarCollapseBtn = document.getElementById("sidebar-collapse-btn");
        const sidebarExpandBtn = document.getElementById("sidebar-expand-btn");
        const ordersListEl = document.getElementById("driver-orders-list");
        const searchInput = document.getElementById("driver-search-input");
        const menuOpenGoogle = document.getElementById("menu-open-google");

        let liveMarker = null;
        let storeMarker = null;
        let customerMarker = null;
        let routeLayer = null;
        let activeStatusFilter = "all";
        let searchQuery = "";
        let selectedOrderId = null;
        let selectedRouteMode = "store";
        let latestPayload = livePayload;
        let isRefreshing = false;
        let hasCenteredInitially = false;

        function openMenu() {
            document.body.classList.add("menu-open");
            menuDrawer.setAttribute("aria-hidden", "false");
            menuToggle.setAttribute("aria-expanded", "true");
        }

        function closeMenu() {
            document.body.classList.remove("menu-open");
            menuDrawer.setAttribute("aria-hidden", "true");
            menuToggle.setAttribute("aria-expanded", "false");
        }

        function formatMoney(amount) {
            const num = Number(amount || 0);
            return `PHP ${num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }

        function escapeHtml(value) {
            return String(value || "").replace(/[&<>"']/g, (char) => ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                "\"": "&quot;",
                "'": "&#39;"
            }[char]));
        }

        function clearRoute() {
            if (routeLayer) {
                map.removeLayer(routeLayer);
                routeLayer = null;
            }
            if (storeMarker) {
                map.removeLayer(storeMarker);
                storeMarker = null;
            }
            if (customerMarker) {
                map.removeLayer(customerMarker);
                customerMarker = null;
            }
        }

        async function drawRouteForOrder(order, mode = "store") {
            const current = latestPayload.current;
            if (!current || current.lat === null || current.lng === null || !order) {
                clearRoute();
                return;
            }

            const start = [Number(current.lat), Number(current.lng)];
            const isStore = mode === "store";
            const targetLat = isStore ? Number(order.store_lat) : Number(order.delivery_lat);
            const targetLng = isStore ? Number(order.store_lng) : Number(order.delivery_lng);

            if (!Number.isFinite(targetLat) || !Number.isFinite(targetLng)) {
                clearRoute();
                return;
            }

            const end = [targetLat, targetLng];
            clearRoute();

            if (isStore) {
                storeMarker = L.marker(end, { icon: storeIcon }).addTo(map)
                    .bindPopup(`<b>Pickup Store:</b><br>${escapeHtml(order.store_name)}`);
            } else {
                customerMarker = L.marker(end, { icon: customerIcon }).addTo(map)
                    .bindPopup(`<b>Deliver to:</b><br>${escapeHtml(order.customer_name)}`);
            }

            try {
                const url = `https://router.project-osrm.org/route/v1/driving/${start[1]},${start[0]};${end[1]},${end[0]}?overview=full&geometries=geojson`;
                const response = await fetch(url, { cache: "no-store" });
                const data = await response.json();
                const coords = (data?.routes?.[0]?.geometry?.coordinates || []).map((pt) => [pt[1], pt[0]]);
                routeLayer = L.polyline(coords.length ? coords : [start, end], {
                    color: isStore ? "#FF4D2E" : "#10B981",
                    weight: 5,
                    opacity: 0.88
                }).addTo(map);

                map.fitBounds(routeLayer.getBounds(), {
                    paddingTopLeft: [50, 420],
                    paddingBottomRight: [50, 50]
                });
            } catch (e) {
                routeLayer = L.polyline([start, end], {
                    color: isStore ? "#FF4D2E" : "#10B981",
                    weight: 5,
                    dashArray: "8 8",
                    opacity: 0.8
                }).addTo(map);

                map.fitBounds(routeLayer.getBounds(), {
                    paddingTopLeft: [50, 420],
                    paddingBottomRight: [50, 50]
                });
            }

            updateGoogleMapsLink(current, targetLat, targetLng);
        }

        function updateGoogleMapsLink(current, targetLat, targetLng) {
            if (menuOpenGoogle) {
                if (current && current.lat && targetLat) {
                    menuOpenGoogle.href = `https://www.google.com/maps/dir/?api=1&origin=${current.lat},${current.lng}&destination=${targetLat},${targetLng}&travelmode=driving`;
                    menuOpenGoogle.removeAttribute("aria-disabled");
                } else if (current && current.lat) {
                    menuOpenGoogle.href = `https://www.google.com/maps?q=${current.lat},${current.lng}`;
                    menuOpenGoogle.removeAttribute("aria-disabled");
                } else {
                    menuOpenGoogle.href = "#";
                    menuOpenGoogle.setAttribute("aria-disabled", "true");
                }
            }
        }

        function updateEarningsTodayLive(addedFee) {
            const todayFeeEl = document.getElementById("earn-today-fee");
            const todaySubEl = todayFeeEl ? todayFeeEl.nextElementSibling : null;
            if (!todayFeeEl) return;

            // Parse current value (strip ₱ and commas)
            const currentFee = parseFloat(todayFeeEl.textContent.replace(/[^\d.]/g, "")) || 0;
            const newFee = currentFee + addedFee;
            todayFeeEl.textContent = "₱" + newFee.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            // Increment delivery count
            if (todaySubEl) {
                const match = todaySubEl.textContent.match(/\d+/);
                const oldCount = match ? parseInt(match[0], 10) : 0;
                const newCount = oldCount + 1;
                todaySubEl.textContent = `${newCount} deliver${newCount === 1 ? "y" : "ies"}`;
            }

            // Flash animation
            if (todayFeeEl.closest) {
                const card = todayFeeEl.closest(".earnings-card");
                if (card) {
                    card.style.transition = "background 0.2s";
                    card.style.background = "rgba(255,255,255,0.40)";
                    setTimeout(() => { card.style.background = ""; }, 700);
                }
            }
        }

        function renderOrders(orders) {
            if (!ordersListEl) return;
            const list = Array.isArray(orders) ? orders : [];

            let filtered = list;
            if (activeStatusFilter !== "all") {
                filtered = filtered.filter(o => o.status === activeStatusFilter);
            }
            if (searchQuery.trim() !== "") {
                const q = searchQuery.toLowerCase();
                filtered = filtered.filter(o =>
                    String(o.id).includes(q) ||
                    (o.customer_name || "").toLowerCase().includes(q) ||
                    (o.store_name || "").toLowerCase().includes(q) ||
                    (o.delivery_address || "").toLowerCase().includes(q)
                );
            }

            if (!filtered.length) {
                ordersListEl.innerHTML = `<div style="text-align:center; padding: 40px 20px; color:#64748B; font-size:14px;">No active orders found.</div>`;
                return;
            }

            ordersListEl.innerHTML = filtered.map(order => {
                const isSelected = selectedOrderId === order.id;
                const items = Array.isArray(order.items) ? order.items : [];
                const itemSummary = items.map(it => `${escapeHtml(it.product_name)} × ${it.quantity}`).join(", ");
                const pickupAddress = order.store_address || (order.store_lat ? `Lat ${Number(order.store_lat).toFixed(4)}, Lng ${Number(order.store_lng).toFixed(4)}` : "Store location");
                const deliveryAddress = order.delivery_address || (order.delivery_lat ? `Lat ${Number(order.delivery_lat).toFixed(4)}, Lng ${Number(order.delivery_lng).toFixed(4)}` : "Customer location");

                let actionHtml = "";
                if (order.status === "pending") {
                    actionHtml = `
                        <button type="button" class="driver-btn btn-primary" data-action="accept" data-order-id="${order.id}">Accept</button>
                        <button type="button" class="driver-btn btn-decline" data-action="decline" data-order-id="${order.id}">Decline</button>
                    `;
                } else if (order.status === "delivering") {
                    actionHtml = `
                        <button type="button" class="driver-btn" data-action="route-delivery" data-order-id="${order.id}">Route to customer</button>
                        <button type="button" class="driver-btn btn-success" data-action="complete" data-order-id="${order.id}">Complete</button>
                    `;
                }

                return `
                    <article class="driver-order-card ${isSelected ? 'active' : ''}" data-order-id="${order.id}">
                        <div class="driver-card-header">
                            <h3 class="driver-card-id">Order #${order.id}</h3>
                            <span class="driver-status-badge status-${order.status}">${order.status.replace("_", " ")}</span>
                        </div>

                        <div class="driver-route-block">
                            <div class="driver-route-row">
                                <span class="driver-route-dot dot-store"></span>
                                <div class="driver-route-text">
                                    <span class="driver-route-label">Pickup</span>
                                    <p class="driver-route-val">${escapeHtml(order.store_name)}</p>
                                    <p class="driver-route-sub">${escapeHtml(pickupAddress)}</p>
                                </div>
                            </div>
                            <div class="driver-route-row">
                                <span class="driver-route-dot dot-customer"></span>
                                <div class="driver-route-text">
                                    <span class="driver-route-label">Delivery</span>
                                    <p class="driver-route-val">${escapeHtml(order.customer_name)}</p>
                                    <p class="driver-route-sub">${escapeHtml(deliveryAddress)}</p>
                                </div>
                            </div>
                        </div>

                        ${items.length ? `<div style="font-size:12.5px; color:#64748B;"><strong>Items:</strong> ${itemSummary}</div>` : ''}

                        <div class="driver-order-amount">
                            <span>Delivery fee: ${formatMoney(order.delivery_fee)}</span>
                            <span class="driver-order-total">${formatMoney(order.total_amount)}</span>
                        </div>

                        <div class="driver-actions-grid">
                            ${actionHtml}
                        </div>
                    </article>
                `;
            }).join("");

            if (!selectedOrderId) {
                const first = list.find(o => o.status === "delivering");
                if (first) {
                    selectedOrderId = first.id;
                    selectedRouteMode = "delivery";
                    drawRouteForOrder(first, selectedRouteMode);
                }
            }
        }

        function renderLiveData(payload, forceCenter = false) {
            latestPayload = payload;
            const current = payload.current;

            if (current && current.lat !== null && current.lng !== null && Number(current.lat) !== 0) {
                const latLng = [Number(current.lat), Number(current.lng)];
                if (liveMarker) {
                    liveMarker.setLatLng(latLng);
                } else {
                    liveMarker = L.marker(latLng, { icon: driverIcon, zIndexOffset: 1000 }).addTo(map)
                        .bindPopup("<strong>Driver Location</strong>");
                }

                if (!hasCenteredInitially || forceCenter) {
                    map.setView(latLng, 16);
                    hasCenteredInitially = true;
                }
            }

            renderOrders(payload.orders || []);
        }

        // ── Modal helpers ──
        const confirmModal = document.getElementById("delivery-confirm-modal");
        const successModal = document.getElementById("delivery-success-modal");
        const confirmOrderId = document.getElementById("confirm-order-id");
        const successOrderId = document.getElementById("success-order-id");
        const confirmOkBtn = document.getElementById("confirm-modal-ok");
        const confirmCancel = document.getElementById("confirm-modal-cancel");
        const successDone = document.getElementById("success-modal-done");
        let pendingDeliveryOrderId = null;

        function openModal(el) {
            el.classList.add("open");
            el.focus && el.focus();
        }
        function closeModal(el) {
            el.classList.remove("open");
        }

        // Close confirm modal on cancel
        confirmCancel.addEventListener("click", () => closeModal(confirmModal));
        confirmModal.addEventListener("click", (e) => {
            if (e.target === confirmModal) closeModal(confirmModal);
        });

        // Confirm delivery action
        confirmOkBtn.addEventListener("click", () => {
            if (pendingDeliveryOrderId === null) return;
            closeModal(confirmModal);
            confirmOkBtn.disabled = true;
            confirmOkBtn.textContent = "Marking…";
            submitOrderAction(pendingDeliveryOrderId, "complete");
        });

        // Success modal done → reload
        successDone.addEventListener("click", () => {
            closeModal(successModal);
            window.location.reload();
        });
        successModal.addEventListener("click", (e) => {
            if (e.target === successModal) {
                closeModal(successModal);
                window.location.reload();
            }
        });

        // ESC closes any open modal
        document.addEventListener("keydown", (e) => {
            if (e.key !== "Escape") return;
            if (confirmModal.classList.contains("open")) closeModal(confirmModal);
            if (successModal.classList.contains("open")) { closeModal(successModal); window.location.reload(); }
        });

        async function submitOrderAction(orderId, action) {
            try {
                const res = await fetch("driver_dashboard.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ order_id: orderId, action })
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    // Re-enable confirm button if failed
                    confirmOkBtn.disabled = false;
                    confirmOkBtn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Confirm Delivery`;
                    alert(data.message || "Failed to update order");
                    return;
                }
                if (action === "complete") {
                    selectedOrderId = null;
                    clearRoute();
                    // Reset confirm button state
                    confirmOkBtn.disabled = false;
                    confirmOkBtn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Confirm Delivery`;
                    // Update earnings panel with delivery fee from completed order
                    const completedOrder = (data.payload?.orders || latestPayload?.orders || []).find(o => o.id === orderId);
                    const fee = completedOrder ? (completedOrder.delivery_fee || 0) : 0;
                    updateEarningsTodayLive(fee);
                    // Show success modal
                    successOrderId.textContent = orderId;
                    openModal(successModal);
                    return;
                }
                renderLiveData(data.payload || latestPayload);
            } catch (err) {
                confirmOkBtn.disabled = false;
                confirmOkBtn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Confirm Delivery`;
                alert("Network error updating order.");
            }
        }

        async function fetchFreshData(forceCenter = false) {
            if (isRefreshing) return;
            isRefreshing = true;
            try {
                const res = await fetch(`driver_dashboard.php?format=json&_=${Date.now()}`, { cache: "no-store" });
                if (!res.ok) throw new Error("HTTP error");
                const data = await res.json();
                renderLiveData(data, forceCenter);
            } catch (e) {
                // Network / GPS retry
            } finally {
                isRefreshing = false;
            }
        }

        // Event Bindings
        if (menuToggle && menuClose && menuBackdrop) {
            menuToggle.addEventListener("click", openMenu);
            menuClose.addEventListener("click", closeMenu);
            menuBackdrop.addEventListener("click", closeMenu);
        }

        function collapseOrdersSidebar() {
            document.body.classList.add("sidebar-collapsed");
            if (sidebar) {
                sidebar.classList.remove("sidebar-open");
            }
            if (sidebarExpandBtn) {
                sidebarExpandBtn.hidden = false;
            }
            setTimeout(() => map.invalidateSize(), 360);
        }

        function expandOrdersSidebar() {
            document.body.classList.remove("sidebar-collapsed");
            if (sidebar) {
                sidebar.classList.add("sidebar-open");
            }
            if (sidebarExpandBtn) {
                sidebarExpandBtn.hidden = true;
            }
            setTimeout(() => map.invalidateSize(), 360);
        }

        if (sidebarCollapseBtn && sidebarExpandBtn) {
            sidebarCollapseBtn.addEventListener("click", collapseOrdersSidebar);
            sidebarExpandBtn.addEventListener("click", () => {
                if (window.innerWidth <= 768 && sidebar && sidebar.classList.contains("sidebar-open")) {
                    collapseOrdersSidebar();
                } else {
                    expandOrdersSidebar();
                }
            });
        }

        if (window.innerWidth <= 768 && sidebar) {
            sidebar.classList.remove("sidebar-open");
        }

        if (window.innerWidth <= 768 && map) {
            map.on("click", () => {
                if (sidebar && sidebar.classList.contains("sidebar-open")) {
                    collapseOrdersSidebar();
                }
            });
        }

        document.getElementById("map-recenter-btn").addEventListener("click", () => {
            if (liveMarker) {
                map.flyTo(liveMarker.getLatLng(), 16, { duration: 0.5 });
            } else if (latestPayload.current && latestPayload.current.lat !== null) {
                map.flyTo([Number(latestPayload.current.lat), Number(latestPayload.current.lng)], 16, { duration: 0.5 });
            } else if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition((pos) => {
                    const latLng = [pos.coords.latitude, pos.coords.longitude];
                    if (liveMarker) {
                        liveMarker.setLatLng(latLng);
                    } else {
                        liveMarker = L.marker(latLng, { icon: driverIcon, zIndexOffset: 1000 }).addTo(map)
                            .bindPopup("<strong>Driver Location (GPS)</strong>");
                    }
                    map.flyTo(latLng, 16, { duration: 0.5 });
                }, (err) => {
                    console.warn("Geolocation error:", err);
                }, { enableHighAccuracy: true });
            }
        });

        document.getElementById("menu-center-live")?.addEventListener("click", () => {
            if (liveMarker) map.flyTo(liveMarker.getLatLng(), 16);
            closeMenu();
        });

        document.getElementById("menu-refresh-now")?.addEventListener("click", () => {
            fetchFreshData(false);
            closeMenu();
        });

        document.getElementById("driver-refresh-btn")?.addEventListener("click", () => {
            fetchFreshData(false);
        });

        document.querySelectorAll("#status-filter-pills .cat-pill").forEach(pill => {
            pill.addEventListener("click", () => {
                document.querySelectorAll("#status-filter-pills .cat-pill").forEach(p => p.classList.remove("active"));
                pill.classList.add("active");
                activeStatusFilter = pill.dataset.status;
                renderOrders(latestPayload.orders || []);
            });
        });

        searchInput?.addEventListener("input", (e) => {
            searchQuery = e.target.value;
            renderOrders(latestPayload.orders || []);
        });

        ordersListEl?.addEventListener("click", (e) => {
            const btn = e.target.closest("[data-action]");
            if (btn) {
                const action = btn.dataset.action;
                const orderId = Number(btn.dataset.orderId);
                const order = (latestPayload.orders || []).find(o => o.id === orderId);

                if (action === "route-store" || action === "route-delivery") {
                    selectedOrderId = orderId;
                    selectedRouteMode = action === "route-delivery" ? "delivery" : "store";
                    drawRouteForOrder(order, selectedRouteMode);
                    renderOrders(latestPayload.orders || []);
                    return;
                }

                if (action === "complete") {
                    pendingDeliveryOrderId = orderId;
                    confirmOrderId.textContent = orderId;
                    openModal(confirmModal);
                    return;
                }

                submitOrderAction(orderId, action);
                return;
            }

            const card = e.target.closest(".driver-order-card");
            if (card) {
                const orderId = Number(card.dataset.orderId);
                const order = (latestPayload.orders || []).find(o => o.id === orderId);
                if (order) {
                    selectedOrderId = orderId;
                    selectedRouteMode = order.status === "delivering" ? "delivery" : "store";
                    drawRouteForOrder(order, selectedRouteMode);
                    renderOrders(latestPayload.orders || []);
                }
            }
        });

        renderLiveData(livePayload, true);
        window.setTimeout(() => {
            map.invalidateSize();
        }, 200);
        setInterval(() => {
            fetchFreshData(false);
        }, refreshIntervalMs);
    </script>
</body>

</html>