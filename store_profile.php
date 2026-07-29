<?php
require_once "auth.php";
require_once "db.php";
require_login();

$storeId = (int) ($_GET["id"] ?? 0);
if ($storeId <= 0) {
    header("Location: account_profile.php");
    exit;
}

$format_price_label = static function ($price): string {
    if ($price === null || $price === "") {
        return "";
    }
    return "PHP " . number_format((float) $price, 2);
};

$store = null;
$products = [];

$stmt = $mysqli->prepare(
    "SELECT id, store_name, first_name, last_name, store_address, store_lat, store_lng, store_contact, contact, email
     FROM users
     WHERE id = ?
       AND account_type = 'store'
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param("i", $storeId);
    $stmt->execute();
    $stmt->bind_result(
        $rowStoreId,
        $storeName,
        $firstName,
        $lastName,
        $storeAddress,
        $storeLat,
        $storeLng,
        $storeContact,
        $defaultContact,
        $email
    );
    if ($stmt->fetch()) {
        $fallbackName = trim((string) $firstName . " " . (string) $lastName);
        $displayName = trim((string) $storeName);
        if ($displayName === "") {
            $displayName = $fallbackName !== "" ? $fallbackName : "Store #" . $rowStoreId;
        }
        $displayContact = trim((string) $storeContact);
        if ($displayContact === "") {
            $displayContact = trim((string) $defaultContact);
        }

        $store = [
            "id" => (int) $rowStoreId,
            "name" => $displayName,
            "address" => (string) ($storeAddress ?? ""),
            "lat" => $storeLat !== null ? (float) $storeLat : null,
            "lng" => $storeLng !== null ? (float) $storeLng : null,
            "contact" => $displayContact,
            "email" => (string) ($email ?? ""),
        ];
    }
    $stmt->close();
}

if (!$store) {
    header("Location: home.php");
    exit;
}

$productStmt = $mysqli->prepare(
    "SELECT id, product_name, product_description, product_price
     FROM store_products
     WHERE store_user_id = ?
     ORDER BY id DESC"
);
if ($productStmt) {
    $productStmt->bind_param("i", $storeId);
    $productStmt->execute();
    $productStmt->bind_result($productId, $productName, $productDescription, $productPrice);
    while ($productStmt->fetch()) {
        $products[] = [
            "id" => (int) $productId,
            "name" => trim((string) ($productName ?? "")),
            "description" => trim((string) ($productDescription ?? "")),
            "price" => $productPrice !== null ? (float) $productPrice : null,
            "price_label" => $format_price_label($productPrice),
        ];
    }
    $productStmt->close();
}

