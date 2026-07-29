<?php
require_once "auth.php";
require_once "db.php";
require_login();

$user_id = (int) ($_SESSION["user_id"] ?? 0);
$user_name = $_SESSION["user_name"] ?? "User";
$account_type = $_SESSION["account_type"] ?? "";
if ($account_type === "admin") {
    header("Location: admin/dashboard.php");
    exit;
}
$is_store = ($_SESSION["account_type"] ?? "") === "store";

$stores = [];
$store_home_pin = [
    "lat" => null,
    "lng" => null
];
$user_home_pin = [
    "address" => "",
    "lat" => null,
    "lng" => null
];

$format_price_label = static function ($price): string {
    if ($price === null || $price === "") {
        return "";
    }
    return "PHP " . number_format((float) $price, 2);
};

if ($is_store && $user_id > 0) {
    $store_stmt = $mysqli->prepare(
        "SELECT store_lat, store_lng
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    if ($store_stmt) {
        $store_stmt->bind_param("i", $user_id);
        $store_stmt->execute();
        $store_stmt->bind_result($store_lat, $store_lng);
        if ($store_stmt->fetch()) {
            $store_home_pin["lat"] = $store_lat !== null ? (float) $store_lat : null;
            $store_home_pin["lng"] = $store_lng !== null ? (float) $store_lng : null;
        }
        $store_stmt->close();
    }
}

if (!$is_store) {
    $store_ids = [];
    $products_by_store = [];

    $user_pin_stmt = $mysqli->prepare(
        "SELECT user_address, user_lat, user_lng
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    if ($user_pin_stmt) {
        $user_pin_stmt->bind_param("i", $user_id);
        $user_pin_stmt->execute();
        $user_pin_stmt->bind_result($user_address, $user_lat, $user_lng);
        if ($user_pin_stmt->fetch()) {
            $user_home_pin["address"] = (string) ($user_address ?? "");
            $user_home_pin["lat"] = $user_lat !== null ? (float) $user_lat : null;
            $user_home_pin["lng"] = $user_lng !== null ? (float) $user_lng : null;
        }
        $user_pin_stmt->close();
    }

    $stmt = $mysqli->prepare(
        "SELECT id, store_name, first_name, last_name, store_address, store_lat, store_lng, store_contact, contact
         FROM users
         WHERE account_type = 'store'
           AND store_lat IS NOT NULL
           AND store_lng IS NOT NULL"
    );
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result(
            $store_id,
            $store_name,
            $first_name,
            $last_name,
            $store_address,
            $store_lat,
            $store_lng,
            $store_contact,
            $default_contact
        );
        while ($stmt->fetch()) {
            $fallback_name = trim(($first_name ?? "") . " " . ($last_name ?? ""));
            $display_name = trim((string) ($store_name ?? ""));
            $display_contact = trim((string) ($store_contact ?? ""));
            if ($display_name === "") {
                $display_name = $fallback_name !== "" ? $fallback_name : "Store #" . $store_id;
            }
            if ($display_contact === "") {
                $display_contact = trim((string) ($default_contact ?? ""));
            }

            $stores[] = [
                "id" => (int) $store_id,
                "name" => $display_name,
                "address" => (string) ($store_address ?? ""),
                "lat" => (float) $store_lat,
                "lng" => (float) $store_lng,
                "contact" => $display_contact,
                "products" => []
            ];
            $store_ids[(int) $store_id] = true;
        }
        $stmt->close();
    }

    if ($store_ids) {
        $products_stmt = $mysqli->prepare(
            "SELECT id, store_user_id, product_name, product_description, product_price
             FROM store_products
             ORDER BY id DESC"
        );
        if ($products_stmt) {
            $products_stmt->execute();
            $products_stmt->bind_result($product_id, $store_user_id, $product_name, $product_description, $product_price);
            while ($products_stmt->fetch()) {
                $owner_id = (int) $store_user_id;
                if (!isset($store_ids[$owner_id])) {
                    continue;
                }
                if (!isset($products_by_store[$owner_id])) {
                    $products_by_store[$owner_id] = [];
                }
                $products_by_store[$owner_id][] = [
                    "id" => (int) $product_id,
                    "name" => trim((string) ($product_name ?? "")),
                    "description" => trim((string) ($product_description ?? "")),
                    "price" => $product_price !== null ? (float) $product_price : null,
                    "price_label" => $format_price_label($product_price)
                ];
            }
            $products_stmt->close();
        }

        foreach ($stores as &$store) {
            $owner_id = (int) $store["id"];
            $store["products"] = $products_by_store[$owner_id] ?? [];
        }
        unset($store);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/home.css?v=hover-effects-1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
</head>
<body class="home-screen <?php echo $is_store ? "account-store" : "account-user"; ?>">
    <main class="home-map-shell">
        <div id="home-map"></div>

        <button id="menu-toggle" class="menu-toggle" type="button" aria-controls="menu-drawer" aria-expanded="false" aria-label="Open menu">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <div class="map-status-chip" id="map-status">
            <?php echo $is_store ? "Map is active. Open menu for Profile and Product." : (count($stores) ? count($stores) . " stores available." : "No stores pinned yet."); ?>
        </div>

        <?php if (!$is_store): ?>
            <section class="product-search-shell" id="product-search-shell">
                <label for="product-search-input">Search Product</label>
                <input type="search" id="product-search-input" placeholder="Type product name..." autocomplete="off">
                <div class="product-search-results" id="product-search-results">
                    <p class="search-hint">Search a product to see available items.</p>
                </div>
            </section>

            <button type="button" class="cart-toggle-btn" id="cart-toggle" aria-controls="cart-panel" aria-expanded="false" aria-label="Toggle cart">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="9" cy="20" r="1.8"></circle>
                    <circle cx="18" cy="20" r="1.8"></circle>
                    <path d="M3 4h2.5l2.2 11.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7"></path>
                </svg>
                <span id="cart-toggle-count">0</span>
            </button>

            <button type="button" class="order-tracker-bubble" id="order-tracker-bubble" aria-controls="order-tracker-panel" aria-expanded="false" aria-label="Show order tracker" hidden>
                <span>Orders</span>
                <b id="order-tracker-bubble-count">0</b>
            </button>

            <section class="cart-panel" id="cart-panel" aria-label="Cart" hidden>
                <div class="cart-head">
                    <div>
                        <h2>Cart</h2>
                        <p id="cart-summary">No items yet.</p>
                    </div>
                    <div class="cart-head-actions">
                        <span class="cart-count" id="cart-count">0</span>
                        <button type="button" class="cart-minimize-btn" id="cart-minimize" aria-label="Minimize cart">&minus;</button>
                    </div>
                </div>
                <div class="cart-items" id="cart-items">
                    <p class="cart-empty">Add products from search results.</p>
                </div>
                <div class="checkout-options" id="checkout-options">
                    <span>Checkout option</span>
                    <label>
                        <input type="radio" name="checkout_type" value="delivery" checked>
                        Delivery
                    </label>
                    <label>
                        <input type="radio" name="checkout_type" value="pickup">
                        Pickup
                    </label>
                </div>
                <div class="checkout-breakdown" id="checkout-breakdown" aria-live="polite"></div>
                <div class="cart-foot">
                    <div>
                        <span>Total</span>
                        <strong id="cart-total">PHP 0.00</strong>
                    </div>
                    <div class="cart-foot-actions">
                        <button type="button" class="cart-checkout-btn" id="cart-checkout" disabled>Checkout</button>
                        <button type="button" class="cart-clear-btn" id="cart-clear" disabled>Clear</button>
                    </div>
                </div>
            </section>

            <section class="store-products-panel" id="store-products-panel" hidden>
                <div class="store-products-panel-head">
                    <div>
                        <h2 id="store-products-title">Store products</h2>
                        <p id="store-products-meta"></p>
                    </div>
                    <button type="button" class="store-products-close" id="store-products-close" aria-label="Close products">&times;</button>
                </div>
                <div class="store-products-panel-list" id="store-products-panel-list"></div>
            </section>

            <section class="order-tracker-panel" id="order-tracker-panel" hidden>
                <div class="order-tracker-head">
                    <div>
                        <h2>Order Tracker</h2>
                        <p id="order-tracker-summary">Waiting for active orders.</p>
                    </div>
                    <div class="order-tracker-controls">
                        <button type="button" class="order-tracker-minimize" id="order-tracker-minimize" aria-label="Minimize tracker">Minimize</button>
                        <button type="button" class="order-tracker-close" id="order-tracker-close" aria-label="Close tracker">&times;</button>
                    </div>
                </div>
                <div class="order-tracker-list" id="order-tracker-list"></div>
            </section>
        <?php endif; ?>

        <aside class="menu-drawer" id="menu-drawer" aria-hidden="true">
            <div class="menu-head">
                <img class="menu-logo" src="732961553_1045061465131627_5347302832846310517_n.png" alt="Lokal">
                <button class="menu-close" id="menu-close" type="button" aria-label="Close menu">&times;</button>
            </div>

            <section class="menu-section">
                <h3>Store Menu</h3>
                <?php if ($is_store): ?>
                    <a class="menu-link" href="account_profile.php">Profile</a>
                    <a class="menu-link" href="store_products.php">Product</a>
                    <a class="menu-link" href="order_history.php">Orders</a>
                    <button type="button" class="menu-link" id="menu-center-store-pin">Center to store pin</button>
                <?php else: ?>
                    <a class="menu-link" href="account_profile.php">Profile</a>
                    <a class="menu-link" href="order_history.php">Orders</a>
                    <button type="button" class="menu-link" id="menu-focus-stores">Focus all stores</button>
                    <button type="button" class="menu-link" id="menu-jump-cards">Jump to store cards</button>
                <?php endif; ?>
            </section>

            <section class="menu-section">
                <h3>User Account</h3>
                <p class="menu-meta"><?php echo escape($user_name); ?></p>
                <p class="menu-meta">Account type: <?php echo $is_store ? "Store" : "User"; ?></p>
                <a class="menu-link menu-logout" href="logout.php">Log out</a>
            </section>
        </aside>

        <div class="menu-backdrop" id="menu-backdrop"></div>

        <?php if (!$is_store): ?>
            <section class="store-carousel-panel" id="store-panel">
                <div class="store-panel-head">
                    <div>
                        <h1>Store Cards</h1>
                        <p><?php echo count($stores) ? "Swipe or use arrows to browse stores." : "No stores available yet."; ?></p>
                    </div>
                    <div class="store-panel-controls">
                        <button type="button" class="carousel-btn store-panel-minimize" id="store-panel-minimize" aria-label="Minimize store cards" aria-expanded="true">&minus;</button>
                        <button type="button" class="carousel-btn" id="carousel-prev" aria-label="Previous stores">&#8249;</button>
                        <button type="button" class="carousel-btn" id="carousel-next" aria-label="Next stores">&#8250;</button>
                    </div>
                </div>

                <div class="store-carousel" id="store-carousel">
                    <?php if ($stores): ?>
                        <?php foreach ($stores as $store): ?>
                            <button
                                type="button"
                                class="store-card"
                                data-store-id="<?php echo (int) $store["id"]; ?>"
                                data-lat="<?php echo escape((string) $store["lat"]); ?>"
                                data-lng="<?php echo escape((string) $store["lng"]); ?>"
                                data-name="<?php echo escape($store["name"]); ?>"
                            >
                                <h2><?php echo escape($store["name"]); ?></h2>
                                <p class="store-address"><?php echo escape($store["address"] !== "" ? $store["address"] : "Store location"); ?></p>
                                <?php if ($store["contact"] !== ""): ?>
                                    <p class="store-contact">Contact: <?php echo escape($store["contact"]); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($store["products"])): ?>
                                    <div class="store-products-preview">
                                        <?php foreach (array_slice($store["products"], 0, 3) as $product): ?>
                                            <?php
                                                $line = $product["name"] !== "" ? $product["name"] : "Product";
                                                if ($product["price_label"] !== "") {
                                                    $line .= " - " . $product["price_label"];
                                                }
                                            ?>
                                            <p class="store-product-line"><?php echo escape($line); ?></p>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="store-products-empty">No products listed yet.</p>
                                <?php endif; ?>
                                <div class="store-search-matches" data-store-match-list hidden></div>
                            </button>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <article class="store-card empty">
                            <h2>No stores pinned yet</h2>
                            <p class="store-address">Store cards will appear here once a store account pins a location.</p>
                        </article>
                    <?php endif; ?>
                </div>
            </section>
        <?php else: ?>
            <section class="store-home-panel">
                <h1>Store Home</h1>
                <p>Use the menu to open Profile or Product. This page stays focused on the map.</p>
                <div class="store-home-actions">
                    <a class="store-home-link" href="account_profile.php">Open Profile</a>
                    <a class="store-home-link" href="store_products.php">Open Product</a>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const isStoreAccount = <?php echo $is_store ? "true" : "false"; ?>;
        const stores = <?php echo json_encode($stores, JSON_UNESCAPED_SLASHES); ?>;
        const storeHomePin = <?php echo json_encode($store_home_pin, JSON_UNESCAPED_SLASHES); ?>;
        const userHomePin = <?php echo json_encode($user_home_pin, JSON_UNESCAPED_SLASHES); ?>;

        const map = L.map("home-map", { zoomControl: false }).setView([0, 0], 2);
        L.control.zoom({ position: "bottomright" }).addTo(map);

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "&copy; OpenStreetMap contributors"
        }).addTo(map);

        const storeIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker store">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 9l2-5h14l2 5"></path>
                        <path d="M5 9v11h14V9"></path>
                        <path d="M9 20v-5h6v5"></path>
                    </svg>
                </div>`,
            iconSize: [34, 34],
            iconAnchor: [17, 34],
            popupAnchor: [0, -32]
        });

        const storeMatchIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker store search-hit">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 9l2-5h14l2 5"></path>
                        <path d="M5 9v11h14V9"></path>
                        <path d="M9 20v-5h6v5"></path>
                    </svg>
                </div>`,
            iconSize: [34, 34],
            iconAnchor: [17, 34],
            popupAnchor: [0, -32]
        });

        const storeHomeIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker store-home">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 10.5 12 3l9 7.5"></path>
                        <path d="M5.5 9.5V21h13V9.5"></path>
                        <path d="M9 21v-6h6v6"></path>
                    </svg>
                </div>`,
            iconSize: [34, 34],
            iconAnchor: [17, 34],
            popupAnchor: [0, -32]
        });

        const riderIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker rider">
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

        const customerIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker user">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M5 21a7 7 0 0 1 14 0"></path>
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
        const menuJumpCards = document.getElementById("menu-jump-cards");

        function setStatus(message) {
            if (statusEl) {
                statusEl.textContent = message;
            }
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

        if (menuToggle && menuClose && menuBackdrop) {
            menuToggle.addEventListener("click", openMenu);
            menuClose.addEventListener("click", closeMenu);
            menuBackdrop.addEventListener("click", closeMenu);
        }

        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                closeMenu();
            }
        });

        if (menuJumpCards) {
            menuJumpCards.addEventListener("click", () => {
                const panel = document.getElementById("store-panel");
                if (panel) {
                    panel.classList.remove("pulse");
                    void panel.offsetWidth;
                    panel.classList.add("pulse");
                    const firstCard = panel.querySelector(".store-card");
                    if (firstCard) {
                        firstCard.focus();
                    }
                }
                closeMenu();
            });
        }

        if (!isStoreAccount) {
            const bounds = [];
            const userBounds = [];
            const markerByStoreId = new Map();
            const storeById = new Map(stores.map((store) => [String(store.id), store]));
            const cards = Array.from(document.querySelectorAll(".store-card[data-store-id]"));
            const carousel = document.getElementById("store-carousel");
            const storePanel = document.getElementById("store-panel");
            const storePanelMinimize = document.getElementById("store-panel-minimize");
            const prevButton = document.getElementById("carousel-prev");
            const nextButton = document.getElementById("carousel-next");
            const focusStoresButton = document.getElementById("menu-focus-stores");
            const searchInput = document.getElementById("product-search-input");
            const searchResults = document.getElementById("product-search-results");
            const cartItemsEl = document.getElementById("cart-items");
            const cartCountEl = document.getElementById("cart-count");
            const cartToggleButton = document.getElementById("cart-toggle");
            const cartToggleCount = document.getElementById("cart-toggle-count");
            const cartPanel = document.getElementById("cart-panel");
            const cartSummaryEl = document.getElementById("cart-summary");
            const cartTotalEl = document.getElementById("cart-total");
            const checkoutOptionsEl = document.getElementById("checkout-options");
            const checkoutBreakdownEl = document.getElementById("checkout-breakdown");
            const cartClearButton = document.getElementById("cart-clear");
            const cartCheckoutButton = document.getElementById("cart-checkout");
            const cartMinimizeButton = document.getElementById("cart-minimize");
            const storeProductsPanel = document.getElementById("store-products-panel");
            const storeProductsTitle = document.getElementById("store-products-title");
            const storeProductsMeta = document.getElementById("store-products-meta");
            const storeProductsList = document.getElementById("store-products-panel-list");
            const storeProductsClose = document.getElementById("store-products-close");
            const orderTrackerPanel = document.getElementById("order-tracker-panel");
            const orderTrackerSummary = document.getElementById("order-tracker-summary");
            const orderTrackerList = document.getElementById("order-tracker-list");
            const orderTrackerClose = document.getElementById("order-tracker-close");
            const orderTrackerMinimize = document.getElementById("order-tracker-minimize");
            const orderTrackerBubble = document.getElementById("order-tracker-bubble");
            const orderTrackerBubbleCount = document.getElementById("order-tracker-bubble-count");
            const cart = new Map();
            const cartStorageKey = "lokal_cart_items";
            let checkoutType = "delivery";
            let orderPollTimer = null;
            let riderMarker = null;
            let customerMarker = null;
            let customerRouteLayer = null;
            let activeTrackedOrderId = null;
            let isOrderTrackerMinimized = false;

            const hasUserHomePin = userHomePin
                && Number.isFinite(Number(userHomePin.lat))
                && Number.isFinite(Number(userHomePin.lng));
            if (hasUserHomePin) {
                const userLat = Number(userHomePin.lat);
                const userLng = Number(userHomePin.lng);
                const address = userHomePin.address && userHomePin.address !== ""
                    ? escapeHtml(userHomePin.address)
                    : "Your saved location";
                L.marker([userLat, userLng], { icon: customerIcon })
                    .addTo(map)
                    .bindPopup(`<strong>Your Location</strong><br>${address}`);
                userBounds.push([userLat, userLng]);
            }

            stores.forEach((store) => {
                const marker = L.marker([store.lat, store.lng], { icon: storeIcon }).addTo(map);
                const name = escapeHtml(store.name && store.name !== "" ? store.name : "Store");
                const address = escapeHtml(store.address && store.address !== "" ? store.address : "Store location");
                const contact = store.contact && store.contact !== "" ? `<br>Contact: ${escapeHtml(store.contact)}` : "";
                marker.bindPopup(`<strong>${name}</strong><br>${address}${contact}<br><a href="store_profile.php?id=${encodeURIComponent(store.id)}">Open store profile</a>`);
                markerByStoreId.set(String(store.id), marker);
                bounds.push([store.lat, store.lng]);
            });

            const initialBounds = bounds.concat(userBounds);
            if (initialBounds.length > 1) {
                map.fitBounds(initialBounds, {
                    paddingTopLeft: [42, 95],
                    paddingBottomRight: [42, 260]
                });
                setStatus(`${bounds.length} stores available.`);
            } else if (initialBounds.length === 1) {
                map.setView(initialBounds[0], 15);
                setStatus(bounds.length ? `${bounds.length} store available.` : "Showing your saved location.");
            } else {
                setStatus("No store markers available.");
            }

            function setActiveCard(activeCard) {
                cards.forEach((card) => card.classList.toggle("active", card === activeCard));
            }

            function focusStore(storeId) {
                const marker = markerByStoreId.get(storeId);
                if (!marker) {
                    return;
                }
                const activeCard = cards.find((card) => (card.dataset.storeId || "") === storeId);
                if (activeCard) {
                    setActiveCard(activeCard);
                }
                map.flyTo(marker.getLatLng(), 16, { duration: 0.55 });
                marker.openPopup();
            }

            function openStoreProfile(storeId) {
                if (!storeById.has(String(storeId))) {
                    return;
                }
                window.location.href = `store_profile.php?id=${encodeURIComponent(storeId)}`;
            }

            function getProductMatches(store, queryLower) {
                if (!Array.isArray(store.products) || !store.products.length) {
                    return [];
                }
                return store.products.filter((product) => {
                    const name = String(product.name || "").toLowerCase();
                    const description = String(product.description || "").toLowerCase();
                    return name.includes(queryLower) || description.includes(queryLower);
                });
            }

            function findStoreProduct(storeId, productId) {
                const store = storeById.get(String(storeId));
                if (!store || !Array.isArray(store.products)) {
                    return null;
                }

                const product = store.products.find((item) => String(item.id) === String(productId));
                return product ? { store, product } : null;
            }

            function normalizeQuantity(value, fallback = 1) {
                const parsed = Number.parseInt(value, 10);
                if (!Number.isFinite(parsed)) {
                    return fallback;
                }
                return Math.max(1, Math.min(99, parsed));
            }

            function getRequestedQuantity(button) {
                const row = button ? button.closest("article") : null;
                const input = row ? row.querySelector("[data-cart-quantity]") : null;
                const quantity = normalizeQuantity(input ? input.value : 1);
                if (input) {
                    input.value = String(quantity);
                }
                return quantity;
            }

            function getCartStoreId() {
                const first = cart.values().next();
                return first.done ? "" : String(first.value.storeId || "");
            }

            function resetCartForDifferentStore(storeId) {
                const currentStoreId = getCartStoreId();
                if (currentStoreId !== "" && currentStoreId !== String(storeId)) {
                    cart.clear();
                    saveCart();
                    renderCart();
                    return true;
                }
                return false;
            }

            function formatCartPrice(value) {
                const amount = Number(value);
                if (!Number.isFinite(amount)) {
                    return "";
                }
                return `PHP ${amount.toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                })}`;
            }

            function calculateDistanceKm(fromLat, fromLng, toLat, toLng) {
                const startLat = Number(fromLat);
                const startLng = Number(fromLng);
                const endLat = Number(toLat);
                const endLng = Number(toLng);
                if (![startLat, startLng, endLat, endLng].every(Number.isFinite)) {
                    return 0;
                }

                const earthRadiusKm = 6371;
                const latDelta = (endLat - startLat) * Math.PI / 180;
                const lngDelta = (endLng - startLng) * Math.PI / 180;
                const a = Math.sin(latDelta / 2) ** 2
                    + Math.cos(startLat * Math.PI / 180) * Math.cos(endLat * Math.PI / 180)
                    * Math.sin(lngDelta / 2) ** 2;
                return earthRadiusKm * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            }

            function calculateDeliveryFee(distanceKm) {
                const distance = Number(distanceKm);
                if (!Number.isFinite(distance) || distance < 0) {
                    return 0;
                }
                return Math.max(1, Math.floor(distance)) * 40;
            }

            function getCartBreakdown() {
                const items = Array.from(cart.values());
                let subtotal = 0;
                let hasUnpricedItem = false;
                const storesInCart = new Map();

                items.forEach((item) => {
                    if (Number.isFinite(Number(item.price))) {
                        subtotal += Number(item.price) * item.quantity;
                    } else {
                        hasUnpricedItem = true;
                    }

                    const store = storeById.get(String(item.storeId));
                    if (store) {
                        storesInCart.set(String(store.id), store);
                    }
                });

                let deliveryDistanceKm = 0;
                if (checkoutType === "delivery" && hasUserHomePin) {
                    storesInCart.forEach((store) => {
                        deliveryDistanceKm += calculateDistanceKm(store.lat, store.lng, userHomePin.lat, userHomePin.lng);
                    });
                }

                const roundedDistanceKm = Math.round(deliveryDistanceKm * 100) / 100;
                const deliveryFee = checkoutType === "delivery" ? calculateDeliveryFee(roundedDistanceKm) : 0;
                return {
                    subtotal,
                    hasUnpricedItem,
                    deliveryDistanceKm: roundedDistanceKm,
                    deliveryFee,
                    total: subtotal + deliveryFee,
                    storeCount: storesInCart.size
                };
            }

            function saveCart() {
                try {
                    window.localStorage.setItem(cartStorageKey, JSON.stringify(Array.from(cart.values())));
                } catch (error) {
                    return;
                }
            }

            function loadSavedCart() {
                try {
                    const saved = JSON.parse(window.localStorage.getItem(cartStorageKey) || "[]");
                    if (!Array.isArray(saved)) {
                        return;
                    }

                    saved.forEach((item) => {
                        const found = findStoreProduct(item.storeId, item.productId);
                        const quantity = Math.max(1, Math.min(99, Number.parseInt(item.quantity, 10) || 1));
                        if (!found) {
                            return;
                        }
                        const key = `${found.store.id}:${found.product.id}`;
                        const price = found.product.price !== null && found.product.price !== ""
                            ? Number(found.product.price)
                            : Number.NaN;
                        cart.set(key, {
                            key,
                            storeId: String(found.store.id),
                            productId: String(found.product.id),
                            storeName: found.store.name || "Store",
                            name: found.product.name || "Product",
                            price: Number.isFinite(price) ? price : null,
                            quantity
                        });
                    });
                } catch (error) {
                    return;
                }
            }

            function formatOrderStatus(status) {
                return {
                    pending: "Waiting for rider",
                    for_pickup: "Rider is going to the store",
                    delivering: "Rider is on the way to you"
                }[status] || status;
            }

            function clearCustomerRoute() {
                if (customerRouteLayer) {
                    map.removeLayer(customerRouteLayer);
                    customerRouteLayer = null;
                }
                if (riderMarker) {
                    map.removeLayer(riderMarker);
                    riderMarker = null;
                }
                if (customerMarker) {
                    map.removeLayer(customerMarker);
                    customerMarker = null;
                }
            }

            async function drawCustomerDeliveryRoute(order, driver) {
                if (!order || order.status !== "delivering" || !driver) {
                    clearCustomerRoute();
                    return;
                }

                const start = [Number(driver.lat), Number(driver.lng)];
                const end = [Number(order.delivery_lat), Number(order.delivery_lng)];
                if (!Number.isFinite(start[0]) || !Number.isFinite(start[1]) || !Number.isFinite(end[0]) || !Number.isFinite(end[1])) {
                    clearCustomerRoute();
                    return;
                }

                clearCustomerRoute();
                riderMarker = L.marker(start, { icon: riderIcon }).addTo(map).bindPopup("Rider location");
                customerMarker = L.marker(end, { icon: customerIcon }).addTo(map).bindPopup("Your delivery location");

                try {
                    const url = `https://router.project-osrm.org/route/v1/driving/${start[1]},${start[0]};${end[1]},${end[0]}?overview=full&geometries=geojson`;
                    const response = await fetch(url, { cache: "no-store" });
                    const data = await response.json();
                    const coordinates = data.routes && data.routes[0] && data.routes[0].geometry
                        ? data.routes[0].geometry.coordinates.map((point) => [point[1], point[0]])
                        : [start, end];
                    customerRouteLayer = L.polyline(coordinates, {
                        color: "#1f8f5e",
                        weight: 5,
                        opacity: 0.86
                    }).addTo(map);
                } catch (error) {
                    customerRouteLayer = L.polyline([start, end], {
                        color: "#1f8f5e",
                        weight: 5,
                        opacity: 0.72,
                        dashArray: "8 8"
                    }).addTo(map);
                }

                map.fitBounds(customerRouteLayer.getBounds(), {
                    paddingTopLeft: [42, 110],
                    paddingBottomRight: [42, 280]
                });
            }

            function renderUserOrders(payload) {
                if (!orderTrackerPanel || !orderTrackerSummary || !orderTrackerList) {
                    return;
                }

                const orders = payload && Array.isArray(payload.orders) ? payload.orders : [];
                if (!orders.length) {
                    orderTrackerPanel.hidden = true;
                    if (orderTrackerBubble) {
                        orderTrackerBubble.hidden = true;
                        orderTrackerBubble.setAttribute("aria-expanded", "false");
                    }
                    isOrderTrackerMinimized = false;
                    activeTrackedOrderId = null;
                    clearCustomerRoute();
                    return;
                }

                orderTrackerPanel.hidden = isOrderTrackerMinimized;
                if (orderTrackerBubble) {
                    orderTrackerBubble.hidden = !isOrderTrackerMinimized;
                    orderTrackerBubble.setAttribute("aria-expanded", isOrderTrackerMinimized ? "false" : "true");
                }
                if (orderTrackerBubbleCount) {
                    orderTrackerBubbleCount.textContent = String(orders.length);
                }
                orderTrackerSummary.textContent = `${orders.length} active order${orders.length === 1 ? "" : "s"}.`;
                orderTrackerList.innerHTML = orders.map((order) => {
                    const items = Array.isArray(order.items) ? order.items : [];
                    const lines = items.map((item) => `<p>${escapeHtml(item.product_name)} x ${Number(item.quantity || 0)}</p>`).join("");
                    return `<article class="order-tracker-card ${activeTrackedOrderId === order.id ? "active" : ""}" data-order-id="${order.id}">
                                <div class="order-tracker-card-head">
                                    <strong>Order #${order.id}</strong>
                                    <span>${escapeHtml(formatOrderStatus(order.status))}</span>
                                </div>
                                <p>Store: ${escapeHtml(order.store_name || "Store")}</p>
                                <div class="order-tracker-items">${lines || "<p>No items listed.</p>"}</div>
                            </article>`;
                }).join("");

                const tracked = orders.find((order) => order.id === activeTrackedOrderId)
                    || orders.find((order) => order.status === "delivering")
                    || orders[0];
                activeTrackedOrderId = tracked.id;

                if (tracked.status === "delivering") {
                    setStatus("Your rider is on the way to your location.");
                    drawCustomerDeliveryRoute(tracked, payload.driver);
                } else {
                    clearCustomerRoute();
                    setStatus(formatOrderStatus(tracked.status));
                }
            }

            async function refreshUserOrders() {
                try {
                    const response = await fetch(`user_orders.php?_=${Date.now()}`, {
                        cache: "no-store"
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || "Unable to load orders.");
                    }
                    renderUserOrders(payload);
                } catch (error) {
                    setStatus(error.message || "Unable to update order status.");
                }
            }

            function getCartItemCount() {
                let count = 0;
                cart.forEach((item) => {
                    count += item.quantity;
                });
                return count;
            }

            function renderCart() {
                if (!cartItemsEl || !cartCountEl || !cartSummaryEl || !cartTotalEl || !cartClearButton || !cartCheckoutButton) {
                    return;
                }

                const items = Array.from(cart.values());
                const itemCount = getCartItemCount();
                const breakdown = getCartBreakdown();

                cartCountEl.textContent = String(itemCount);
                if (cartToggleCount) {
                    cartToggleCount.textContent = String(itemCount);
                }
                cartSummaryEl.textContent = itemCount === 0
                    ? "No items yet."
                    : `${items.length} product${items.length === 1 ? "" : "s"}, ${itemCount} item${itemCount === 1 ? "" : "s"} in cart.`;
                cartTotalEl.textContent = breakdown.hasUnpricedItem
                    ? `${formatCartPrice(breakdown.total)} + unpriced item`
                    : formatCartPrice(breakdown.total);
                cartClearButton.disabled = itemCount === 0;
                cartCheckoutButton.disabled = itemCount === 0;
                if (checkoutBreakdownEl) {
                    if (itemCount === 0) {
                        checkoutBreakdownEl.innerHTML = "";
                    } else {
                        const deliveryTier = Math.max(1, Math.floor(Number(breakdown.deliveryDistanceKm) || 0));
                        const deliveryLine = checkoutType === "delivery"
                            ? `<p><span>Delivery (${breakdown.deliveryDistanceKm.toFixed(2)} km, PHP 40 x ${deliveryTier})</span><strong>${formatCartPrice(breakdown.deliveryFee)}</strong></p>`
                            : `<p><span>Pickup fee</span><strong>${formatCartPrice(0)}</strong></p>`;
                        const locationNote = checkoutType === "delivery" && !hasUserHomePin
                            ? `<p class="checkout-note">Add your delivery address in Profile to calculate distance.</p>`
                            : "";
                        checkoutBreakdownEl.innerHTML = `<p><span>Subtotal</span><strong>${formatCartPrice(breakdown.subtotal)}</strong></p>
                            ${deliveryLine}
                            <p><span>Grand total</span><strong>${formatCartPrice(breakdown.total)}</strong></p>
                            ${locationNote}`;
                    }
                }

                if (!items.length) {
                    cartItemsEl.innerHTML = `<p class="cart-empty">Add products from search results.</p>`;
                    return;
                }

                cartItemsEl.innerHTML = items.map((item) => {
                    const lineTotal = Number.isFinite(item.price)
                        ? formatCartPrice(item.price * item.quantity)
                        : "Price not set";
                    return `<article class="cart-item" data-cart-key="${escapeHtml(item.key)}">
                                <div class="cart-item-copy">
                                    <strong>${escapeHtml(item.name)}</strong>
                                    <span>${escapeHtml(item.storeName)}</span>
                                    <span>Qty ${item.quantity}</span>
                                    <em>${escapeHtml(lineTotal)}</em>
                                </div>
                                <div class="cart-qty-controls" aria-label="Quantity controls">
                                    <button type="button" data-cart-action="decrease" aria-label="Decrease quantity">-</button>
                                    <span>${item.quantity}</span>
                                    <button type="button" data-cart-action="increase" aria-label="Increase quantity">+</button>
                                    <button type="button" data-cart-action="remove" aria-label="Remove item">Remove</button>
                                </div>
                            </article>`;
                }).join("");
            }

            function updateCartItem(storeId, productId, nextQuantity) {
                const found = findStoreProduct(storeId, productId);
                if (!found) {
                    return;
                }

                const key = `${found.store.id}:${found.product.id}`;
                const quantity = Math.max(0, Math.min(99, Number.parseInt(nextQuantity, 10) || 0));
                if (quantity <= 0) {
                    cart.delete(key);
                    saveCart();
                    renderCart();
                    return;
                }

                const price = found.product.price !== null && found.product.price !== ""
                    ? Number(found.product.price)
                    : Number.NaN;
                cart.set(key, {
                    key,
                    storeId: String(found.store.id),
                    productId: String(found.product.id),
                    storeName: found.store.name || "Store",
                    name: found.product.name || "Product",
                    price: Number.isFinite(price) ? price : null,
                    quantity
                });
                saveCart();
                renderCart();
            }

            function addProductToCart(storeId, productId, quantity = 1, openCart = true) {
                const found = findStoreProduct(storeId, productId);
                if (!found) {
                    return;
                }

                const didResetCart = resetCartForDifferentStore(found.store.id);
                const key = `${found.store.id}:${found.product.id}`;
                const current = cart.get(key);
                const amount = normalizeQuantity(quantity);
                const currentQuantity = current ? normalizeQuantity(current.quantity) : 0;
                const nextQuantity = Math.min(99, currentQuantity + amount);
                updateCartItem(found.store.id, found.product.id, nextQuantity);
                if (openCart) {
                    setCartPanelOpen(true);
                }
                setStatus(`${didResetCart ? "Cart reset for this store. " : ""}${found.product.name || "Product"} quantity in cart: ${nextQuantity}.`);
            }

            function getCurrentLocation() {
                return new Promise((resolve, reject) => {
                    if (!("geolocation" in navigator)) {
                        reject(new Error("Geolocation is not available."));
                        return;
                    }

                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            resolve({
                                lat: position.coords.latitude,
                                lng: position.coords.longitude
                            });
                        },
                        () => reject(new Error("Allow location access to checkout.")),
                        { enableHighAccuracy: true, timeout: 10000 }
                    );
                });
            }

            async function checkoutCart() {
                if (!cart.size || !cartCheckoutButton) {
                    return;
                }

                cartCheckoutButton.disabled = true;
                cartCheckoutButton.textContent = checkoutType === "delivery" ? "Locating..." : "Sending...";
                setStatus(checkoutType === "delivery" ? "Getting your delivery location..." : "Placing pickup order...");

                try {
                    const location = checkoutType === "delivery"
                        ? (hasUserHomePin ? { lat: Number(userHomePin.lat), lng: Number(userHomePin.lng) } : await getCurrentLocation())
                        : null;
                    cartCheckoutButton.textContent = "Sending...";

                    const response = await fetch("checkout.php", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json"
                        },
                        body: JSON.stringify({
                            order_type: checkoutType,
                            delivery_lat: location ? location.lat : null,
                            delivery_lng: location ? location.lng : null,
                            items: Array.from(cart.values()).map((item) => ({
                                product_id: item.productId,
                                quantity: item.quantity
                            }))
                        })
                    });

                    const result = await response.json();
                    if (!response.ok || !result.ok) {
                        throw new Error(result.message || "Checkout failed.");
                    }

                    cart.clear();
                    saveCart();
                    renderCart();
                    setCartPanelOpen(false);
                    setStatus(result.message || "Order sent to driver.");
                    refreshUserOrders();
                } catch (error) {
                    setStatus(error.message || "Checkout failed. Please try again.");
                    renderCart();
                } finally {
                    cartCheckoutButton.textContent = "Checkout";
                }
            }

            function setCartPanelOpen(isOpen) {
                if (!cartPanel || !cartToggleButton) {
                    return;
                }

                cartPanel.hidden = !isOpen;
                cartToggleButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
                document.body.classList.toggle("cart-open", isOpen);
            }

            function renderStoreProducts(storeId) {
                const store = storeById.get(String(storeId));
                if (!store || !storeProductsPanel || !storeProductsTitle || !storeProductsMeta || !storeProductsList) {
                    return;
                }

                const products = Array.isArray(store.products) ? store.products : [];
                storeProductsTitle.textContent = store.name || "Store products";
                storeProductsMeta.textContent = store.address && store.address !== ""
                    ? store.address
                    : "Store location";

                if (!products.length) {
                    storeProductsList.innerHTML = `<p class="store-products-panel-empty">No products listed yet.</p>`;
                } else {
                    storeProductsList.innerHTML = products.map((product) => {
                        const productName = product.name && product.name !== "" ? product.name : "Product";
                        const productPrice = product.price_label && product.price_label !== "" ? product.price_label : "Price not set";
                        const productDescription = product.description && product.description !== ""
                            ? `<p>${escapeHtml(product.description)}</p>`
                            : "";
                        return `<article class="store-product-row">
                                    <div>
                                        <strong>${escapeHtml(productName)}</strong>
                                        <span>${escapeHtml(productPrice)}</span>
                                        ${productDescription}
                                    </div>
                                    <div class="add-to-cart-controls">
                                        <label class="product-quantity-control">
                                            <span>Qty</span>
                                            <input type="number" min="1" max="99" value="1" inputmode="numeric" data-cart-quantity>
                                        </label>
                                        <button type="button" data-store-product-add data-store-id="${store.id}" data-product-id="${product.id}">Add</button>
                                    </div>
                                </article>`;
                    }).join("");
                }

                storeProductsPanel.hidden = false;
                setStatus(`Showing products from ${store.name || "store"}.`);
            }

            function renderCardMatches(card, matchedProducts) {
                const matchList = card.querySelector("[data-store-match-list]");
                if (!matchList) {
                    return;
                }
                if (!matchedProducts.length) {
                    matchList.hidden = true;
                    matchList.innerHTML = "";
                    return;
                }

                const lines = matchedProducts.slice(0, 4).map((product) => {
                    const productName = escapeHtml(product.name && product.name !== "" ? product.name : "Product");
                    const productPrice = product.price_label && product.price_label !== "" ? ` <span>${escapeHtml(product.price_label)}</span>` : "";
                    return `<p class="store-search-line">${productName}${productPrice}</p>`;
                }).join("");
                const moreCount = matchedProducts.length > 4 ? `<p class="store-search-line more">+${matchedProducts.length - 4} more</p>` : "";

                matchList.innerHTML = `<p class="store-search-title">Matched products</p>${lines}${moreCount}`;
                matchList.hidden = false;
            }

            function renderSearchResults(query, matchedRows) {
                if (!searchResults) {
                    return;
                }
                if (query === "") {
                    searchResults.innerHTML = `<p class="search-hint">Search a product to see available items.</p>`;
                    return;
                }
                if (!matchedRows.length) {
                    searchResults.innerHTML = `<p class="search-empty">No product found for "${escapeHtml(query)}".</p>`;
                    return;
                }

                const productRows = matchedRows.flatMap((row) => row.matches.map((product) => ({
                    store: row.store,
                    product
                })));

                const resultsHtml = productRows.map((row) => {
                    const productName = row.product.name && row.product.name !== "" ? row.product.name : "Product";
                    const productPrice = row.product.price_label && row.product.price_label !== "" ? row.product.price_label : "Price not set";
                    const productDescription = row.product.description && row.product.description !== ""
                        ? `<p class="search-product-description">${escapeHtml(row.product.description)}</p>`
                        : "";
                    return `<article class="search-result-item">
                                <div class="search-product-copy">
                                    <strong>${escapeHtml(productName)}</strong>
                                    <span>${escapeHtml(productPrice)}</span>
                                    ${productDescription}
                                    <p class="search-store-line">${escapeHtml(row.store.name)} - ${escapeHtml(row.store.address && row.store.address !== "" ? row.store.address : "Store location")}</p>
                                </div>
                                <div class="search-product-actions">
                                    <button type="button" data-search-action="focus-store" data-store-id="${row.store.id}">View store</button>
                                    <label class="product-quantity-control">
                                        <span>Qty</span>
                                        <input type="number" min="1" max="99" value="1" inputmode="numeric" data-cart-quantity>
                                    </label>
                                    <button type="button" data-search-action="add-cart" data-store-id="${row.store.id}" data-product-id="${row.product.id}">Add</button>
                                </div>
                            </article>`;
                }).join("");

                searchResults.innerHTML = resultsHtml;
                searchResults.querySelectorAll("[data-search-action]").forEach((button) => {
                    button.addEventListener("click", () => {
                        const action = button.dataset.searchAction || "";
                        const storeId = button.dataset.storeId || "";
                        if (action === "focus-store") {
                            openStoreProfile(storeId);
                            return;
                        }
                        if (action === "add-cart") {
                            addProductToCart(
                                storeId,
                                button.dataset.productId || "",
                                getRequestedQuantity(button),
                                true
                            );
                        }
                    });
                });
            }

            function applyProductSearch(queryValue) {
                const rawQuery = String(queryValue || "").trim();
                const queryLower = rawQuery.toLowerCase();
                const matchedBounds = [];
                const matchedRows = [];
                let matchedStoreCount = 0;
                let matchedProductCount = 0;

                cards.forEach((card) => {
                    const storeId = card.dataset.storeId || "";
                    const marker = markerByStoreId.get(storeId);
                    const store = storeById.get(storeId);
                    if (!marker || !store) {
                        return;
                    }

                    if (rawQuery === "") {
                        marker.setIcon(storeIcon);
                        card.classList.remove("search-hit", "search-dim");
                        renderCardMatches(card, []);
                        return;
                    }

                    const matches = getProductMatches(store, queryLower);
                    if (matches.length) {
                        marker.setIcon(storeMatchIcon);
                        card.classList.add("search-hit");
                        card.classList.remove("search-dim");
                        matchedBounds.push([store.lat, store.lng]);
                        matchedRows.push({ store, matches });
                        matchedStoreCount += 1;
                        matchedProductCount += matches.length;
                        renderCardMatches(card, matches);
                    } else {
                        marker.setIcon(storeIcon);
                        card.classList.remove("search-hit");
                        card.classList.add("search-dim");
                        renderCardMatches(card, []);
                    }
                });

                if (rawQuery === "") {
                    renderSearchResults("", []);
                    if (bounds.length) {
                        setStatus(`${bounds.length} stores available.`);
                    } else {
                        setStatus("No store markers available.");
                    }
                    return;
                }

                if (matchedBounds.length > 1) {
                    map.fitBounds(matchedBounds, {
                        paddingTopLeft: [42, 110],
                        paddingBottomRight: [42, 260]
                    });
                } else if (matchedBounds.length === 1) {
                    map.flyTo(matchedBounds[0], 16, { duration: 0.45 });
                }

                renderSearchResults(rawQuery, matchedRows);
                if (matchedStoreCount > 0) {
                    setStatus(`Found ${matchedProductCount} matching product(s) in ${matchedStoreCount} store(s).`);
                } else {
                    setStatus(`No store has "${rawQuery}".`);
                }
            }

            cards.forEach((card) => {
                card.addEventListener("click", () => {
                    const storeId = card.dataset.storeId || "";
                    openStoreProfile(storeId);
                });
            });

            function scrollCards(direction) {
                if (!carousel) {
                    return;
                }
                const firstCard = carousel.querySelector(".store-card");
                if (!firstCard) {
                    return;
                }
                const styles = getComputedStyle(carousel);
                const gap = Number.parseInt(styles.columnGap || styles.gap || "0", 10) || 0;
                const width = firstCard.getBoundingClientRect().width + gap;
                carousel.scrollBy({ left: direction * width, behavior: "smooth" });
            }

            if (prevButton) {
                prevButton.addEventListener("click", () => scrollCards(-1));
            }
            if (nextButton) {
                nextButton.addEventListener("click", () => scrollCards(1));
            }
            if (storePanel && storePanelMinimize) {
                storePanelMinimize.addEventListener("click", () => {
                    const isMinimized = storePanel.classList.toggle("is-minimized");
                    storePanelMinimize.setAttribute("aria-expanded", isMinimized ? "false" : "true");
                    storePanelMinimize.setAttribute("aria-label", isMinimized ? "Show store cards" : "Minimize store cards");
                    storePanelMinimize.innerHTML = isMinimized ? "View Stores" : "&minus;";
                });
            }
            if (focusStoresButton) {
                focusStoresButton.addEventListener("click", () => {
                    if (bounds.length) {
                        map.fitBounds(bounds, {
                            paddingTopLeft: [42, 95],
                            paddingBottomRight: [42, 260]
                        });
                        setStatus("Showing all store markers.");
                    }
                    closeMenu();
                });
            }

            if (searchInput) {
                searchInput.addEventListener("input", () => {
                    applyProductSearch(searchInput.value);
                });
                searchInput.addEventListener("keydown", (event) => {
                    if (event.key === "Escape") {
                        searchInput.value = "";
                        applyProductSearch("");
                    }
                });
            }

            if (cartItemsEl) {
                cartItemsEl.addEventListener("click", (event) => {
                    const button = event.target.closest("[data-cart-action]");
                    if (!button) {
                        return;
                    }

                    const itemEl = button.closest(".cart-item");
                    const item = itemEl ? cart.get(itemEl.dataset.cartKey || "") : null;
                    if (!item) {
                        return;
                    }

                    const action = button.dataset.cartAction || "";
                    if (action === "increase") {
                        updateCartItem(item.storeId, item.productId, item.quantity + 1);
                    } else if (action === "decrease") {
                        updateCartItem(item.storeId, item.productId, item.quantity - 1);
                    } else if (action === "remove") {
                        updateCartItem(item.storeId, item.productId, 0);
                    }
                });
            }

            if (cartClearButton) {
                cartClearButton.addEventListener("click", () => {
                    cart.clear();
                    saveCart();
                    renderCart();
                    setStatus("Cart cleared.");
                });
            }

            if (checkoutOptionsEl) {
                checkoutOptionsEl.addEventListener("change", (event) => {
                    const input = event.target.closest("input[name='checkout_type']");
                    if (!input) {
                        return;
                    }
                    checkoutType = input.value === "pickup" ? "pickup" : "delivery";
                    renderCart();
                });
            }

            if (cartCheckoutButton) {
                cartCheckoutButton.addEventListener("click", checkoutCart);
            }

            if (cartToggleButton) {
                cartToggleButton.addEventListener("click", () => {
                    setCartPanelOpen(cartPanel ? cartPanel.hidden : true);
                });
            }

            if (cartMinimizeButton) {
                cartMinimizeButton.addEventListener("click", () => {
                    setCartPanelOpen(false);
                });
            }

            if (storeProductsClose && storeProductsPanel) {
                storeProductsClose.addEventListener("click", () => {
                    storeProductsPanel.hidden = true;
                });
            }

            if (storeProductsList) {
                storeProductsList.addEventListener("click", (event) => {
                    const button = event.target.closest("[data-store-product-add]");
                    if (!button) {
                        return;
                    }

                    addProductToCart(
                        button.dataset.storeId || "",
                        button.dataset.productId || "",
                        getRequestedQuantity(button),
                        true
                    );
                });
            }

            if (orderTrackerClose && orderTrackerPanel) {
                orderTrackerClose.addEventListener("click", () => {
                    isOrderTrackerMinimized = true;
                    orderTrackerPanel.hidden = true;
                    if (orderTrackerBubble) {
                        orderTrackerBubble.hidden = false;
                        orderTrackerBubble.setAttribute("aria-expanded", "false");
                    }
                });
            }

            if (orderTrackerMinimize && orderTrackerPanel) {
                orderTrackerMinimize.addEventListener("click", () => {
                    isOrderTrackerMinimized = true;
                    orderTrackerPanel.hidden = true;
                    if (orderTrackerBubble) {
                        orderTrackerBubble.hidden = false;
                        orderTrackerBubble.setAttribute("aria-expanded", "false");
                    }
                });
            }

            if (orderTrackerBubble && orderTrackerPanel) {
                orderTrackerBubble.addEventListener("click", () => {
                    isOrderTrackerMinimized = false;
                    orderTrackerPanel.hidden = false;
                    orderTrackerBubble.hidden = true;
                    orderTrackerBubble.setAttribute("aria-expanded", "true");
                });
            }

            if (orderTrackerList) {
                orderTrackerList.addEventListener("click", (event) => {
                    const card = event.target.closest(".order-tracker-card");
                    if (!card) {
                        return;
                    }
                    activeTrackedOrderId = Number(card.dataset.orderId || 0);
                    refreshUserOrders();
                });
            }

            loadSavedCart();
            renderCart();
            applyProductSearch("");
            refreshUserOrders();
            orderPollTimer = window.setInterval(refreshUserOrders, 10000);
        } else {
            const centerButton = document.getElementById("menu-center-store-pin");
            let storePinMarker = null;

            function placeStorePin(lat, lng) {
                if (storePinMarker) {
                    storePinMarker.setLatLng([lat, lng]);
                } else {
                    storePinMarker = L.marker([lat, lng], { icon: storeHomeIcon })
                        .addTo(map)
                        .bindPopup("Your store location");
                }
            }

            const hasStoredPin = storeHomePin
                && Number.isFinite(Number(storeHomePin.lat))
                && Number.isFinite(Number(storeHomePin.lng));

            if (hasStoredPin) {
                const lat = Number(storeHomePin.lat);
                const lng = Number(storeHomePin.lng);
                placeStorePin(lat, lng);
                map.setView([lat, lng], 15);
                setStatus("Map centered to your saved store pin.");
            } else if ("geolocation" in navigator) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        map.setView([position.coords.latitude, position.coords.longitude], 14);
                        setStatus("Open menu for Profile and Product.");
                    },
                    () => {
                        map.setView([14.5995, 120.9842], 12);
                        setStatus("Open menu for Profile and Product.");
                    },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            } else {
                map.setView([14.5995, 120.9842], 12);
                setStatus("Open menu for Profile and Product.");
            }

            if (centerButton) {
                centerButton.addEventListener("click", () => {
                    if (storePinMarker) {
                        map.flyTo(storePinMarker.getLatLng(), 15, { duration: 0.45 });
                        setStatus("Centered to your store pin.");
                    } else {
                        setStatus("No saved store pin yet. Set it in Profile.");
                    }
                    closeMenu();
                });
            }
        }
    </script>
</body>
</html>
