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

function store_hours_status(string $hours): string {
    $value = trim($hours);
    if ($value === "") {
        return "Closed now";
    }

    if (stripos($value, "24/7") !== false || stripos($value, "24 hours") !== false) {
        return "Open now";
    }

    $days = [
        "sun" => 0,
        "mon" => 1,
        "tue" => 2,
        "wed" => 3,
        "thu" => 4,
        "fri" => 5,
        "sat" => 6,
    ];

    $normalizeDay = static function (string $token): int {
        $token = strtolower(substr(trim($token), 0, 3));
        return $days[$token] ?? 0;
    };

    $normalizeMinutes = static function (int $hour, int $minute): int {
        $hour = max(0, min(23, $hour));
        $minute = max(0, min(59, $minute));
        return ($hour * 60) + $minute;
    };

    $matches = [];
    preg_match_all(
        '/((?:mon|tue|wed|thu|fri|sat|sun))\s*(?:-\s*((?:mon|tue|wed|thu|fri|sat|sun)))?\s*,?\s*([0-9]{1,2})(?::([0-9]{2}))?\s*(am|pm)\s*-\s*([0-9]{1,2})(?::([0-9]{2}))?\s*(am|pm)/i',
        $value,
        $matches,
        PREG_SET_ORDER
    );

    $now = new DateTimeImmutable('now');
    $nowMinutes = $normalizeMinutes((int) $now->format('G'), (int) $now->format('i'));
    $currentDay = (int) $now->format('w');
    $currentDay = $currentDay === 0 ? 0 : $currentDay; // Sunday => 0

    foreach ($matches as $match) {
        $startDay = $normalizeDay($match[1]);
        $endDay = isset($match[2]) && trim($match[2]) !== '' ? $normalizeDay($match[2]) : $startDay;
        $startHour = (int) $match[3];
        $startMinute = (int) ($match[4] ?? 0);
        $endHour = (int) $match[5];
        $endMinute = (int) ($match[6] ?? 0);
        $startMeridiem = strtolower($match[7]);
        $endMeridiem = strtolower($match[8]);

        if (strtolower($startMeridiem) === 'pm' && $startHour < 12) {
            $startHour += 12;
        }
        if (strtolower($startMeridiem) === 'am' && $startHour === 12) {
            $startHour = 0;
        }
        if (strtolower($endMeridiem) === 'pm' && $endHour < 12) {
            $endHour += 12;
        }
        if (strtolower($endMeridiem) === 'am' && $endHour === 12) {
            $endHour = 0;
        }

        $startMinutes = $normalizeMinutes($startHour, $startMinute);
        $endMinutes = $normalizeMinutes($endHour, $endMinute);

        $inRange = false;
        $daySpan = $endDay - $startDay;
        if ($daySpan >= 0) {
            $inRange = $currentDay >= $startDay && $currentDay <= $endDay;
        } else {
            $inRange = $currentDay >= $startDay || $currentDay <= $endDay;
        }

        if (!$inRange) {
            continue;
        }

        if ($endMinutes < $startMinutes) {
            $inRange = ($nowMinutes >= $startMinutes || $nowMinutes <= $endMinutes);
        } else {
            $inRange = $nowMinutes >= $startMinutes && $nowMinutes <= $endMinutes;
        }

        if ($inRange) {
            return "Open now";
        }
    }

    return "Closed now";
}

$store = null;
$products = [];

$stmt = $mysqli->prepare(
    "SELECT id, store_name, first_name, last_name, store_address, store_lat, store_lng, store_contact, contact, email, store_hours, store_category, profile_image
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
        $email,
        $storeHours,
        $storeCategory,
        $profileImage
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
            "hours" => trim((string) ($storeHours ?? "")),
            "category" => (string) ($storeCategory ?? "Store"),
            "profile_image" => (string) ($profileImage ?? ""),
        ];
    }
    $stmt->close();
}

if (!$store) {
    header("Location: home.php");
    exit;
}

