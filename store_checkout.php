<?php
require_once "auth.php";
require_once "db.php";
require_login();

if (($_SESSION["account_type"] ?? "") === "store") {
    header("Location: home.php");
    exit;
}

$storeId = (int) ($_GET["store_id"] ?? $_GET["id"] ?? 0);
if ($storeId <= 0) {
    header("Location: home.php");
    exit;
}

$store = null;
$user = [
    "address" => "",
    "lat" => null,
    "lng" => null,
];

$stmt = $mysqli->prepare(
    "SELECT id, store_name, first_name, last_name, store_address
     FROM users
     WHERE id = ?
       AND account_type = 'store'
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param("i", $storeId);
    $stmt->execute();
    $stmt->bind_result($rowStoreId, $storeName, $firstName, $lastName, $storeAddress);
    if ($stmt->fetch()) {
        $fallbackName = trim((string) $firstName . " " . (string) $lastName);
        $displayName = trim((string) $storeName);
        if ($displayName === "") {
            $displayName = $fallbackName !== "" ? $fallbackName : "Store #" . $rowStoreId;
        }
        $store = [
            "id" => (int) $rowStoreId,
            "name" => $displayName,
            "address" => (string) ($storeAddress ?? ""),
        ];
    }
    $stmt->close();
}

if (!$store) {
    header("Location: home.php");
    exit;
}

