<?php
require_once "common.php";

$isBootstrapMode = !admin_has_admin_account($mysqli);

$errors = [];
$newAdmin = [
    "first_name" => "",
    "middle_name" => "",
    "last_name" => "",
    "contact" => "",
    "email" => "",
];

if ($isBootstrapMode && $_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($newAdmin as $field => $_) {
        $newAdmin[$field] = trim($_POST[$field] ?? "");
    }
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if ($newAdmin["first_name"] === "" || $newAdmin["middle_name"] === "" || $newAdmin["last_name"] === "") {
        $errors[] = "Complete admin name is required.";
    }
    if ($newAdmin["contact"] === "") {
        $errors[] = "Contact is required.";
    }
    if (!filter_var($newAdmin["email"], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email is required.";
    }
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    if (!$errors) {
        $check = $mysqli->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if ($check) {
            $check->bind_param("s", $newAdmin["email"]);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $errors[] = "Email is already registered.";
            }
            $check->close();
        }
    }

    if (!$errors) {
        $accountType = "admin";
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare(
            "INSERT INTO users (account_type, first_name, middle_name, last_name, contact, email, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param(
                "sssssss",
                $accountType,
                $newAdmin["first_name"],
                $newAdmin["middle_name"],
                $newAdmin["last_name"],
                $newAdmin["contact"],
                $newAdmin["email"],
                $hash
            );
            if ($stmt->execute()) {
                header("Location: login.php?registered=1");
                exit;
            }
            $stmt->close();
        }
        $errors[] = "Unable to create admin account.";
    }
}

if ($isBootstrapMode) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin</title>
    <link rel="stylesheet" href="../assets/styles.css?v=large-logo-1">
    <link rel="stylesheet" href="../assets/store-admin.css?v=large-logo-1">
    <link rel="stylesheet" href="assets/admin.css?v=large-logo-1">
</head>
<body class="store-admin-body admin-body">
    <main class="auth-shell">
        <section class="card admin-bootstrap-card">
            <h1>Create First Admin</h1>
            <p class="status-text">No admin account exists yet. Create the first admin to unlock the admin dashboard.</p>
            <?php if ($errors): ?>
                <div class="notice error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo escape($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" class="form-stack">
                <div class="split">
                    <div class="field">
                        <label for="first_name">First name</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo escape($newAdmin["first_name"]); ?>" required>
                    </div>
                    <div class="field">
                        <label for="middle_name">Middle name</label>
                        <input type="text" id="middle_name" name="middle_name" value="<?php echo escape($newAdmin["middle_name"]); ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo escape($newAdmin["last_name"]); ?>" required>
                </div>
                <div class="field">
                    <label for="contact">Contact</label>
                    <input type="text" id="contact" name="contact" value="<?php echo escape($newAdmin["contact"]); ?>" required>
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo escape($newAdmin["email"]); ?>" required>
                </div>
                <div class="split">
                    <div class="field">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>
                <button class="btn" type="submit">Create Admin</button>
            </form>
        </section>
    </main>
</body>
</html>
<?php
    exit;
}

admin_require_admin();

$accounts = admin_fetch_accounts($mysqli);
$orders = admin_fetch_orders($mysqli);
$livePayload = admin_fetch_live_payload($mysqli, $accounts, $orders);
$stores = admin_filter_accounts($accounts, "store");
$users = admin_filter_accounts($accounts, "user");
$admins = admin_filter_accounts($accounts, "admin");

