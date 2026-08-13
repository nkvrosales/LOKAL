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
             o.status IN ('pending', 'for_pickup', 'delivering')
             OR (o.status = 'completed' AND DATE(o.delivered_at) = CURRENT_DATE())
           )
         ORDER BY 
           CASE o.status
             WHEN 'delivering' THEN 1
             WHEN 'for_pickup' THEN 2
             WHEN 'pending' THEN 3
             WHEN 'completed' THEN 4
             ELSE 5
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
        "accept" => ["from" => "pending", "to" => "for_pickup", "time_column" => "accepted_at"],
        "decline" => ["from" => "pending", "to" => "declined", "time_column" => "declined_at"],
        "pickup" => ["from" => "for_pickup", "to" => "delivering", "time_column" => "pickup_at"],
        "complete" => ["from" => "delivering", "to" => "completed", "time_column" => "delivered_at"],
    ];

    if (!isset($transitions[$action]) || $orderId <= 0) {
        return ["ok" => false, "message" => "Invalid order action."];
    }

    $transition = $transitions[$action];
    $timeColumn = $transition["time_column"];
    $stmt = $mysqli->prepare(
        "UPDATE orders
         SET status = ?, {$timeColumn} = CURRENT_TIMESTAMP
         WHERE id = ? AND status = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return ["ok" => false, "message" => "Unable to update order."];
    }

    $stmt->bind_param("sis", $transition["to"], $orderId, $transition["from"]);
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        .driver-orders-sidebar {
            width: 380px;
            min-width: 380px;
            height: 100vh;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(24px);
            border-right: 1px solid #E2E8F0;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            flex-shrink: 0;
            box-sizing: border-box;
            transition: margin-left 0.35s cubic-bezier(0.16, 1, 0.3, 1), transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 10px 0 30px rgba(15, 23, 42, 0.06);
        }

        .sidebar-collapsed .driver-orders-sidebar {
            margin-left: -380px;
        }

        .driver-order-card {
            padding: 16px;
            border-radius: 16px;
            border: 1.5px solid #E2E8F0;
            background: #FFFFFF;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: all 0.2s ease;
            box-sizing: border-box;
            width: 100%;
        }

        .driver-order-card:hover {
            border-color: #CBD5E1;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
        }

        .driver-order-card.active {
            border-color: var(--primary);
            box-shadow: 0 0 0 1px var(--primary), 0 6px 16px var(--primary-light);
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

        .status-pending { background: #FEF3C7; color: #B45309; }
        .status-for_pickup { background: #DBEAFE; color: #1D4ED8; }
        .status-delivering { background: #D1FAE5; color: #047857; }
        .status-completed { background: #F1F5F9; color: #475569; }

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

        .dot-store { background: var(--primary); }
        .dot-customer { background: var(--secondary); }

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
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #FFFFFF;
            border: 1.5px solid #E2E8F0;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
            transition: all 0.2s ease;
        }

        .map-locate-action:hover {
            background: var(--primary-light);
            border-color: var(--primary);
            transform: scale(1.05);
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
                    <button type="button" id="sidebar-collapse-btn" class="sidebar-collapse-btn" title="Hide sidebar" aria-label="Hide sidebar">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="15 18 9 12 15 6"></polyline>
                        </svg>
                    </button>
                </div>

                <div class="sidebar-search-row">
                    <input type="text" id="driver-search-input" class="sidebar-search-input" placeholder="Search order #, customer, store" autocomplete="off">
                    <button type="button" id="driver-refresh-btn" class="sidebar-locate-btn" title="Refresh data" aria-label="Refresh data">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <polyline points="1 20 1 14 7 14"></polyline>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                    </button>
                </div>

                <div class="sidebar-categories" id="status-filter-pills">
                    <button type="button" class="cat-pill active" data-status="all">All</button>
                    <button type="button" class="cat-pill" data-status="pending">Pending</button>
                    <button type="button" class="cat-pill" data-status="for_pickup">For Pickup</button>
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

            <button id="sidebar-expand-btn" class="sidebar-expand-btn" type="button" aria-label="Show orders sidebar" hidden>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="9 18 15 12 9 6"></polyline>
                </svg>
                <span>Orders</span>
            </button>

            <!-- Recenter GPS Action Button -->
            <button type="button" class="map-locate-action" id="map-recenter-btn" title="Center GPS location" aria-label="Center GPS location">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="3 11 22 2 13 21 11 13 3 11"></polygon>
                </svg>
            </button>

            <!-- Navigation Drawer Toggle -->
            <button id="menu-toggle" class="menu-toggle" type="button" aria-controls="menu-drawer" aria-expanded="false" aria-label="Open menu">
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
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
                        <span>Center to latest GPS</span>
                    </button>
                    <button type="button" class="menu-link" id="menu-refresh-now">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                        <span>Refresh now</span>
                    </button>
                    <a class="menu-link" id="menu-open-google" href="<?php echo escape_value($googleMapsUrl); ?>" target="_blank" rel="noopener" <?php echo $googleMapsUrl === "" ? 'aria-disabled="true"' : ""; ?>>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                        <span>Open in Google Maps</span>
                    </a>
                </section>

                <section class="menu-section">
                    <h3>Live GPS Feed</h3>
                    <p class="menu-meta" id="drawer-feed-status"><?php echo $current ? "Receiving GPS updates from gps_logs." : "No GPS rows with coordinates yet."; ?></p>
                    <p class="menu-meta" id="drawer-device">Device: <?php echo escape_value(display_value($current["device"] ?? null)); ?></p>
                    <p class="menu-meta" id="drawer-status">Status: <?php echo escape_value(display_value($current["status"] ?? null)); ?></p>
                    <p class="menu-meta" id="drawer-device-time">Device time: <?php echo escape_value(display_value($current["device_time"] ?? null)); ?></p>
                    <p class="menu-meta" id="drawer-server-time">Server time: <?php echo escape_value(display_value($current["created_at"] ?? null)); ?></p>
                </section>

                <section class="menu-section">
                    <h3>Account</h3>
                    <div class="user-card-info">
                        <div class="user-avatar-circle"><?php echo strtoupper(substr($driverName, 0, 1)); ?></div>
                        <div>
                            <p class="menu-user-name"><?php echo escape_value($driverName); ?></p>
                            <p class="menu-user-role">Delivery Rider</p>
                        </div>
                    </div>
                    <a class="menu-link menu-logout" href="logout.php">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <span>Log out</span>
                    </a>
                </section>
            </aside>

            <div class="menu-backdrop" id="menu-backdrop"></div>
        </main>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const livePayload = <?php echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const refreshIntervalMs = (livePayload.refresh_interval_seconds || 10) * 1000;
        const defaultCenter = [14.5995, 120.9842];

        const map = L.map("home-map", { zoomControl: false }).setView(defaultCenter, 13);
        L.control.zoom({ position: "bottomright" }).addTo(map);

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "&copy; OpenStreetMap contributors"
        }).addTo(map);

        const driverIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker driver">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="6.5" cy="17.5" r="2.5"></circle>
                        <circle cx="17.5" cy="17.5" r="2.5"></circle>
                        <path d="M9 7H6"></path>
                        <path d="M8.5 10.5 11 17.5"></path>
                        <path d="M11 10.5h4l2.5 7"></path>
                        <path d="M10.5 10.5 14 7.5"></path>
                    </svg>
                </div>`,
            iconSize: [34, 34],
            iconAnchor: [17, 34],
            popupAnchor: [0, -30]
        });

        const storeIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker" style="background:#FFFFFF; color:var(--primary); border-color:var(--primary);">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                </div>`,
            iconSize: [34, 34],
            iconAnchor: [17, 34],
            popupAnchor: [0, -30]
        });

        const customerIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker" style="background:#FFFFFF; color:var(--secondary); border-color:var(--secondary);">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                        <circle cx="12" cy="10" r="3"></circle>
                    </svg>
                </div>`,
            iconSize: [34, 34],
            iconAnchor: [17, 34],
            popupAnchor: [0, -30]
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
                } else if (order.status === "for_pickup") {
                    actionHtml = `
                        <button type="button" class="driver-btn" data-action="route-store" data-order-id="${order.id}">Route to store</button>
                        <button type="button" class="driver-btn btn-primary" data-action="pickup" data-order-id="${order.id}">Picked up</button>
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
                const first = list.find(o => o.status === "for_pickup" || o.status === "delivering");
                if (first) {
                    selectedOrderId = first.id;
                    selectedRouteMode = first.status === "delivering" ? "delivery" : "store";
                    drawRouteForOrder(first, selectedRouteMode);
                }
            }
        }

        function renderLiveData(payload, forceCenter = false) {
            latestPayload = payload;
            const current = payload.current;

            if (current && current.lat !== null && current.lng !== null) {
                hudStatusText.textContent = `GPS Connected (${current.device || "Unit"})`;
                liveIndicatorDot.style.background = "#10B981";

                const latLng = [Number(current.lat), Number(current.lng)];
                if (liveMarker) {
                    liveMarker.setLatLng(latLng);
                } else {
                    liveMarker = L.marker(latLng, { icon: driverIcon }).addTo(map)
                        .bindPopup("<strong>Your Location</strong>");
                }

                if (!hasCenteredInitially || forceCenter) {
                    map.setView(latLng, 15);
                    hasCenteredInitially = true;
                }
            }

            renderOrders(payload.orders || []);
        }

        async function submitOrderAction(orderId, action) {
            try {
                const res = await fetch("driver_dashboard.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ order_id: orderId, action })
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    alert(data.message || "Failed to update order");
                    return;
                }
                if (action === "complete") {
                    selectedOrderId = null;
                    clearRoute();
                    alert("Thank you for the delivery!");
                    window.location.reload();
                    return;
                }
                renderLiveData(data.payload || latestPayload);
            } catch (err) {
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

        if (sidebarCollapseBtn && sidebarExpandBtn) {
            sidebarCollapseBtn.addEventListener("click", () => {
                document.body.classList.add("sidebar-collapsed");
                sidebarExpandBtn.hidden = false;
                setTimeout(() => map.invalidateSize(), 360);
            });
            sidebarExpandBtn.addEventListener("click", () => {
                document.body.classList.remove("sidebar-collapsed");
                sidebarExpandBtn.hidden = true;
                setTimeout(() => map.invalidateSize(), 360);
            });
        }

        document.getElementById("map-recenter-btn").addEventListener("click", () => {
            if (liveMarker) {
                map.flyTo(liveMarker.getLatLng(), 16, { duration: 0.5 });
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
                    if (confirm(`Mark Order #${orderId} as Delivered?`)) {
                        submitOrderAction(orderId, action);
                    }
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
        setInterval(() => {
            fetchFreshData(false);
        }, refreshIntervalMs);
    </script>
</body>
</html>
