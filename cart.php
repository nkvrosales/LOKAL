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
if ($account_type === "store") {
    header("Location: home.php");
    exit;
}

$stores = [];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cart | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/store-admin.css?v=cart-page-1">
    <link rel="stylesheet" href="assets/home.css?v=cart-page-1">
</head>
<body class="cart-screen store-admin-body">
    <header class="top-bar">
        <a class="logo" href="home.php" style="text-decoration:none">
            <span style="color:var(--primary, #FF4D2E)">LOKAL</span>
        </a>
        <nav class="store-admin-nav" aria-label="Cart navigation">
            <a class="store-admin-tab" href="home.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Home</span>
            </a>
            <a class="store-admin-tab" href="account_profile.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Profile</span>
            </a>
            <a class="store-admin-tab" href="order_history.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span>Orders</span>
            </a>
            <a class="store-admin-tab active" href="cart.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.8"/><circle cx="18" cy="20" r="1.8"/><path d="M3 4h2.5l2.2 11.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7"/></svg>
                <span>Cart</span>
            </a>
            <a class="store-admin-tab" href="logout.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Log out</span>
            </a>
        </nav>
    </header>

    <main class="store-admin-shell cart-page">
        <section class="store-admin-card">
            <h1>Your Cart</h1>

            <p class="cart-page-summary" id="cart-summary">No items yet.</p>

            <div class="cart-items" id="cart-items">
                <p class="cart-empty">Your cart is empty.</p>
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
    </main>

    <script>
        const stores = <?php echo json_encode($stores, JSON_UNESCAPED_SLASHES); ?>;
        const userHomePin = <?php echo json_encode($user_home_pin, JSON_UNESCAPED_SLASHES); ?>;

        const storeById = new Map(stores.map((store) => [String(store.id), store]));
        const cartStorageKey = "lokal_cart_items";
        const cart = new Map();
        let checkoutType = "delivery";

        const cartItemsEl = document.getElementById("cart-items");
        const cartSummaryEl = document.getElementById("cart-summary");
        const cartTotalEl = document.getElementById("cart-total");
        const checkoutOptionsEl = document.getElementById("checkout-options");
        const checkoutBreakdownEl = document.getElementById("checkout-breakdown");
        const cartClearButton = document.getElementById("cart-clear");
        const cartCheckoutButton = document.getElementById("cart-checkout");

        const hasUserHomePin = userHomePin
            && Number.isFinite(Number(userHomePin.lat))
            && Number.isFinite(Number(userHomePin.lng));

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, (char) => ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                "\"": "&quot;",
                "'": "&#39;"
            }[char]));
        }

        function normalizeQuantity(value, fallback = 1) {
            const parsed = Number.parseInt(value, 10);
            if (!Number.isFinite(parsed)) {
                return fallback;
            }
            return Math.max(1, Math.min(99, parsed));
        }

        function findStoreProduct(storeId, productId) {
            const store = storeById.get(String(storeId));
            if (!store || !Array.isArray(store.products)) {
                return null;
            }
            const product = store.products.find((item) => String(item.id) === String(productId));
            return product ? { store, product } : null;
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

        function getCartItemCount() {
            let count = 0;
            cart.forEach((item) => {
                count += item.quantity;
            });
            return count;
        }

        function renderCart() {
            const items = Array.from(cart.values());
            const itemCount = getCartItemCount();
            const breakdown = getCartBreakdown();

            cartSummaryEl.textContent = itemCount === 0
                ? "No items yet."
                : `${breakdown.storeCount} store${breakdown.storeCount === 1 ? "" : "s"}, ${itemCount} item${itemCount === 1 ? "" : "s"} in cart.`;
            cartTotalEl.textContent = breakdown.hasUnpricedItem
                ? `${formatCartPrice(breakdown.total)} + unpriced item`
                : formatCartPrice(breakdown.total);
            cartClearButton.disabled = itemCount === 0;
            cartCheckoutButton.hidden = breakdown.storeCount !== 1;
            cartCheckoutButton.disabled = itemCount === 0;

            if (itemCount === 0) {
                checkoutBreakdownEl.innerHTML = "";
            } else if (breakdown.storeCount === 1) {
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
            } else {
                checkoutBreakdownEl.innerHTML = `<p class="checkout-note">You have items from multiple stores. Checkout each store separately below.</p>`;
            }

            if (!items.length) {
                cartItemsEl.innerHTML = `<p class="cart-empty">Your cart is empty. Add products from a store.</p>`;
                return;
            }

            const groups = new Map();
            items.forEach((item) => {
                const storeId = item.storeId;
                if (!groups.has(storeId)) {
                    groups.set(storeId, { storeName: item.storeName, items: [] });
                }
                groups.get(storeId).items.push(item);
            });

            cartItemsEl.innerHTML = Array.from(groups.entries()).map(([storeId, group]) => {
                const groupSubtotal = group.items.reduce((total, item) => {
                    return Number.isFinite(Number(item.price))
                        ? total + Number(item.price) * item.quantity
                        : total;
                }, 0);
                const itemLines = group.items.map((item) => {
                    const lineTotal = Number.isFinite(item.price)
                        ? formatCartPrice(item.price * item.quantity)
                        : "Price not set";
                    return `<article class="cart-item" data-cart-key="${escapeHtml(item.key)}">
                                <div class="cart-item-copy">
                                    <strong>${escapeHtml(item.name)}</strong>
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
                return `<article class="cart-store-group" data-store-id="${escapeHtml(storeId)}">
                            <div class="cart-store-group-head">
                                <h2>${escapeHtml(group.storeName)}</h2>
                                <strong>${escapeHtml(formatCartPrice(groupSubtotal))}</strong>
                            </div>
                            <div class="cart-store-group-items">${itemLines}</div>
                            <a class="btn cart-checkout-btn cart-store-checkout" href="store_checkout.php?store_id=${encodeURIComponent(storeId)}">Checkout ${escapeHtml(group.storeName)}</a>
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

        function openCheckout() {
            if (!cart.size) {
                return;
            }
            const firstItem = cart.values().next().value;
            if (!firstItem) {
                return;
            }
            window.location.href = `store_checkout.php?store_id=${encodeURIComponent(firstItem.storeId)}`;
        }

        if (cartCheckoutButton) {
            cartCheckoutButton.addEventListener("click", (event) => {
                event.preventDefault();
                const storeCheckoutLink = cartItemsEl.querySelector(".cart-store-checkout");
                if (storeCheckoutLink) {
                    window.location.href = storeCheckoutLink.getAttribute("href");
                } else {
                    openCheckout();
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

        loadSavedCart();
        renderCart();
    </script>
</body>
</html>