$userId = (int) ($_SESSION["user_id"] ?? 0);
$userStmt = $mysqli->prepare("SELECT user_address, user_lat, user_lng FROM users WHERE id = ? LIMIT 1");
if ($userStmt) {
    $userStmt->bind_param("i", $userId);
    $userStmt->execute();
    $userStmt->bind_result($userAddress, $userLat, $userLng);
    if ($userStmt->fetch()) {
        $user["address"] = trim((string) ($userAddress ?? ""));
        $user["lat"] = $userLat !== null ? (float) $userLat : null;
        $user["lng"] = $userLng !== null ? (float) $userLng : null;
    }
    $userStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/store-admin.css?v=hover-effects-1">
</head>
<body class="store-admin-body">
    <header class="top-bar">
        <a class="logo" href="home.php" style="text-decoration:none">Lokal</a>
        <nav class="store-admin-nav" aria-label="Checkout navigation">
            <a class="store-admin-tab" href="store_profile.php?id=<?php echo (int) $store["id"]; ?>">Back to store</a>
            <a class="store-admin-tab" href="home.php">Home</a>
        </nav>
    </header>

    <main class="public-store-shell checkout-page-shell">
        <section class="public-store-head">
            <div>
                <a class="back-link" href="store_profile.php?id=<?php echo (int) $store["id"]; ?>">Back to store</a>
                <h1>Checkout</h1>
                <p><?php echo escape($store["name"]); ?></p>
            </div>
        </section>

        <section class="checkout-page-grid">
            <article class="public-store-card checkout-card">
                <h2>Items</h2>
                <div class="checkout-items" id="checkout-items">
                    <p class="public-cart-empty">Loading cart...</p>
                </div>
            </article>

            <article class="public-store-card checkout-card">
                <h2>Order</h2>
                <p id="checkout-summary">Review your cart before placing the order.</p>

                <div class="checkout-choice">
                    <label>
                        <input type="radio" name="checkout_type" value="delivery" checked>
                        <span>Delivery</span>
                    </label>
                    <label>
                        <input type="radio" name="checkout_type" value="pickup">
                        <span>Pickup</span>
                    </label>
                </div>

                <div class="checkout-address">
                    <span>Delivery address</span>
                    <strong><?php echo escape($user["address"] !== "" ? $user["address"] : "No saved delivery address"); ?></strong>
                </div>

                <div class="checkout-breakdown-card" id="checkout-breakdown">
                    <p class="public-cart-empty">No totals yet.</p>
                </div>

                <div class="checkout-total-row">
                    <span>Grand total</span>
                    <strong id="checkout-total">PHP 0.00</strong>
                </div>

                <p class="status-text" id="checkout-status"></p>

                <div class="checkout-page-actions">
                    <button type="button" class="btn" id="place-order">Place order</button>
                    <a class="btn checkout-secondary" href="store_profile.php?id=<?php echo (int) $store["id"]; ?>">Back to store</a>
                </div>
            </article>
        </section>
    </main>

    <script>
        const storeId = "<?php echo (int) $store["id"]; ?>";
        const cartStorageKey = "lokal_cart_items";
        const userHomePin = <?php echo json_encode($user, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const checkoutItems = document.getElementById("checkout-items");
        const checkoutSummary = document.getElementById("checkout-summary");
        const checkoutBreakdown = document.getElementById("checkout-breakdown");
        const checkoutTotal = document.getElementById("checkout-total");
        const checkoutStatus = document.getElementById("checkout-status");
        const placeOrderButton = document.getElementById("place-order");
        let checkoutType = "delivery";
        const deliveryFee = 40;

        function escapeHtml(value) {
            return String(value ?? "")
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function normalizeQuantity(value, fallback = 1) {
            const parsed = Number.parseInt(value, 10);
            if (!Number.isFinite(parsed)) {
                return fallback;
            }
            return Math.max(1, Math.min(99, parsed));
        }

        function formatCartPrice(value) {
            const amount = Number(value);
            if (!Number.isFinite(amount)) {
                return "Price not set";
            }
            return `PHP ${amount.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            })}`;
        }

        function loadCart() {
            try {
                const saved = JSON.parse(window.localStorage.getItem(cartStorageKey) || "[]");
                if (!Array.isArray(saved)) {
                    return [];
                }
                return saved.filter((item) => String(item.storeId || "") === storeId);
            } catch (error) {
                return [];
            }
        }

        function saveCart(items) {
            window.localStorage.setItem(cartStorageKey, JSON.stringify(items));
        }

        function getSubtotal(items) {
            return items.reduce((total, item) => {
                const price = Number(item.price);
                return Number.isFinite(price)
                    ? total + price * normalizeQuantity(item.quantity)
                    : total;
            }, 0);
        }

        function getCheckoutTotal(items) {
            return getSubtotal(items) + (checkoutType === "delivery" ? deliveryFee : 0);
        }

        function renderCheckout() {
            const items = loadCart();
            const itemCount = items.reduce((total, item) => total + normalizeQuantity(item.quantity, 0), 0);
            if (!items.length) {
                checkoutItems.innerHTML = `<p class="public-cart-empty">Your cart is empty.</p>`;
                checkoutSummary.textContent = "Add products from the store before checkout.";
                checkoutTotal.textContent = formatCartPrice(0);
                checkoutBreakdown.innerHTML = `<p class="public-cart-empty">No totals yet.</p>`;
                placeOrderButton.disabled = true;
                return;
            }

            checkoutSummary.textContent = `${items.length} product${items.length === 1 ? "" : "s"}, ${itemCount} item${itemCount === 1 ? "" : "s"}.`;
            checkoutTotal.textContent = formatCartPrice(getCheckoutTotal(items));
            placeOrderButton.disabled = false;
            checkoutBreakdown.innerHTML = `${items.map((item) => {
                const quantity = normalizeQuantity(item.quantity);
                const price = Number(item.price);
                const lineTotal = Number.isFinite(price) ? price * quantity : Number.NaN;
                return `<p>
                            <span>${escapeHtml(item.name || "Product")} x ${quantity}</span>
                            <strong>${escapeHtml(formatCartPrice(lineTotal))}</strong>
                        </p>`;
            }).join("")}
                <p>
                    <span>Delivery Fee</span>
                    <strong>${escapeHtml(formatCartPrice(checkoutType === "delivery" ? deliveryFee : 0))}</strong>
                </p>
                <p class="grand-total-line">
                    <span>Grand total</span>
                    <strong>${escapeHtml(formatCartPrice(getCheckoutTotal(items)))}</strong>
                </p>`;
            checkoutItems.innerHTML = items.map((item) => {
                const quantity = normalizeQuantity(item.quantity);
                const price = Number(item.price);
                const lineTotal = Number.isFinite(price) ? formatCartPrice(price * quantity) : "Price not set";
                return `<article class="checkout-item">
                            <div>
                                <strong>${escapeHtml(item.name || "Product")}</strong>
                                <span>${escapeHtml(item.storeName || "Store")}</span>
                            </div>
                            <div>
                                <span>Qty ${quantity}</span>
                                <strong>${escapeHtml(lineTotal)}</strong>
                            </div>
                        </article>`;
            }).join("");
        }

        async function getCurrentLocation() {
            if (!navigator.geolocation) {
                throw new Error("Geolocation is not available.");
            }
            return new Promise((resolve, reject) => {
                navigator.geolocation.getCurrentPosition(
                    (position) => resolve({
                        lat: position.coords.latitude,
                        lng: position.coords.longitude
                    }),
                    () => reject(new Error("Allow location access to place a delivery order.")),
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
                );
            });
        }

        async function placeOrder() {
            const items = loadCart();
            if (!items.length || !placeOrderButton) {
                return;
            }

            placeOrderButton.disabled = true;
            placeOrderButton.textContent = checkoutType === "delivery" ? "Locating..." : "Placing...";
            checkoutStatus.textContent = checkoutType === "delivery" ? "Getting delivery location..." : "Placing pickup order...";

            try {
                const hasSavedPin = Number.isFinite(Number(userHomePin.lat)) && Number.isFinite(Number(userHomePin.lng));
                const location = checkoutType === "delivery" && !hasSavedPin
                    ? await getCurrentLocation()
                    : { lat: userHomePin.lat, lng: userHomePin.lng };

                placeOrderButton.textContent = "Sending...";
                const response = await fetch("checkout.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        order_type: checkoutType,
                        delivery_lat: checkoutType === "delivery" ? location.lat : null,
                        delivery_lng: checkoutType === "delivery" ? location.lng : null,
                        items: items.map((item) => ({
                            product_id: item.productId,
                            quantity: normalizeQuantity(item.quantity)
                        }))
                    })
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.message || "Checkout failed.");
                }

                saveCart([]);
                renderCheckout();
                checkoutStatus.textContent = result.message || "Order placed.";
                placeOrderButton.textContent = "Order placed";
            } catch (error) {
                checkoutStatus.textContent = error.message || "Checkout failed. Please try again.";
                placeOrderButton.disabled = false;
                placeOrderButton.textContent = "Place order";
            }
        }

        document.querySelectorAll("input[name='checkout_type']").forEach((input) => {
            input.addEventListener("change", () => {
                checkoutType = input.value === "pickup" ? "pickup" : "delivery";
                renderCheckout();
            });
        });

        if (placeOrderButton) {
            placeOrderButton.addEventListener("click", placeOrder);
        }

        renderCheckout();
    </script>
</body>
</html>