if (isset($_GET["format"]) && $_GET["format"] === "json") {
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    echo json_encode($livePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="../assets/store-admin.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/admin.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
</head>
<body class="store-admin-body admin-body">
    <header class="top-bar">
        <a class="logo admin-header-logo" href="dashboard.php" aria-label="Admin dashboard">
            <img src="../732961553_1045061465131627_5347302832846310517_n.png" alt="Logo">
        </a>
        <?php echo admin_nav("dashboard"); ?>
    </header>

    <main class="admin-shell">
        <section class="admin-hero" aria-label="Admin live map">
            <div class="admin-map-panel">
                <div id="admin-map"></div>
                <div class="admin-map-status" id="admin-map-status">Loading pins and driver route...</div>
            </div>
            <aside class="admin-summary-panel">
                <div>
                    <h1>Admin Dashboard</h1>
                    <p>Live view of stores, users, driver GPS, and the current active route.</p>
                </div>
                <div class="admin-stat-grid">
                    <article><strong><?php echo count($admins); ?></strong><span>Admins</span></article>
                    <article><strong><?php echo count($stores); ?></strong><span>Stores</span></article>
                    <article><strong><?php echo count($users); ?></strong><span>Users</span></article>
                    <article><strong><?php echo count($orders); ?></strong><span>Orders</span></article>
                </div>
                <div class="admin-live-card">
                    <span class="driver-live-dot" id="admin-live-dot"></span>
                    <p id="admin-live-copy">Polling every 10 seconds.</p>
                </div>
                <div class="admin-route-list" id="admin-active-orders"></div>
            </aside>
        </section>
    </main>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const initialPayload = <?php echo json_encode($livePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const map = L.map("admin-map", { zoomControl: false }).setView([14.5995, 120.9842], 12);
        L.control.zoom({ position: "bottomright" }).addTo(map);
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "&copy; OpenStreetMap contributors"
        }).addTo(map);

        const statusEl = document.getElementById("admin-map-status");
        const liveDot = document.getElementById("admin-live-dot");
        const liveCopy = document.getElementById("admin-live-copy");
        const activeOrdersEl = document.getElementById("admin-active-orders");
        let driverMarker = null;
        let routeLayer = null;
        let routeTargetMarker = null;
        let userLayer = L.layerGroup().addTo(map);
        let storeLayer = L.layerGroup().addTo(map);
        let selectedOrderId = null;

        const storeIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker store"><svg class="marker-svg" viewBox="0 0 24 24"><path d="M3 9l2-5h14l2 5"></path><path d="M5 9v11h14V9"></path><path d="M9 20v-5h6v5"></path></svg></div>`,
            iconSize: [34, 34],
            iconAnchor: [17, 34],
            popupAnchor: [0, -32]
        });
        const userIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker user"><svg class="marker-svg" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M5 21a7 7 0 0 1 14 0"></path></svg></div>`,
            iconSize: [34, 34],
            iconAnchor: [17, 34],
            popupAnchor: [0, -30]
        });
        const driverIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker driver"><svg class="marker-svg" viewBox="0 0 24 24"><circle cx="6.5" cy="17.5" r="2.5"></circle><circle cx="17.5" cy="17.5" r="2.5"></circle><path d="M9 7H6"></path><path d="M8.5 10.5 11 17.5"></path><path d="M11 10.5h4l2.5 7"></path><path d="M10.5 10.5 14 7.5"></path></svg></div>`,
            iconSize: [34, 34],
            iconAnchor: [17, 34],
            popupAnchor: [0, -30]
        });

        function escapeHtml(value) {
            return String(value ?? "").replace(/[&<>"']/g, (char) => ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#039;"
            }[char]));
        }

        function hasPoint(point) {
            return point && Number.isFinite(Number(point.lat)) && Number.isFinite(Number(point.lng));
        }

        function getRouteTarget(order) {
            if (!order) {
                return null;
            }
            if ((order.status === "pending" || order.status === "for_pickup") && hasPoint({ lat: order.store_lat, lng: order.store_lng })) {
                return { lat: Number(order.store_lat), lng: Number(order.store_lng), label: `Pickup: ${order.store_name || "Store"}`, color: "#FF5B2E" };
            }
            if (order.status === "delivering" && hasPoint({ lat: order.delivery_lat, lng: order.delivery_lng })) {
                return { lat: Number(order.delivery_lat), lng: Number(order.delivery_lng), label: `Deliver to: ${order.customer_name || "Customer"}`, color: "#FF5B2E" };
            }
            return null;
        }

        function clearRoute() {
            if (routeLayer) {
                map.removeLayer(routeLayer);
                routeLayer = null;
            }
            if (routeTargetMarker) {
                map.removeLayer(routeTargetMarker);
                routeTargetMarker = null;
            }
        }

        async function drawRoute(driver, order) {
            const target = getRouteTarget(order);
            if (!hasPoint(driver) || !target) {
                clearRoute();
                return;
            }

            const start = [Number(driver.lat), Number(driver.lng)];
            const end = [target.lat, target.lng];
            clearRoute();
            routeTargetMarker = L.marker(end).addTo(map).bindPopup(escapeHtml(target.label));

            try {
                const url = `https://router.project-osrm.org/route/v1/driving/${start[1]},${start[0]};${end[1]},${end[0]}?overview=full&geometries=geojson`;
                const response = await fetch(url, { cache: "no-store" });
                const data = await response.json();
                const coordinates = data.routes && data.routes[0] && data.routes[0].geometry
                    ? data.routes[0].geometry.coordinates.map((point) => [point[1], point[0]])
                    : [start, end];
                routeLayer = L.polyline(coordinates, { color: target.color, weight: 5, opacity: 0.86 }).addTo(map);
            } catch (error) {
                routeLayer = L.polyline([start, end], { color: target.color, weight: 5, opacity: 0.75, dashArray: "8 8" }).addTo(map);
            }

            map.fitBounds(routeLayer.getBounds(), { padding: [38, 38] });
            statusEl.textContent = `${target.label}. Route updates when the active transaction changes.`;
        }

        function renderAccountPins(payload) {
            const bounds = [];
            storeLayer.clearLayers();
            userLayer.clearLayers();

            (payload.stores || []).forEach((store) => {
                if (!hasPoint(store)) {
                    return;
                }
                bounds.push([Number(store.lat), Number(store.lng)]);
                L.marker([Number(store.lat), Number(store.lng)], { icon: storeIcon })
                    .addTo(storeLayer)
                    .bindPopup(`<strong>${escapeHtml(store.name)}</strong><br>${escapeHtml(store.address || "Store location")}`);
            });

            (payload.users || []).forEach((user) => {
                if (!hasPoint(user)) {
                    return;
                }
                bounds.push([Number(user.lat), Number(user.lng)]);
                L.marker([Number(user.lat), Number(user.lng)], { icon: userIcon })
                    .addTo(userLayer)
                    .bindPopup(`<strong>${escapeHtml(user.name)}</strong><br>${escapeHtml(user.address || "User location")}`);
            });

            if (!driverMarker && bounds.length) {
                map.fitBounds(bounds, { padding: [40, 40] });
            }
        }

        function renderActiveOrders(payload) {
            const orders = Array.isArray(payload.active_orders) ? payload.active_orders : [];
            if (!activeOrdersEl) {
                return;
            }
            if (!orders.length) {
                activeOrdersEl.innerHTML = `<p>No active transaction route.</p>`;
                selectedOrderId = null;
                clearRoute();
                return;
            }

            const selected = orders.find((order) => Number(order.id) === Number(selectedOrderId))
                || orders.find((order) => order.status === "for_pickup" || order.status === "delivering")
                || orders[0];
            selectedOrderId = Number(selected.id);

            activeOrdersEl.innerHTML = orders.slice(0, 6).map((order) => {
                const isActive = Number(order.id) === Number(selectedOrderId);
                return `<button type="button" class="${isActive ? "active" : ""}" data-order-id="${order.id}">
                            <strong>#${order.id} ${escapeHtml(order.status.replace("_", " "))}</strong>
                            <span>${escapeHtml(order.store_name || "Store")} to ${escapeHtml(order.customer_name || "Customer")}</span>
                        </button>`;
            }).join("");

            activeOrdersEl.querySelectorAll("button").forEach((button) => {
                button.addEventListener("click", () => {
                    selectedOrderId = Number(button.dataset.orderId || 0);
                    renderPayload(payload);
                });
            });

            drawRoute(payload.driver, selected);
        }

        function renderPayload(payload) {
            renderAccountPins(payload);

            if (hasPoint(payload.driver)) {
                const point = [Number(payload.driver.lat), Number(payload.driver.lng)];
                if (driverMarker) {
                    driverMarker.setLatLng(point);
                } else {
                    driverMarker = L.marker(point, { icon: driverIcon }).addTo(map);
                }
                driverMarker.bindPopup(`<strong>${escapeHtml(payload.driver.device || "Driver")}</strong><br>${escapeHtml(payload.driver.created_at || "")}`);
                liveDot.classList.remove("is-stale");
                liveCopy.textContent = `Driver GPS: ${Number(payload.driver.lat).toFixed(6)}, ${Number(payload.driver.lng).toFixed(6)}`;
            } else {
                liveDot.classList.add("is-stale");
                liveCopy.textContent = "Waiting for driver GPS.";
            }

            renderActiveOrders(payload);
            if (!payload.active_orders || !payload.active_orders.length) {
                statusEl.textContent = "All account pins are visible. No active driver route.";
            }
        }

        async function refreshAdminMap() {
            try {
                const response = await fetch(`dashboard.php?format=json&_=${Date.now()}`, { cache: "no-store" });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                renderPayload(await response.json());
            } catch (error) {
                liveDot.classList.add("is-stale");
                liveCopy.textContent = "Refresh failed. Retrying.";
            }
        }

        renderPayload(initialPayload);
        window.setInterval(refreshAdminMap, 10000);
    </script>
</body>
</html>
