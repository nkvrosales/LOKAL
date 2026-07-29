<?php
require_once __DIR__ . "/db_connect.php";

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

function fetch_driver_orders(mysqli $mysqli): array
{
    $orders = [];
    $result = $mysqli->query(
        "SELECT o.id, o.customer_name, u.contact AS customer_contact, o.delivery_address,
                o.store_name, o.store_address, o.store_lat, o.store_lng,
                o.delivery_lat, o.delivery_lng, o.total_amount, o.status, o.created_at, o.accepted_at, o.pickup_at
         FROM orders o
         LEFT JOIN users u ON u.id = o.customer_user_id
         WHERE o.order_type = 'delivery'
           AND status IN ('pending', 'for_pickup', 'delivering')
         ORDER BY FIELD(status, 'for_pickup', 'delivering', 'pending'), created_at ASC
         LIMIT 25"
    );

    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $orderId = (int) $row["id"];
        $orders[$orderId] = [
            "id" => $orderId,
            "customer_name" => (string) ($row["customer_name"] ?? "Customer"),
            "customer_contact" => (string) ($row["customer_contact"] ?? ""),
            "delivery_address" => (string) ($row["delivery_address"] ?? ""),
            "store_name" => (string) ($row["store_name"] ?? "Store"),
            "store_address" => (string) ($row["store_address"] ?? ""),
            "store_lat" => isset($row["store_lat"]) ? (float) $row["store_lat"] : null,
            "store_lng" => isset($row["store_lng"]) ? (float) $row["store_lng"] : null,
            "delivery_lat" => isset($row["delivery_lat"]) ? (float) $row["delivery_lat"] : null,
            "delivery_lng" => isset($row["delivery_lng"]) ? (float) $row["delivery_lng"] : null,
            "total_amount" => isset($row["total_amount"]) ? (float) $row["total_amount"] : 0,
            "status" => (string) ($row["status"] ?? "pending"),
            "created_at" => (string) ($row["created_at"] ?? ""),
            "accepted_at" => (string) ($row["accepted_at"] ?? ""),
            "pickup_at" => (string) ($row["pickup_at"] ?? ""),
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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
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
$initialStatus = $current
    ? "Live GPS connected. Last device update: " . display_value($current["created_at"])
    : "Waiting for GPS logs with latitude and longitude.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Dashboard | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/home.css?v=hover-effects-1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
</head>
<body class="home-screen driver-dashboard-screen">
    <main class="home-map-shell">
        <div id="home-map"></div>

        <button id="menu-toggle" class="menu-toggle" type="button" aria-controls="menu-drawer" aria-expanded="false" aria-label="Open menu">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <div class="map-status-chip" id="map-status"><?php echo escape_value($initialStatus); ?></div>

        <aside class="menu-drawer" id="menu-drawer" aria-hidden="true">
            <div class="menu-head">
                <h2>Driver Live</h2>
                <button class="menu-close" id="menu-close" type="button" aria-label="Close menu">&times;</button>
            </div>

            <section class="menu-section">
                <h3>Controls</h3>
                <button type="button" class="menu-link" id="menu-center-live">Center to latest GPS</button>
                <button type="button" class="menu-link" id="menu-refresh-now">Refresh now</button>
                <a
                    class="menu-link"
                    id="menu-open-google"
                    href="<?php echo escape_value($googleMapsUrl); ?>"
                    target="_blank"
                    rel="noopener"
                    <?php echo $googleMapsUrl === "" ? 'aria-disabled="true"' : ""; ?>
                >Open in Google Maps</a>
            </section>

            <section class="menu-section">
                <h3>Live Feed</h3>
                <p class="menu-meta" id="drawer-feed-status"><?php echo $current ? "Receiving GPS updates from gps_logs." : "No GPS rows with coordinates yet."; ?></p>
                <p class="menu-meta" id="drawer-device">Device: <?php echo escape_value(display_value($current["device"] ?? null)); ?></p>
                <p class="menu-meta" id="drawer-status">Status: <?php echo escape_value(display_value($current["status"] ?? null)); ?></p>
                <p class="menu-meta" id="drawer-device-time">Device time: <?php echo escape_value(display_value($current["device_time"] ?? null)); ?></p>
                <p class="menu-meta" id="drawer-server-time">Server time: <?php echo escape_value(display_value($current["created_at"] ?? null)); ?></p>
            </section>

            <section class="menu-section">
                <h3>Polling</h3>
                <p class="menu-meta">Source table: gps_logs</p>
                <p class="menu-meta">Refresh interval: every 10 seconds</p>
                <p class="menu-meta">Map target: latest GPS coordinate only</p>
            </section>
        </aside>

        <div class="menu-backdrop" id="menu-backdrop"></div>

        <section class="driver-dashboard-panel" id="driver-dashboard-panel">
            <div class="driver-panel-head">
                <div>
                    <h1>Driver Dashboard</h1>
                    <p>Accept orders, follow pickup routes, and switch to delivery once the order is picked up.</p>
                </div>
                <div class="driver-panel-controls">
                    <div class="driver-live-pill">
                        <span class="driver-live-dot" id="driver-live-dot"></span>
                        <span id="live-pill-text">Polling every 10 seconds</span>
                    </div>
                    <button
                        type="button"
                        class="driver-panel-toggle"
                        id="driver-panel-toggle"
                        aria-controls="driver-panel-body"
                        aria-expanded="true"
                    >Minimize</button>
                </div>
            </div>

            <div class="driver-panel-body" id="driver-panel-body">
                <section class="driver-orders-section">
                    <div class="driver-orders-head">
                        <div>
                            <h2>Orders</h2>
                            <p id="driver-orders-summary">Waiting for customer checkout.</p>
                        </div>
                        <button type="button" class="store-home-link driver-action-btn" id="orders-refresh-now">Refresh orders</button>
                    </div>
                    <div class="driver-orders-list" id="driver-orders-list"></div>
                </section>

                <div class="store-home-actions">
                    <button type="button" class="store-home-link driver-action-btn" id="panel-center-live">Center to latest GPS</button>
                    <button type="button" class="store-home-link driver-action-btn" id="panel-refresh-now">Refresh now</button>
                    <a
                        class="store-home-link"
                        id="panel-open-google"
                        href="<?php echo escape_value($googleMapsUrl); ?>"
                        target="_blank"
                        rel="noopener"
                        <?php echo $googleMapsUrl === "" ? 'aria-disabled="true"' : ""; ?>
                    >Open in Google Maps</a>
                </div>
            </div>
        </section>
    </main>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const livePayload = <?php echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const refreshIntervalMs = (livePayload.refresh_interval_seconds || 10) * 1000;
        const defaultCenter = [14.5995, 120.9842];

        const map = L.map("home-map", { zoomControl: false }).setView(defaultCenter, 12);
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

        const statusEl = document.getElementById("map-status");
        const menuDrawer = document.getElementById("menu-drawer");
        const menuToggle = document.getElementById("menu-toggle");
        const menuClose = document.getElementById("menu-close");
        const menuBackdrop = document.getElementById("menu-backdrop");
        const liveDot = document.getElementById("driver-live-dot");
        const livePillText = document.getElementById("live-pill-text");
        const driverPanel = document.getElementById("driver-dashboard-panel");
        const driverPanelBody = document.getElementById("driver-panel-body");
        const driverPanelToggle = document.getElementById("driver-panel-toggle");
        const drawerFeedStatus = document.getElementById("drawer-feed-status");
        const drawerDevice = document.getElementById("drawer-device");
        const drawerStatus = document.getElementById("drawer-status");
        const drawerDeviceTime = document.getElementById("drawer-device-time");
        const drawerServerTime = document.getElementById("drawer-server-time");
        const detailIds = {
            device: document.getElementById("detail-device"),
            status: document.getElementById("detail-status"),
            coordinates: document.getElementById("detail-coordinates"),
            sat: document.getElementById("detail-sat"),
            hdop: document.getElementById("detail-hdop"),
            valid: document.getElementById("detail-valid"),
            device_time: document.getElementById("detail-device-time"),
            created_at: document.getElementById("detail-server-time"),
            chars_count: document.getElementById("detail-chars"),
            updated: document.getElementById("detail-updated")
        };
        const panelNote = document.getElementById("driver-panel-note");
        const menuOpenGoogle = document.getElementById("menu-open-google");
        const panelOpenGoogle = document.getElementById("panel-open-google");
        const ordersListEl = document.getElementById("driver-orders-list");
        const ordersSummaryEl = document.getElementById("driver-orders-summary");

        let liveMarker = null;
        let storeTargetMarker = null;
        let deliveryTargetMarker = null;
        let routeLayer = null;
        let hasInitializedView = false;
        let isRefreshing = false;
        let lastRenderedGpsId = 0;
        let latestPayload = livePayload;
        let selectedOrderId = null;
        let selectedRouteMode = "store";

        function setStatus(message) {
            if (statusEl) {
                statusEl.textContent = message;
            }
        }

        function openMenu() {
            if (!menuDrawer || !menuToggle) {
                return;
            }
            document.body.classList.add("menu-open");
            menuDrawer.setAttribute("aria-hidden", "false");
            menuToggle.setAttribute("aria-expanded", "true");
        }

        function closeMenu() {
            if (!menuDrawer || !menuToggle) {
                return;
            }
            document.body.classList.remove("menu-open");
            menuDrawer.setAttribute("aria-hidden", "true");
            menuToggle.setAttribute("aria-expanded", "false");
        }

        function formatValue(value, fallback = "--") {
            if (value === null || value === undefined || value === "") {
                return fallback;
            }
            return String(value);
        }

        function formatCoordinatePair(current) {
            if (!current || !Number.isFinite(Number(current.lat)) || !Number.isFinite(Number(current.lng))) {
                return "--";
            }
            return `${Number(current.lat).toFixed(6)}, ${Number(current.lng).toFixed(6)}`;
        }

        function hasCoordinates(current) {
            return !!current
                && Number.isFinite(Number(current.lat))
                && Number.isFinite(Number(current.lng));
        }

        function updateLinkState(linkEl, href) {
            if (!linkEl) {
                return;
            }
            if (href) {
                linkEl.href = href;
                linkEl.removeAttribute("aria-disabled");
            } else {
                linkEl.href = "#";
                linkEl.setAttribute("aria-disabled", "true");
            }
        }

        function setDetailValue(key, value, options = {}) {
            const el = detailIds[key];
            if (!el) {
                return;
            }
            el.textContent = value;
            el.classList.toggle("muted", !!options.muted);
        }

        function centerToLive() {
            if (!liveMarker) {
                setStatus("No live GPS position to center yet.");
                return;
            }
            map.flyTo(liveMarker.getLatLng(), 17, { duration: 0.55 });
            setStatus("Centered to the latest GPS position.");
        }

        function bindPopupContent(current) {
            return `<strong>Your Delivery</strong>`;
        }

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, (char) => ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                "\"": "&quot;",
                "'": "&#39;"
            }[char]));
        }

        function formatMoney(value) {
            const amount = Number(value);
            return `PHP ${Number.isFinite(amount) ? amount.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) : "0.00"}`;
        }

        function formatOrderStatus(status) {
            return {
                pending: "Pending",
                for_pickup: "For Pickup",
                delivering: "Delivering"
            }[status] || status;
        }

        const deliveryAddressCache = new Map();

        function formatCoordinateAddress(lat, lng) {
            if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) {
                return "Address not available";
            }
            return `Pinned location: ${Number(lat).toFixed(6)}, ${Number(lng).toFixed(6)}`;
        }

        function getDeliveryAddressKey(order) {
            if (!order || !Number.isFinite(Number(order.delivery_lat)) || !Number.isFinite(Number(order.delivery_lng))) {
                return "";
            }
            return `${Number(order.delivery_lat).toFixed(7)},${Number(order.delivery_lng).toFixed(7)}`;
        }

        function escapeSelectorValue(value) {
            if (window.CSS && typeof window.CSS.escape === "function") {
                return window.CSS.escape(value);
            }
            return String(value).replace(/["\\]/g, "\\$&");
        }

        function updateDeliveryAddressText(order) {
            const key = getDeliveryAddressKey(order);
            if (!key) {
                return;
            }

            const elements = document.querySelectorAll(`[data-delivery-address-key="${escapeSelectorValue(key)}"]`);
            if (!elements.length) {
                return;
            }

            if (order.delivery_address && order.delivery_address !== "") {
                deliveryAddressCache.set(key, order.delivery_address);
                elements.forEach((element) => {
                    element.textContent = order.delivery_address;
                });
                return;
            }

            const fallback = formatCoordinateAddress(order.delivery_lat, order.delivery_lng);
            const cached = deliveryAddressCache.get(key);
            if (cached) {
                elements.forEach((element) => {
                    element.textContent = cached;
                });
                return;
            }

            deliveryAddressCache.set(key, fallback);
            elements.forEach((element) => {
                element.textContent = fallback;
            });

            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(order.delivery_lat)}&lon=${encodeURIComponent(order.delivery_lng)}&zoom=18&addressdetails=0`, {
                cache: "force-cache"
            })
                .then((response) => response.ok ? response.json() : null)
                .then((data) => {
                    const resolvedAddress = data && data.display_name ? String(data.display_name) : "";
                    if (!resolvedAddress) {
                        return;
                    }
                    deliveryAddressCache.set(key, resolvedAddress);
                    document.querySelectorAll(`[data-delivery-address-key="${escapeSelectorValue(key)}"]`).forEach((element) => {
                        element.textContent = resolvedAddress;
                    });
                })
                .catch(() => {
                    deliveryAddressCache.set(key, fallback);
                });
        }

        function getRouteTarget(order, mode = null) {
            if (!order) {
                return null;
            }
            const routeMode = mode || selectedRouteMode || (order.status === "delivering" ? "delivery" : "store");
            if (routeMode === "store") {
                return {
                    mode: "pickup",
                    label: "Route to store for pickup",
                    popup: `Pickup: ${order.store_name || "Store"}`,
                    lat: Number(order.store_lat),
                    lng: Number(order.store_lng)
                };
            }
            if (routeMode === "delivery") {
                return {
                    mode: "delivery",
                    label: "Route to customer",
                    popup: `Deliver to: ${order.customer_name || "Customer"}`,
                    lat: Number(order.delivery_lat),
                    lng: Number(order.delivery_lng)
                };
            }
            return null;
        }

        function clearRoute() {
            if (routeLayer) {
                map.removeLayer(routeLayer);
                routeLayer = null;
            }
            if (storeTargetMarker) {
                map.removeLayer(storeTargetMarker);
                storeTargetMarker = null;
            }
            if (deliveryTargetMarker) {
                map.removeLayer(deliveryTargetMarker);
                deliveryTargetMarker = null;
            }
        }

        function buildGoogleMapsPointUrl(current) {
            if (!hasCoordinates(current)) {
                return "";
            }
            return `https://www.google.com/maps?q=${current.lat},${current.lng}`;
        }

        function buildGoogleMapsDirectionsUrl(current, target) {
            if (!hasCoordinates(current) || !target || !Number.isFinite(target.lat) || !Number.isFinite(target.lng)) {
                return "";
            }

            const origin = `${Number(current.lat).toFixed(6)},${Number(current.lng).toFixed(6)}`;
            const destination = `${Number(target.lat).toFixed(6)},${Number(target.lng).toFixed(6)}`;
            return `https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent(origin)}&destination=${encodeURIComponent(destination)}&travelmode=driving`;
        }

        function getSelectedRouteOrder(orders) {
            const activeOrders = Array.isArray(orders)
                ? orders.filter((order) => order.status !== "declined")
                : [];
            return activeOrders.find((order) => order.id === selectedOrderId) || activeOrders[0] || null;
        }

        function updateGoogleMapsLinks() {
            const current = latestPayload.current || null;
            const order = getSelectedRouteOrder(latestPayload.orders || []);
            const target = order ? getRouteTarget(order, selectedRouteMode) : null;
            const href = target
                ? buildGoogleMapsDirectionsUrl(current, target)
                : buildGoogleMapsPointUrl(current);

            updateLinkState(menuOpenGoogle, href);
            updateLinkState(panelOpenGoogle, href);
            if (menuOpenGoogle) {
                menuOpenGoogle.textContent = target ? "Open active route in Google Maps" : "Open in Google Maps";
            }
            if (panelOpenGoogle) {
                panelOpenGoogle.textContent = target ? "Open active route in Google Maps" : "Open in Google Maps";
            }
        }

        async function drawRouteForOrder(order, mode = null) {
            const current = latestPayload.current || null;
            const target = getRouteTarget(order, mode);
            if (!hasCoordinates(current) || !target || !Number.isFinite(target.lat) || !Number.isFinite(target.lng)) {
                clearRoute();
                return;
            }

            const start = [Number(current.lat), Number(current.lng)];
            const end = [target.lat, target.lng];
            clearRoute();

            if (target.mode === "pickup") {
                storeTargetMarker = L.marker(end, { icon: driverIcon }).addTo(map).bindPopup(escapeHtml(target.popup || "Pickup store"));
            } else {
                deliveryTargetMarker = L.marker(end).addTo(map).bindPopup(escapeHtml(target.popup || "Customer delivery location"));
            }

            try {
                const url = `https://router.project-osrm.org/route/v1/driving/${start[1]},${start[0]};${end[1]},${end[0]}?overview=full&geometries=geojson`;
                const response = await fetch(url, { cache: "no-store" });
                const data = await response.json();
                const coordinates = data.routes && data.routes[0] && data.routes[0].geometry
                    ? data.routes[0].geometry.coordinates.map((point) => [point[1], point[0]])
                    : [start, end];
                routeLayer = L.polyline(coordinates, {
                    color: target.mode === "pickup" ? "#a80000" : "#1f8f5e",
                    weight: 5,
                    opacity: 0.86
                }).addTo(map);
                map.fitBounds(routeLayer.getBounds(), {
                    paddingTopLeft: [42, 90],
                    paddingBottomRight: [42, 330]
                });
                setStatus(target.label);
            } catch (error) {
                routeLayer = L.polyline([start, end], {
                    color: target.mode === "pickup" ? "#a80000" : "#1f8f5e",
                    weight: 5,
                    opacity: 0.75,
                    dashArray: "8 8"
                }).addTo(map);
                map.fitBounds(routeLayer.getBounds(), {
                    paddingTopLeft: [42, 90],
                    paddingBottomRight: [42, 330]
                });
                setStatus(`${target.label}. Showing direct fallback line.`);
            }
        }

        function renderOrders(orders) {
            if (!ordersListEl || !ordersSummaryEl) {
                return;
            }

            if (!Array.isArray(orders) || !orders.length) {
                ordersSummaryEl.textContent = "Waiting for customer checkout.";
                ordersListEl.innerHTML = `<p class="driver-orders-empty">No active orders.</p>`;
                selectedOrderId = null;
                clearRoute();
                updateGoogleMapsLinks();
                return;
            }

            const activeOrders = orders.filter((order) => order.status !== "declined");
            ordersSummaryEl.textContent = `${activeOrders.length} active order${activeOrders.length === 1 ? "" : "s"}.`;
            ordersListEl.innerHTML = activeOrders.map((order) => {
                const items = Array.isArray(order.items) ? order.items : [];
                const itemLines = items.map((item) => (
                    `<p>${escapeHtml(item.product_name)} x ${Number(item.quantity || 0)}</p>`
                )).join("");
                const pickupAddress = order.store_address && order.store_address !== ""
                    ? order.store_address
                    : formatCoordinateAddress(order.store_lat, order.store_lng);
                const deliveryKey = getDeliveryAddressKey(order);
                const deliveryAddress = order.delivery_address && order.delivery_address !== ""
                    ? order.delivery_address
                    : deliveryKey && deliveryAddressCache.has(deliveryKey)
                    ? deliveryAddressCache.get(deliveryKey)
                    : formatCoordinateAddress(order.delivery_lat, order.delivery_lng);
                const routeAddressClass = order.status === "delivering" ? "active" : "";
                const actions = order.status === "pending"
                    ? `<button type="button" data-order-action="accept" data-order-id="${order.id}">Accept</button>
                       <button type="button" data-order-action="decline" data-order-id="${order.id}">Decline</button>`
                    : order.status === "for_pickup"
                        ? `<button type="button" data-order-action="pickup" data-order-id="${order.id}">Picked up</button>`
                        : `<button type="button" data-order-action="complete" data-order-id="${order.id}">Complete</button>`;
                return `<article class="driver-order-card ${selectedOrderId === order.id ? "active" : ""}" data-order-id="${order.id}">
                            <div class="driver-order-top">
                                <div>
                                    <strong>Order #${order.id}</strong>
                                    <span>${escapeHtml(formatOrderStatus(order.status))}</span>
                                </div>
                                <b>${escapeHtml(formatMoney(order.total_amount))}</b>
                            </div>
                            <p class="driver-order-meta">Customer: ${escapeHtml(order.customer_name)}</p>
                            <p class="driver-order-meta">Contact: ${escapeHtml(order.customer_contact || "No contact listed")}</p>
                            <p class="driver-order-meta">Pickup: ${escapeHtml(order.store_name)}</p>
                            <div class="driver-order-route-details">
                                <p class="${order.status === "for_pickup" ? "active" : ""}">
                                    <strong>Pickup address:</strong>
                                    <span>${escapeHtml(pickupAddress)}</span>
                                </p>
                                <p class="${routeAddressClass}">
                                    <strong>Delivery address:</strong>
                                    <span data-delivery-address-key="${escapeHtml(deliveryKey)}">${escapeHtml(deliveryAddress)}</span>
                                </p>
                            </div>
                            <div class="driver-order-items">${itemLines || "<p>No items listed.</p>"}</div>
                            <div class="driver-order-actions">
                                <button type="button" data-order-action="route-store" data-order-id="${order.id}">Route to store</button>
                                <button type="button" data-order-action="route-delivery" data-order-id="${order.id}">Delivery route</button>
                                ${actions}
                            </div>
                        </article>`;
            }).join("");

            const previousSelectedOrderId = selectedOrderId;
            const selected = activeOrders.find((order) => order.id === selectedOrderId)
                || activeOrders.find((order) => order.status === "for_pickup" || order.status === "delivering");
            if (selected) {
                if (previousSelectedOrderId !== selected.id) {
                    selectedRouteMode = selected.status === "delivering" ? "delivery" : "store";
                }
                selectedOrderId = selected.id;
                drawRouteForOrder(selected, selectedRouteMode);
            }
            activeOrders.forEach(updateDeliveryAddressText);
            updateGoogleMapsLinks();
        }

        function renderLiveData(payload, options = {}) {
            latestPayload = payload;
            const current = payload.current || null;
            const history = Array.isArray(payload.history) ? payload.history : [];
            updateGoogleMapsLinks();

            drawerFeedStatus.textContent = current
                ? `Receiving GPS updates from ${payload.source || "gps_logs"}.`
                : "No GPS rows with coordinates yet.";
            drawerDevice.textContent = `Device: ${formatValue(current && current.device)}`;
            drawerStatus.textContent = `Status: ${formatValue(current && current.status)}`;
            drawerDeviceTime.textContent = `Device time: ${formatValue(current && current.device_time)}`;
            drawerServerTime.textContent = `Server time: ${formatValue(current && current.created_at)}`;

            setDetailValue("device", formatValue(current && current.device), { muted: !current });
            setDetailValue("status", formatValue(current && current.status), { muted: !current });
            setDetailValue("coordinates", formatCoordinatePair(current), { muted: !hasCoordinates(current) });
            setDetailValue("sat", formatValue(current && current.sat), { muted: !current });
            setDetailValue("hdop", formatValue(current && current.hdop), { muted: !current });
            setDetailValue("valid", formatValue(current && current.valid), { muted: !current });
            setDetailValue("device_time", formatValue(current && current.device_time), { muted: !current });
            setDetailValue("created_at", formatValue(current && current.created_at), { muted: !current });
            setDetailValue("chars_count", formatValue(current && current.chars_count), { muted: !current });
            setDetailValue("updated", formatValue(current && current.updated), { muted: !current });

            if (!current) {
                if (panelNote) {
                    panelNote.textContent = "The dashboard will update automatically once gps_logs receives coordinates.";
                }
                livePillText.textContent = "Waiting for GPS logs";
                liveDot.classList.add("is-stale");
                lastRenderedGpsId = 0;
                setStatus("Waiting for GPS logs with latitude and longitude.");
                return;
            }

            if (panelNote) {
                panelNote.textContent = `Latest GPS row ID ${current.id} loaded from gps_logs.`;
            }
            livePillText.textContent = `Last server update: ${formatValue(current.created_at)}`;
            liveDot.classList.remove("is-stale");

            if (hasCoordinates(current)) {
                const liveLatLng = [Number(current.lat), Number(current.lng)];
                const hasNewGpsRow = Number(current.id || 0) !== lastRenderedGpsId;

                if (liveMarker) {
                    liveMarker.setLatLng(liveLatLng);
                    liveMarker.setPopupContent(bindPopupContent(current));
                } else {
                    liveMarker = L.marker(liveLatLng, { icon: driverIcon }).addTo(map);
                    liveMarker.bindPopup(bindPopupContent(current));
                }

                if (!hasInitializedView || options.forceCenter || hasNewGpsRow) {
                    if (!hasInitializedView) {
                        map.setView(liveLatLng, 16);
                    } else {
                        map.flyTo(liveLatLng, Math.max(map.getZoom(), 16), { duration: 0.55 });
                    }
                    hasInitializedView = true;
                }

                lastRenderedGpsId = Number(current.id || 0);
                setStatus(`Live GPS ready for ${formatValue(current.device)} at ${formatCoordinatePair(current)}.`);
            } else {
                lastRenderedGpsId = Number(current.id || 0);
                setStatus("Latest GPS row does not include coordinates.");
            }

            renderOrders(payload.orders || []);
        }

        async function submitOrderAction(orderId, action) {
            try {
                const response = await fetch("driver_dashboard.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({ order_id: orderId, action })
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.message || "Unable to update order.");
                }
                if (action === "complete") {
                    selectedOrderId = null;
                    selectedRouteMode = "store";
                    clearRoute();
                    setStatus("Delivery completed.");
                    window.alert("Thank you for Delivery");
                    window.location.reload();
                    return;
                }
                renderLiveData(result.payload || latestPayload, { forceCenter: false });
                setStatus(result.message || "Order updated.");
            } catch (error) {
                setStatus(error.message || "Unable to update order.");
            }
        }

        async function refreshLiveData(forceCenter = false) {
            if (isRefreshing) {
                return;
            }

            isRefreshing = true;
            try {
                const response = await fetch(`driver_dashboard.php?format=json&_=${Date.now()}`, {
                    cache: "no-store"
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const payload = await response.json();
                renderLiveData(payload, { forceCenter });
            } catch (error) {
                liveDot.classList.add("is-stale");
                livePillText.textContent = "Refresh failed. Retrying.";
                setStatus("Unable to fetch the latest GPS data. Retrying in 10 seconds.");
            } finally {
                isRefreshing = false;
            }
        }

        if (menuToggle && menuClose && menuBackdrop) {
            menuToggle.addEventListener("click", openMenu);
            menuClose.addEventListener("click", closeMenu);
            menuBackdrop.addEventListener("click", closeMenu);
        }

        if (driverPanel && driverPanelBody && driverPanelToggle) {
            driverPanelToggle.addEventListener("click", () => {
                const isMinimized = driverPanel.classList.toggle("is-minimized");
                driverPanelBody.hidden = isMinimized;
                driverPanelToggle.textContent = isMinimized ? "Show panel" : "Minimize";
                driverPanelToggle.setAttribute("aria-expanded", String(!isMinimized));
                window.setTimeout(() => {
                    map.invalidateSize();
                }, 220);
            });
        }

        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                closeMenu();
            }
        });

        [
            document.getElementById("menu-center-live"),
            document.getElementById("panel-center-live")
        ].forEach((button) => {
            if (button) {
                button.addEventListener("click", () => {
                    centerToLive();
                    closeMenu();
                });
            }
        });

        [
            document.getElementById("menu-refresh-now"),
            document.getElementById("panel-refresh-now"),
            document.getElementById("orders-refresh-now")
        ].forEach((button) => {
            if (button) {
                button.addEventListener("click", () => {
                    refreshLiveData(false);
                    closeMenu();
                });
            }
        });

        if (ordersListEl) {
            ordersListEl.addEventListener("click", (event) => {
                const button = event.target.closest("[data-order-action]");
                if (!button) {
                    return;
                }

                const orderId = Number(button.dataset.orderId || 0);
                const action = button.dataset.orderAction || "";
                const order = (latestPayload.orders || []).find((item) => Number(item.id) === orderId);
                if (!order) {
                    return;
                }

                selectedOrderId = orderId;
                if (action === "route-store" || action === "route-delivery") {
                    selectedRouteMode = action === "route-delivery" ? "delivery" : "store";
                    drawRouteForOrder(order, selectedRouteMode);
                    renderOrders(latestPayload.orders || []);
                    updateGoogleMapsLinks();
                    return;
                }

                submitOrderAction(orderId, action);
            });
        }

        renderLiveData(livePayload, { forceCenter: false });
        window.setInterval(() => {
            refreshLiveData(false);
        }, refreshIntervalMs);
    </script>
</body>
</html>