$productStmt = $mysqli->prepare(
    "SELECT id, product_name, product_description, product_price, product_image
     FROM store_products
     WHERE store_user_id = ? AND is_active = 1
     ORDER BY id DESC"
);
if ($productStmt) {
    $productStmt->bind_param("i", $storeId);
    $productStmt->execute();
    $productStmt->bind_result($productId, $productName, $productDescription, $productPrice, $productImage);
    while ($productStmt->fetch()) {
        $products[] = [
            "id"          => (int) $productId,
            "name"        => trim((string) ($productName ?? "")),
            "description" => trim((string) ($productDescription ?? "")),
            "price"       => $productPrice !== null ? (float) $productPrice : null,
            "price_label" => $format_price_label($productPrice),
            "image"       => $productImage ? trim((string) $productImage) : null,
        ];
    }
    $productStmt->close();
}

// Check active pickup orders for this customer at this store
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);
$readyPickupOrders = [];
if ($currentUserId > 0) {
    $pickupCheckStmt = $mysqli->prepare(
        "SELECT id, status, scheduled_time, created_at, accepted_at, pickup_at
         FROM orders
         WHERE customer_user_id = ?
           AND store_user_id = ?
           AND order_type = 'pickup'
           AND (
             status IN ('pending', 'for_pickup', 'delivering')
             OR (status = 'completed' AND DATE(delivered_at) = CURRENT_DATE())
           )
         ORDER BY id DESC
         LIMIT 3"
    );
    if ($pickupCheckStmt) {
        $pickupCheckStmt->bind_param("ii", $currentUserId, $storeId);
        $pickupCheckStmt->execute();
        $pickupCheckStmt->bind_result($pId, $pStatus, $pSched, $pCreated, $pAccepted, $pPickup);
        while ($pickupCheckStmt->fetch()) {
            $isReady = in_array($pStatus, ["for_pickup", "delivering", "completed"], true);
            $readyPickupOrders[] = [
                "id" => (int) $pId,
                "status" => (string) $pStatus,
                "scheduled_time" => (string) ($pSched ?: "ASAP"),
                "created_at" => (string) $pCreated,
                "is_ready" => $isReady,
            ];
        }
        $pickupCheckStmt->close();
    }
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
    <link rel="stylesheet" href="assets/store-admin.css?v=store-enhancements-3">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
</head>
<body class="store-admin-body">
    <header class="top-bar">
        <a class="logo" href="home.php" style="text-decoration:none">
            <span style="color:var(--primary, #FF4D2E)">LOKAL</span>
        </a>
        <nav class="store-admin-nav" aria-label="Store profile navigation">
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
            <?php if (($_SESSION["account_type"] ?? "") !== "store"): ?>
                <a class="store-admin-tab" href="cart.php">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.8"/><circle cx="18" cy="20" r="1.8"/><path d="M3 4h2.5l2.2 11.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7"/></svg>
                    <span>Cart</span>
                </a>
            <?php endif; ?>
            <a class="store-admin-tab" href="logout.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Log out</span>
            </a>
        </nav>
    </header>

    <main class="public-store-shell">

        <!-- â”€â”€ PICKUP ORDER READY NOTIFICATION BANNER â”€â”€ -->
        <?php 
        $activeReadyOrder = null;
        foreach ($readyPickupOrders as $ro) {
            if ($ro["is_ready"]) {
                $activeReadyOrder = $ro;
                break;
            }
        }
        ?>
        <?php if ($activeReadyOrder): ?>
            <aside class="pickup-ready-banner" id="pickup-ready-banner" role="alert">
                <div class="pickup-banner-left">
                    <div class="pickup-banner-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    </div>
                    <div class="pickup-banner-text">
                        <h4>Your Order #<?php echo $activeReadyOrder["id"]; ?> is Ready to Pickup!</h4>
                        <p>Your order at <strong><?php echo escape($store["name"]); ?></strong> is prepared. Please visit the store counter to pick it up. Pickup time: <?php echo escape($activeReadyOrder["scheduled_time"]); ?>. Grace period starts at the chosen pickup time and lasts 15-30 minutes.</p>
                    </div>
                </div>
                <div class="pickup-banner-actions">
                    <a href="order_history.php" class="pickup-banner-btn">View Order</a>
                    <button type="button" class="pickup-banner-close" onclick="document.getElementById('pickup-ready-banner').style.display='none'" aria-label="Dismiss banner">&times;</button>
                </div>
            </aside>
        <?php endif; ?>

        <!-- â”€â”€ STORE HERO HEADER â”€â”€ -->
        <section class="store-hero-head">
            <div class="store-hero-left">
                <div class="store-hero-avatar">
                    <?php if (!empty($store["profile_image"]) && file_exists(__DIR__ . "/uploads/profiles/" . $store["profile_image"])): ?>
                        <img src="uploads/profiles/<?php echo escape($store["profile_image"]); ?>" alt="<?php echo escape($store["name"]); ?>">
                    <?php else: ?>
                        <span><?php echo strtoupper(substr($store["name"] ?: "S", 0, 1)); ?></span>
                    <?php endif; ?>
                </div>
                <div class="store-hero-info">
                    <a class="back-link" href="home.php" style="margin-bottom:4px; display:inline-flex; align-items:center; gap:4px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        Back to stores
                    </a>
                    <h1>
                        <?php echo escape($store["name"]); ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="#3B82F6" stroke="#fff" stroke-width="2"><path d="M12 2l2.4 5.2 5.6.8-4 4.1 1 5.7-5-2.8-5 2.8 1-5.7-4-4.1 5.6-.8z"/></svg>
                    </h1>
                    <div class="store-hero-badges">
                        <span class="store-badge-cat"><?php echo escape($store["category"]); ?></span>
                        <span class="store-badge-open" style="background:<?php echo store_hours_status($store["hours"]) === "Open now" ? '#DCFCE7;color:#166534;' : '#FEE2E2;color:#991B1B;'; ?>; border:1px solid <?php echo store_hours_status($store["hours"]) === "Open now" ? '#86EFAC' : '#FCA5A5'; ?>;">
                            <?php echo store_hours_status($store["hours"]) === "Open now" ? "Open now" : "Closed now"; ?>
                        </span>
                    </div>
                    <p class="store-hero-address">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <?php echo escape($store["address"] !== "" ? $store["address"] : "Store location not listed."); ?>
                    </p>
                    <div class="store-hero-contact-links">
                        <?php if ($store["contact"] !== ""): ?>
                            <a class="store-contact-pill" href="tel:<?php echo escape($store["contact"]); ?>">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                <?php echo escape($store["contact"]); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($store["email"] !== ""): ?>
                            <a class="store-contact-pill" href="mailto:<?php echo escape($store["email"]); ?>">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                Email Store
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- â”€â”€ STORE DATA & MAP â”€â”€ -->
        <section class="public-store-grid">
            <article class="public-store-card">
                <h2>Store Information</h2>
                <div style="display:flex; flex-direction:column; gap:10px; margin-top:10px;">
                    <p style="margin:0;"><strong>Available Products:</strong> <?php echo count($products); ?> items</p>
                    <p style="margin:0;"><strong>Store Hours:</strong> <?php echo escape($store["hours"] !== "" ? $store["hours"] : "Not set yet"); ?></p>
                    <p style="margin:0;"><strong>Service Options:</strong> Delivery (PHP 40.00) &amp; Store Pickup (Free)</p>
                    <p style="margin:0;"><strong>Order Schedule:</strong> ASAP (20-35 mins) or Scheduled Time Slot</p>
                    <p style="margin:0;"><strong>Location:</strong> <?php echo escape($store["address"] !== "" ? $store["address"] : "Not listed"); ?></p>
                </div>
            </article>

            <article class="public-store-card">
                <h2>Store Location</h2>
                <div id="public-store-map"></div>
            </article>
        </section>

        <!-- â”€â”€ PRODUCTS SECTION â”€â”€ -->
        <section class="public-store-products">
            <div class="public-store-section-head">
                <div>
                    <h2>Products</h2>
                    <p id="cart-status">Choose delivery or pickup, select your preferred time, and add items to cart.</p>
                </div>
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <div class="store-search-bar">
                        <svg class="store-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="store-product-search" class="store-search-input" placeholder="Search products in this store...">
                    </div>
                    <button type="button" class="btn public-cart-link" id="public-cart-open">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.8"/><circle cx="18" cy="20" r="1.8"/><path d="M3 4h2.5l2.2 11.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7"/></svg>
                        Open Cart <span id="public-cart-count">0</span>
                    </button>
                </div>
            </div>

            <?php if ($products): ?>
                <div class="public-product-list" id="public-product-list">
                    <?php foreach ($products as $product): ?>
                        <article class="public-product-item" data-product-name="<?php echo strtolower(escape($product["name"])); ?>">
                            <div class="public-product-img-wrap">
                                <?php if (!empty($product["image"]) && file_exists(__DIR__ . "/uploads/products/" . $product["image"])): ?>
                                    <img class="public-product-img" src="uploads/products/<?php echo escape($product["image"]); ?>" alt="<?php echo escape($product["name"]); ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="public-product-img-placeholder">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="3" width="18" height="18" rx="3"/>
                                            <circle cx="8.5" cy="8.5" r="1.5"/>
                                            <polyline points="21 15 16 10 5 21"/>
                                        </svg>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="public-product-body">
                                <h3><?php echo escape($product["name"] !== "" ? $product["name"] : "Product"); ?></h3>
                                <p class="public-product-price"><?php echo escape($product["price_label"] !== "" ? $product["price_label"] : "Price not set"); ?></p>
                                <?php if ($product["description"] !== ""): ?>
                                    <p class="public-product-desc"><?php echo escape($product["description"]); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="public-add-controls">
                                <div class="public-qty-label">
                                    <span>Qty</span>
                                    <div class="public-qty-controls">
                                        <button type="button" class="public-qty-btn minus" data-qty-action="decrease">−</button>
                                        <input type="number" min="1" max="99" value="1" inputmode="numeric" data-product-quantity>
                                        <button type="button" class="public-qty-btn plus" data-qty-action="increase">+</button>
                                    </div>
                                </div>
                                <button type="button" class="public-add-btn" data-product-id="<?php echo (int) $product["id"]; ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Add to cart
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div id="no-products-search-msg" style="display:none; text-align:center; padding:40px 20px; color:#64748B;">
                    No products matched your search.
                </div>
            <?php else: ?>
                <p class="store-products-empty">No products listed yet.</p>
            <?php endif; ?>
        </section>
    </main>

    <!-- â”€â”€ ENHANCED CART MODAL WITH DELIVERY/PICKUP & TIME SELECTION â”€â”€ -->
    <section class="public-cart-modal" id="public-cart-modal" aria-label="Cart" hidden>
        <div class="public-cart-backdrop" data-cart-close></div>
        <div class="public-cart-panel" role="dialog" aria-modal="true" aria-labelledby="public-cart-title" style="width:min(480px, 100%);">
            <div class="public-cart-head">
                <div>
                    <h2 id="public-cart-title">Your Cart</h2>
                    <p id="public-cart-summary">No items yet.</p>
                </div>
                <button type="button" class="public-cart-close" data-cart-close aria-label="Close cart">&times;</button>
            </div>

            <!-- Service Mode Selection: Delivery vs Pickup -->
            <div class="cart-delivery-choice" id="cart-service-choice">
                <button type="button" class="cart-choice-btn active" data-service-type="delivery">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18.5" cy="17.5" r="2.5"/><circle cx="5.5" cy="17.5" r="2.5"/><path d="M12 17.5V14l-3-3 4-3 2 3h4"/></svg>
                    Delivery
                </button>
                <button type="button" class="cart-choice-btn" data-service-type="pickup">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    Pickup
                </button>
            </div>

            <!-- Time Selection: Delivery / Pickup Time -->
            <div class="cart-time-section">
                <div class="cart-time-header">
                    <span id="cart-time-title">Delivery Time</span>

                </div>
                <select id="cart-time-select" class="cart-time-select">
                    <option value="ASAP" selected>ASAP (Estimated 20-35 mins)</option>
                </select>
            </div>

            <!-- Cart Items List -->
            <div class="public-cart-items" id="public-cart-items"></div>

            <!-- Order Total Breakdown & Action -->
            <div class="public-cart-foot">
                <div class="cart-summary-row">
                    <span>Subtotal</span>
                    <strong id="cart-breakdown-subtotal">PHP 0.00</strong>
                </div>
                <div class="cart-summary-row">
                    <span id="cart-breakdown-fee-label">Delivery Fee</span>
                    <strong id="cart-breakdown-fee">PHP 40.00</strong>
                </div>
                <div class="cart-summary-row total-row">
                    <span>Total Amount</span>
                    <strong id="public-cart-total">PHP 0.00</strong>
                </div>

                <div class="public-cart-actions" style="margin-top:8px; width:100%;">
                    <button type="button" class="btn public-cart-checkout" id="cart-checkout-btn" style="flex:1;">Proceed to Checkout</button>
                    <button type="button" class="public-cart-clear" id="public-cart-clear">Clear</button>
                </div>
            </div>
        </div>
    </section>

    <!-- â”€â”€ DYNAMIC FLOATING NOTIFICATION TOAST CONTAINER â”€â”€ -->
    <div id="pickup-toast-container"></div>

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
        const cartSubtotalEl = document.getElementById("cart-breakdown-subtotal");
        const cartFeeEl = document.getElementById("cart-breakdown-fee");
        const cartFeeLabelEl = document.getElementById("cart-breakdown-fee-label");
        const cartTimeTitleEl = document.getElementById("cart-time-title");
        const cartTimeSelect = document.getElementById("cart-time-select");
        const cartCheckoutBtn = document.getElementById("cart-checkout-btn");

        let currentServiceType = "delivery"; // 'delivery' or 'pickup'
        const baseDeliveryFee = 40.0;

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
                return saved.filter((item) => String(item.storeId || "") === String(store.id));
            } catch (error) {
                return [];
            }
        }

        function saveCart(currentStoreItems) {
            let all = [];
            try {
                all = JSON.parse(window.localStorage.getItem(cartStorageKey) || "[]");
                if (!Array.isArray(all)) {
                    all = [];
                }
            } catch (e) {
                all = [];
            }
            const otherStoreItems = all.filter((item) => String(item.storeId || "") !== String(store.id));
            window.localStorage.setItem(cartStorageKey, JSON.stringify(otherStoreItems.concat(currentStoreItems)));
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
                return "PHP 0.00";
            }
            return `PHP ${amount.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            })}`;
        }

        function getCartSubtotal(items = loadCart()) {
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
            const subtotal = getCartSubtotal(items);
            const fee = currentServiceType === "pickup" ? 0.0 : (itemCount > 0 ? baseDeliveryFee : 0.0);
            const grandTotal = subtotal + fee;

            renderCartCount();

            if (publicCartSummary) {
                publicCartSummary.textContent = itemCount === 0
                    ? "No items yet."
                    : `${items.length} product${items.length === 1 ? "" : "s"}, ${itemCount} item${itemCount === 1 ? "" : "s"}.`;
            }

            if (cartSubtotalEl) {
                cartSubtotalEl.textContent = formatCartPrice(subtotal);
            }

            if (cartFeeEl && cartFeeLabelEl) {
                if (currentServiceType === "pickup") {
                    cartFeeLabelEl.textContent = "Pickup Fee";
                    cartFeeEl.textContent = "PHP 0.00";
                    cartFeeEl.style.color = "#10B981";
                } else {
                    cartFeeLabelEl.textContent = "Delivery Fee";
                    cartFeeEl.textContent = formatCartPrice(fee);
                    cartFeeEl.style.color = "#0F172A";
                }
            }

            if (publicCartTotal) {
                publicCartTotal.textContent = formatCartPrice(grandTotal);
            }

            if (publicCartClear) {
                publicCartClear.disabled = itemCount === 0;
            }

            if (cartCheckoutBtn) {
                cartCheckoutBtn.disabled = itemCount === 0;
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
                return `
                    <article class="public-cart-item" data-cart-key="${escapeHtml(item.key)}" style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px; border:1px solid #E2E8F0; border-radius:12px; background:#F8FAFC;">
                        <div style="flex:1; min-width:0;">
                            <strong style="display:block; font-size:14px; color:#0F172A; margin-bottom:2px;">${escapeHtml(item.name || "Product")}</strong>
                            <span style="font-size:12.5px; color:#FF5B2E; font-weight:700;">${escapeHtml(lineTotal)}</span>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div class="cart-item-stepper">
                                <button type="button" class="cart-step-btn" data-cart-action="decrease" aria-label="Decrease quantity">&minus;</button>
                                <span class="cart-step-val">${quantity}</span>
                                <button type="button" class="cart-step-btn" data-cart-action="increase" aria-label="Increase quantity">+</button>
                            </div>
                            <button type="button" class="cart-remove-item" data-cart-action="remove" title="Remove item" aria-label="Remove item">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                            </button>
                        </div>
                    </article>
                `;
            }).join("");
        }

        // â”€â”€ Smart Time Slot Generator (Current Time Aware, No Emojis) â”€â”€
        function generateSmartTimeSlots(selectedVal = "ASAP") {
            const slots = [];
            slots.push({ value: "ASAP", text: "ASAP (Estimated 20-35 mins)" });

            const now = new Date();
            // Start 30 minutes from now
            const start = new Date(now.getTime() + 30 * 60 * 1000);
            
            // Round up to the next 30-minute boundary
            const mins = start.getMinutes();
            if (mins > 0 && mins <= 30) {
                start.setMinutes(30, 0, 0);
            } else if (mins > 30) {
                start.setHours(start.getHours() + 1, 0, 0, 0);
            }

            function formatTime(d) {
                let hours = d.getHours();
                const ampm = hours >= 12 ? "PM" : "AM";
                hours = hours % 12;
                if (hours === 0) hours = 12;
                const m = d.getMinutes().toString().padStart(2, "0");
                return `${hours}:${m} ${ampm}`;
            }

            // Generate slots for Today up to 10:00 PM (hour 22)
            let current = new Date(start.getTime());
            const endToday = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 22, 0, 0);

            while (current < endToday) {
                const slotEnd = new Date(current.getTime() + 30 * 60 * 1000);
                const label = `Today, ${formatTime(current)} - ${formatTime(slotEnd)}`;
                slots.push({ value: label, text: label });
                current = new Date(current.getTime() + 30 * 60 * 1000);
            }

            // Add Tomorrow slots from 9:00 AM to 9:00 PM
            const tmrw = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 9, 0, 0);
            const endTmrw = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 21, 0, 0);
            let tmrwCurrent = new Date(tmrw.getTime());

            while (tmrwCurrent < endTmrw) {
                const slotEnd = new Date(tmrwCurrent.getTime() + 60 * 60 * 1000);
                const label = `Tomorrow, ${formatTime(tmrwCurrent)} - ${formatTime(slotEnd)}`;
                slots.push({ value: label, text: label });
                tmrwCurrent = new Date(tmrwCurrent.getTime() + 60 * 60 * 1000);
            }

            return slots;
        }

        function populateTimeSelect(selectEl, selectedVal = "ASAP") {
            if (!selectEl) return;
            const currentSelected = selectedVal || selectEl.value || "ASAP";
            const slots = generateSmartTimeSlots(currentSelected);
            selectEl.innerHTML = "";
            slots.forEach(slot => {
                const opt = document.createElement("option");
                opt.value = slot.value;
                opt.textContent = slot.text;
                if (slot.value === currentSelected) {
                    opt.selected = true;
                }
                selectEl.appendChild(opt);
            });
        }

        // Service Type Switcher (Delivery vs Pickup)
        document.querySelectorAll("#cart-service-choice .cart-choice-btn").forEach((btn) => {
            btn.addEventListener("click", () => {
                document.querySelectorAll("#cart-service-choice .cart-choice-btn").forEach((b) => b.classList.remove("active"));
                btn.classList.add("active");
                currentServiceType = btn.dataset.serviceType || "delivery";

                if (cartTimeTitleEl) {
                    cartTimeTitleEl.textContent = currentServiceType === "pickup" ? "Pickup Time" : "Delivery Time";
                }

                renderCart();
            });
        });

        // Checkout Button Click Handler
        if (cartCheckoutBtn) {
            cartCheckoutBtn.addEventListener("click", () => {
                const selectedTime = cartTimeSelect ? cartTimeSelect.value : "ASAP";
                const checkoutUrl = `store_checkout.php?store_id=${encodeURIComponent(store.id)}&order_type=${encodeURIComponent(currentServiceType)}&scheduled_time=${encodeURIComponent(selectedTime)}`;
                window.location.href = checkoutUrl;
            });
        }

        function setCartModalOpen(isOpen) {
            if (!publicCartModal) {
                return;
            }
            publicCartModal.hidden = !isOpen;
            if (isOpen) {
                populateTimeSelect(cartTimeSelect);
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
            setStatus(`Added ${amount} × ${product.name || "Product"} to cart.`);
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

        // Handle quantity +/- buttons
        document.querySelectorAll(".public-qty-btn").forEach((btn) => {
            btn.addEventListener("click", (e) => {
                e.preventDefault();
                const input = btn.closest(".public-qty-controls").querySelector("[data-product-quantity]");
                if (!input) return;
                
                const currentValue = parseInt(input.value, 10) || 1;
                const action = btn.dataset.qtyAction;
                let newValue = currentValue;
                
                if (action === "increase") {
                    newValue = Math.min(currentValue + 1, 99);
                } else if (action === "decrease") {
                    newValue = Math.max(currentValue - 1, 1);
                }
                
                input.value = String(newValue);
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

        // â”€â”€ Real-time Product Search â”€â”€
        const searchInput = document.getElementById("store-product-search");
        const productItems = document.querySelectorAll("#public-product-list .public-product-item");
        const noSearchMsg = document.getElementById("no-products-search-msg");

        if (searchInput) {
            searchInput.addEventListener("input", (e) => {
                const q = e.target.value.trim().toLowerCase();
                let matchedCount = 0;
                productItems.forEach((item) => {
                    const name = item.dataset.productName || "";
                    if (!q || name.includes(q)) {
                        item.style.display = "";
                        matchedCount++;
                    } else {
                        item.style.display = "none";
                    }
                });
                if (noSearchMsg) {
                    noSearchMsg.style.display = matchedCount === 0 && q ? "block" : "none";
                }
            });
        }

        // â”€â”€ Real-Time Pickup Status Notification Poller â”€â”€
        let notifiedOrderIds = new Set();
        async function checkPickupOrdersStatus() {
            try {
                const res = await fetch("user_orders.php");
                if (!res.ok) return;
                const data = await res.json();
                if (Array.isArray(data.orders)) {
                    data.orders.forEach(order => {
                        // If order is at this store, is a pickup order, and is ready
                        if (String(order.store_id || "") === String(store.id) || order.store_name === store.name) {
                            if (order.status === "for_pickup" || order.status === "ready" || order.status === "completed") {
                                if (!notifiedOrderIds.has(order.id)) {
                                    notifiedOrderIds.add(order.id);
                                    showPickupToast(order.id, store.name);
                                }
                            }
                        }
                    });
                }
            } catch (err) {
                // Ignore poll error
            }
        }

        function showPickupToast(orderId, storeName) {
            const container = document.getElementById("pickup-toast-container");
            if (!container) return;
            const toast = document.createElement("div");
            toast.className = "pickup-live-toast";
            toast.innerHTML = `
                <div style="width:40px; height:40px; border-radius:10px; background:#10B981; color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </div>
                <div style="flex:1;">
                    <h5 style="margin:0; font-size:14px; color:#065F46; font-weight:800;">Order #${orderId} Ready!</h5>
                    <p style="margin:2px 0 0; font-size:12.5px; color:#047857;">Available for pickup at ${escapeHtml(storeName)}</p>
                </div>
                <a href="order_history.php" style="padding:6px 12px; border-radius:8px; background:#059669; color:#fff; font-size:12px; font-weight:700; text-decoration:none;">View</a>
                <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:#94A3B8; font-size:18px; cursor:pointer;">&times;</button>
            `;
            container.appendChild(toast);
            setTimeout(() => { if (toast.parentElement) toast.remove(); }, 12000);
        }

        // Start periodic polling every 12 seconds
        setInterval(checkPickupOrdersStatus, 12000);

        renderCart();

        // â”€â”€ Map Initialization â”€â”€
        if (Number.isFinite(Number(store.lat)) && Number.isFinite(Number(store.lng))) {
            const map = L.map("public-store-map", { zoomControl: false }).setView([Number(store.lat), Number(store.lng)], 16);
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
            L.marker([Number(store.lat), Number(store.lng)], { icon: storeIcon }).addTo(map).bindPopup(`<strong>${escapeHtml(store.name || "Store")}</strong><br>${escapeHtml(store.address || "")}`).openPopup();
        } else {
            document.getElementById("public-store-map").innerHTML = "<p style='padding:18px; color:#64748B;'>Store location coordinates not available.</p>";
        }
    </script>
</body>
</html>