$storeForCart = $store;
$storeForCart["products"] = $products;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($store["name"]); ?> | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/store-admin.css?v=hover-effects-1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
</head>
<body class="store-admin-body">
    <header class="top-bar">
        <div class="logo">Lokal</div>
        <nav class="store-admin-nav" aria-label="Store profile navigation">
            <a class="store-admin-tab" href="home.php">Home</a>
            <a class="store-admin-tab" href="order_history.php">Orders</a>
            <a class="store-admin-tab" href="account_profile.php">Profile</a>
            <a class="store-admin-tab" href="logout.php">Log out</a>
        </nav>
    </header>

    <main class="public-store-shell">
        <section class="public-store-head">
            <div>
                <a class="back-link" href="home.php">Back to stores</a>
                <h1><?php echo escape($store["name"]); ?></h1>
                <p><?php echo escape($store["address"] !== "" ? $store["address"] : "Store location not listed."); ?></p>
            </div>
            <button type="button" class="btn public-cart-link" id="public-cart-open">Open Cart <span id="public-cart-count">0</span></button>
        </section>

        <section class="public-store-grid">
            <article class="public-store-card">
                <h2>Store Data</h2>
                <p><strong>Contact:</strong> <?php echo escape($store["contact"] !== "" ? $store["contact"] : "Not listed"); ?></p>
                <p><strong>Email:</strong> <?php echo escape($store["email"] !== "" ? $store["email"] : "Not listed"); ?></p>
                <p><strong>Products:</strong> <?php echo count($products); ?></p>
            </article>

            <article class="public-store-card">
                <h2>Location</h2>
                <div id="public-store-map"></div>
            </article>
        </section>

        <section class="public-store-products">
            <div class="public-store-section-head">
                <h2>Products</h2>
                <p id="cart-status">Add products here, then open the cart to update quantities.</p>
            </div>

            <?php if ($products): ?>
                <div class="public-product-list">
                    <?php foreach ($products as $product): ?>
                        <article class="public-product-item">
                            <div>
                                <h3><?php echo escape($product["name"] !== "" ? $product["name"] : "Product"); ?></h3>
                                <p class="public-product-price"><?php echo escape($product["price_label"] !== "" ? $product["price_label"] : "Price not set"); ?></p>
                                <?php if ($product["description"] !== ""): ?>
                                    <p><?php echo escape($product["description"]); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="public-add-controls">
                                <label>
                                    <span>Qty</span>
                                    <input type="number" min="1" max="99" value="1" inputmode="numeric" data-product-quantity>
                                </label>
                                <button type="button" data-product-id="<?php echo (int) $product["id"]; ?>">Add to cart</button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="store-products-empty">No products listed yet.</p>
            <?php endif; ?>
        </section>
    </main>

    <section class="public-cart-modal" id="public-cart-modal" aria-label="Cart" hidden>
        <div class="public-cart-backdrop" data-cart-close></div>
        <div class="public-cart-panel" role="dialog" aria-modal="true" aria-labelledby="public-cart-title">
            <div class="public-cart-head">
                <div>
                    <h2 id="public-cart-title">Cart</h2>
                    <p id="public-cart-summary">No items yet.</p>
                </div>
                <button type="button" class="public-cart-close" data-cart-close aria-label="Close cart">&times;</button>
            </div>
            <div class="public-cart-items" id="public-cart-items"></div>
            <div class="public-cart-foot">
                <div>
                    <span>Total</span>
                    <strong id="public-cart-total">PHP 0.00</strong>
                </div>
                <div class="public-cart-actions">
                    <a class="btn public-cart-checkout" href="store_checkout.php?store_id=<?php echo (int) $store["id"]; ?>">Checkout</a>
                    <button type="button" class="public-cart-clear" id="public-cart-clear">Clear</button>
                </div>
            </div>
        </div>
    </section>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const store = <?php echo json_encode($storeForCart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const cartStorageKey = "lokal_cart_items";
        const statusEl = document.getElementById("cart-status");
        const publicCartCount = document.getElementById("public-cart-count");
        const publicCartOpen = document.getElementById("public-cart-open");
        const publicCartModal = document.getElementById("public-cart-modal");
        const publicCartItems = document.getElementById("public-cart-items");
        const publicCartSummary = document.getElementById("public-cart-summary");
        const publicCartTotal = document.getElementById("public-cart-total");
        const publicCartClear = document.getElementById("public-cart-clear");

        function setStatus(message) {
            if (statusEl) {
                statusEl.textContent = message;
            }
        }

        function escapeHtml(value) {
            return String(value ?? "")
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function loadCart() {
            try {
                const saved = JSON.parse(window.localStorage.getItem(cartStorageKey) || "[]");
                if (!Array.isArray(saved)) {
                    return [];
                }
                const currentStoreItems = saved.filter((item) => String(item.storeId || "") === String(store.id));
                if (saved.length && currentStoreItems.length !== saved.length) {
                    saveCart([]);
                    return [];
                }
                return currentStoreItems;
            } catch (error) {
                return [];
            }
        }

        function saveCart(items) {
            window.localStorage.setItem(cartStorageKey, JSON.stringify(items));
        }

        function normalizeQuantity(value, fallback = 1) {
            const parsed = Number.parseInt(value, 10);
            if (!Number.isFinite(parsed)) {
                return fallback;
            }
            return Math.max(1, Math.min(99, parsed));
        }

        function getCartItemCount(items = loadCart()) {
            return items.reduce((total, item) => total + normalizeQuantity(item.quantity, 0), 0);
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

        function getCartTotal(items = loadCart()) {
            return items.reduce((total, item) => {
                const price = Number(item.price);
                return Number.isFinite(price)
                    ? total + price * normalizeQuantity(item.quantity)
                    : total;
            }, 0);
        }

        function renderCartCount() {
            if (publicCartCount) {
                publicCartCount.textContent = String(getCartItemCount());
            }
        }

        function renderCart() {
            const items = loadCart();
            const itemCount = getCartItemCount(items);
            renderCartCount();
            if (publicCartSummary) {
                publicCartSummary.textContent = itemCount === 0
                    ? "No items yet."
                    : `${items.length} product${items.length === 1 ? "" : "s"}, ${itemCount} item${itemCount === 1 ? "" : "s"}.`;
            }
            if (publicCartTotal) {
                publicCartTotal.textContent = formatCartPrice(getCartTotal(items));
            }
            if (publicCartClear) {
                publicCartClear.disabled = itemCount === 0;
            }
            if (!publicCartItems) {
                return;
            }
            if (!items.length) {
                publicCartItems.innerHTML = `<p class="public-cart-empty">Your cart is empty.</p>`;
                return;
            }
            publicCartItems.innerHTML = items.map((item) => {
                const quantity = normalizeQuantity(item.quantity);
                const price = Number(item.price);
                const lineTotal = Number.isFinite(price) ? formatCartPrice(price * quantity) : "Price not set";
                return `<article class="public-cart-item" data-cart-key="${escapeHtml(item.key)}">
                            <div>
                                <strong>${escapeHtml(item.name || "Product")}</strong>
                                <span>${escapeHtml(lineTotal)}</span>
                            </div>
                            <div class="public-cart-qty">
                                <button type="button" data-cart-action="decrease" aria-label="Decrease quantity">-</button>
                                <span>${quantity}</span>
                                <button type="button" data-cart-action="increase" aria-label="Increase quantity">+</button>
                                <button type="button" data-cart-action="remove">Remove</button>
                            </div>
                        </article>`;
            }).join("");
        }

        function setCartModalOpen(isOpen) {
            if (!publicCartModal) {
                return;
            }
            publicCartModal.hidden = !isOpen;
            if (isOpen) {
                renderCart();
            }
        }

        function getRequestedQuantity(button) {
            const item = button ? button.closest(".public-product-item") : null;
            const input = item ? item.querySelector("[data-product-quantity]") : null;
            const quantity = normalizeQuantity(input ? input.value : 1);
            if (input) {
                input.value = String(quantity);
            }
            return quantity;
        }

        function addProduct(productId, quantity = 1) {
            const product = Array.isArray(store.products)
                ? store.products.find((item) => String(item.id) === String(productId))
                : null;
            if (!product) {
                return;
            }

            const key = `${store.id}:${product.id}`;
            const items = loadCart();
            const existing = items.find((item) => item.key === key);
            const amount = normalizeQuantity(quantity);
            let nextQuantity = amount;
            if (existing) {
                nextQuantity = Math.min(99, normalizeQuantity(existing.quantity, 0) + amount);
                existing.quantity = nextQuantity;
            } else {
                items.push({
                    key,
                    storeId: String(store.id),
                    productId: String(product.id),
                    storeName: store.name || "Store",
                    name: product.name || "Product",
                    price: product.price !== null && product.price !== "" ? Number(product.price) : null,
                    quantity: nextQuantity
                });
            }
            saveCart(items);
            renderCart();
            setStatus(`${product.name || "Product"} quantity in cart: ${nextQuantity}. Cart has ${getCartItemCount(items)} item(s).`);
        }

        function updateCartItem(cartKey, nextQuantity) {
            const items = loadCart();
            const index = items.findIndex((item) => item.key === cartKey);
            if (index === -1) {
                return;
            }
            const quantity = Math.max(0, Math.min(99, Number.parseInt(nextQuantity, 10) || 0));
            if (quantity <= 0) {
                items.splice(index, 1);
            } else {
                items[index].quantity = quantity;
            }
            saveCart(items);
            renderCart();
            setStatus(items.length ? "Cart updated." : "Cart cleared.");
        }

        document.querySelectorAll("[data-product-id]").forEach((button) => {
            button.addEventListener("click", () => {
                addProduct(
                    button.dataset.productId || "",
                    getRequestedQuantity(button)
                );
            });
        });

        if (publicCartOpen) {
            publicCartOpen.addEventListener("click", () => setCartModalOpen(true));
        }

        if (publicCartModal) {
            publicCartModal.addEventListener("click", (event) => {
                const closeButton = event.target.closest("[data-cart-close]");
                if (closeButton) {
                    setCartModalOpen(false);
                    return;
                }
                const actionButton = event.target.closest("[data-cart-action]");
                if (!actionButton) {
                    return;
                }
                const item = actionButton.closest(".public-cart-item");
                if (!item) {
                    return;
                }
                const items = loadCart();
                const cartItem = items.find((entry) => entry.key === item.dataset.cartKey);
                if (!cartItem) {
                    return;
                }
                const quantity = normalizeQuantity(cartItem.quantity);
                const action = actionButton.dataset.cartAction || "";
                if (action === "increase") {
                    updateCartItem(cartItem.key, quantity + 1);
                } else if (action === "decrease") {
                    updateCartItem(cartItem.key, quantity - 1);
                } else if (action === "remove") {
                    updateCartItem(cartItem.key, 0);
                }
            });
        }

        if (publicCartClear) {
            publicCartClear.addEventListener("click", () => {
                saveCart([]);
                renderCart();
                setStatus("Cart cleared.");
            });
        }

        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                setCartModalOpen(false);
            }
        });

        renderCart();

        if (Number.isFinite(Number(store.lat)) && Number.isFinite(Number(store.lng))) {
            const map = L.map("public-store-map", { zoomControl: false }).setView([Number(store.lat), Number(store.lng)], 16);
            L.control.zoom({ position: "bottomright" }).addTo(map);
            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                attribution: "&copy; OpenStreetMap contributors"
            }).addTo(map);
            L.marker([Number(store.lat), Number(store.lng)]).addTo(map).bindPopup(store.name || "Store").openPopup();
        } else {
            document.getElementById("public-store-map").innerHTML = "<p>Location not available.</p>";
        }
    </script>
</body>
</html>
