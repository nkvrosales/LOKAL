<?php
require_once "auth.php";
require_once "db.php";
require_login();

$userId  = (int) ($_SESSION["user_id"] ?? 0);
$isStore = ($_SESSION["account_type"] ?? "") === "store";

// â”€â”€ Handle order status update (AJAX POST) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"], $_POST["order_id"])) {
    header("Content-Type: application/json");
    $orderId = (int) $_POST["order_id"];
    $action  = trim($_POST["action"]);

    $allowed = $isStore ? ["reject", "cancel", "ready_for_pickup", "picked_up"] : [];

    if (!in_array($action, $allowed, true)) {
        echo json_encode(["ok" => false, "message" => "Not allowed."]);
        exit;
    }

    $check = $mysqli->prepare("SELECT id, status FROM orders WHERE id = ? AND store_user_id = ?");
    $check->bind_param("ii", $orderId, $userId);
    $check->execute();
    $check->bind_result($checkId, $currentStatus);
    $found = $check->fetch();
    $check->close();

    if (!$found) {
        echo json_encode(["ok" => false, "message" => "Order not found."]);
        exit;
    }

    $now = date("Y-m-d H:i:s");
    $newStatus = null;

    if ($action === "reject" && $currentStatus === "pending") {
        $newStatus = "declined";
        $upd = $mysqli->prepare("UPDATE orders SET status = 'declined' WHERE id = ?");
        $upd->bind_param("i", $orderId); $upd->execute(); $upd->close();
    } elseif ($action === "cancel" && in_array($currentStatus, ["pending","delivering","for_pickup","ready_for_pickup"], true)) {
        $newStatus = "cancelled";
        $upd = $mysqli->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
        $upd->bind_param("i", $orderId); $upd->execute(); $upd->close();
    } elseif ($action === "ready_for_pickup" && in_array($currentStatus, ["pending","delivering"], true)) {
        $newStatus = "ready_for_pickup";
        $upd = $mysqli->prepare("UPDATE orders SET status = 'ready_for_pickup', pickup_at = ? WHERE id = ?");
        $upd->bind_param("si", $now, $orderId); $upd->execute(); $upd->close();
    } elseif ($action === "picked_up" && in_array($currentStatus, ["ready_for_pickup","for_pickup"], true)) {
        $newStatus = "completed";
        $upd = $mysqli->prepare("UPDATE orders SET status = 'completed', delivered_at = ? WHERE id = ?");
        $upd->bind_param("si", $now, $orderId); $upd->execute(); $upd->close();
    } else {
        echo json_encode(["ok" => false, "message" => "Action not valid for current status."]);
        exit;
    }

    echo json_encode(["ok" => true, "new_status" => $newStatus, "message" => "Order updated."]);
    exit;
}

// â”€â”€ Fetch orders â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$cols = "id, customer_name, delivery_address, store_name, store_address, total_amount, status,
         order_type, subtotal_amount, delivery_fee, delivery_distance_km,
         scheduled_time, created_at, accepted_at, pickup_at, delivered_at";
$sql = $isStore
    ? "SELECT $cols FROM orders WHERE store_user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100"
    : "SELECT $cols FROM orders WHERE customer_user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100";

$orders = [];
$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result(
        $oid, $cName, $dAddr, $sName, $sAddr, $total, $status,
        $oType, $sub, $dFee, $dKm, $sTime, $cAt, $aAt, $pAt, $dvAt
    );
    while ($stmt->fetch()) {
        $orders[(int)$oid] = [
            "id"                   => (int)$oid,
            "customer_name"        => (string)($cName ?? "Customer"),
            "delivery_address"     => (string)($dAddr ?? ""),
            "store_name"           => (string)($sName ?? "Store"),
            "store_address"        => (string)($sAddr ?? ""),
            "total_amount"         => $total !== null ? (float)$total : 0,
            "status"               => (string)($status ?? ""),
            "order_type"           => (string)($oType ?? "delivery"),
            "subtotal_amount"      => $sub !== null ? (float)$sub : 0,
            "delivery_fee"         => $dFee !== null ? (float)$dFee : 0,
            "delivery_distance_km" => $dKm !== null ? (float)$dKm : 0,
            "scheduled_time"       => (string)($sTime ?? "ASAP"),
            "created_at"           => (string)($cAt ?? ""),
            "accepted_at"          => (string)($aAt ?? ""),
            "pickup_at"            => (string)($pAt ?? ""),
            "delivered_at"         => (string)($dvAt ?? ""),
            "items"                => [],
        ];
    }
    $stmt->close();
}

if ($orders) {
    $res = $mysqli->query(
        "SELECT order_id, product_name, unit_price, quantity, line_total
         FROM order_items WHERE order_id IN (" . implode(",", array_keys($orders)) . ") ORDER BY id ASC"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $o = (int)$row["order_id"];
            if (isset($orders[$o])) {
                $orders[$o]["items"][] = [
                    "product_name" => (string)($row["product_name"] ?? "Product"),
                    "quantity"     => isset($row["quantity"]) ? (int)$row["quantity"] : 0,
                    "line_total"   => isset($row["line_total"]) ? (float)$row["line_total"] : 0,
                ];
            }
        }
        $res->close();
    }
}

function fmt_money(float $a): string { return "PHP " . number_format($a, 2); }

function parse_pickup_window_start(string $scheduledTime): ?DateTimeImmutable {
    $value = trim($scheduledTime);
    if ($value === "" || strtoupper($value) === "ASAP") {
        return null;
    }

    $dayOffset = stripos($value, "tomorrow") !== false ? 1 : 0;
    if (preg_match('/(\d{1,2}):(\d{2})\s*(am|pm)/i', $value, $matches)) {
        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        $meridiem = strtolower($matches[3]);

        if ($meridiem === "pm" && $hour < 12) {
            $hour += 12;
        }
        if ($meridiem === "am" && $hour === 12) {
            $hour = 0;
        }

        $start = new DateTimeImmutable("now");
        if ($dayOffset) {
            $start = $start->modify("+1 day");
        }
        $start = $start->setTime($hour, $minute, 0);
        return $start;
    }

    return null;
}

function pickup_grace_label(string $scheduledTime): string {
    $value = trim($scheduledTime);
    if ($value === "" || strtoupper($value) === "ASAP") {
        return "Grace window starts now • 15-30 mins";
    }

    $windowStart = parse_pickup_window_start($value);
    if ($windowStart === null) {
        return "Pickup time: " . $value . " • Grace: 15-30 mins";
    }

    $windowEnd = $windowStart->modify("+30 minutes");
    return "Pickup at " . $windowStart->format("h:i A") . " • Grace: 15-30 mins until " . $windowEnd->format("h:i A");
}

function status_badge_info(string $s): array {
    $m = [
        "pending"          => ["Pending",           "sb-pending"],
        "delivering"       => ["Delivering",         "sb-delivering"],
        "ready_for_pickup" => ["Ready to Pickup",   "sb-ready"],
        "for_pickup"       => ["Ready to Pickup",   "sb-ready"],
        "completed"        => ["Completed",          "sb-completed"],
        "declined"         => ["Rejected",           "sb-declined"],
        "cancelled"        => ["Cancelled",          "sb-cancelled"],
    ];
    return $m[$s] ?? [ucwords(str_replace("_"," ",$s)), "sb-default"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/store-admin.css?v=orders-3">
    <style>
    /* â”€â”€ Order cards â”€â”€ */
    .oh-card { max-width: 860px; }
    .oh-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:8px; }
    .oh-header h1 { margin:0; font-size:22px; }
    .oh-count { font-size:13px; color:#94A3B8; font-weight:600; margin-top:2px; }

    .order-list { display:flex; flex-direction:column; gap:16px; margin-top:4px; }

    .order-card {
        background:#fff;
        border:1.5px solid #E2E8F0;
        border-radius:18px;
        overflow:hidden;
        box-shadow:0 4px 16px rgba(0,0,0,.06);
        transition:box-shadow .2s,transform .2s;
    }
    .order-card:hover { box-shadow:0 8px 28px rgba(0,0,0,.10); transform:translateY(-1px); }

    .order-card-head {
        display:flex; align-items:center; justify-content:space-between;
        flex-wrap:wrap; gap:10px;
        padding:14px 20px 12px;
        border-bottom:1px solid #F1F5F9;
        background:#FAFBFC;
    }
    .order-card-id { font-size:15px; font-weight:800; color:#0F172A; }
    .order-card-id small { font-size:11.5px; font-weight:600; color:#94A3B8; margin-left:6px; }
    .order-card-meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .order-card-total { font-size:16px; font-weight:800; color:#FF5B2E; }

    /* Status badges */
    .status-badge {
        display:inline-flex; align-items:center; gap:5px;
        font-size:11.5px; font-weight:700;
        padding:4px 12px; border-radius:999px; letter-spacing:.3px;
    }
    .status-badge::before {
        content:""; width:6px; height:6px; border-radius:50%;
        background:currentColor; opacity:.7;
    }
    .sb-pending    { background:#FEF3C7; color:#92400E; }
    .sb-delivering { background:#DBEAFE; color:#1E40AF; }
    .sb-ready      { background:#D1FAE5; color:#065F46; }
    .sb-completed  { background:#F0FDF4; color:#166534; }
    .sb-declined   { background:#FEE2E2; color:#991B1B; }
    .sb-cancelled  { background:#F1F5F9; color:#475569; }
    .sb-default    { background:#F1F5F9; color:#374151; }

    .type-badge {
        font-size:11px; font-weight:700; padding:3px 10px;
        border-radius:999px; background:#EFF6FF; color:#1D4ED8;
    }
    .type-badge.pickup { background:#FFF7ED; color:#C2410C; }

    /* Card body */
    .order-card-body { padding:16px 20px; display:flex; flex-direction:column; gap:14px; }
    .order-info-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px 20px; }
    .info-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#94A3B8; margin-bottom:2px; }
    .info-value { font-size:13.5px; font-weight:700; color:#0F172A; }

    /* Items list */
    .order-items-list { background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; overflow:hidden; }
    .oi-head { display:flex; justify-content:space-between; padding:8px 14px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#94A3B8; border-bottom:1px solid #E2E8F0; }
    .oi-row { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-bottom:1px solid #F1F5F9; font-size:13px; }
    .oi-row:last-child { border-bottom:none; }
    .oi-name { font-weight:600; color:#0F172A; }
    .oi-qty { color:#94A3B8; font-size:12px; }
    .oi-price { font-weight:700; color:#0F172A; }

    /* Totals */
    .order-totals { border-top:1px dashed #E2E8F0; padding-top:12px; display:flex; flex-direction:column; gap:6px; font-size:13px; }
    .ot-row { display:flex; justify-content:space-between; color:#64748B; }
    .ot-row.grand { font-weight:800; font-size:15px; color:#0F172A; border-top:1px solid #E2E8F0; padding-top:8px; margin-top:2px; }
    .ot-row.grand strong { color:#FF5B2E; }

    /* Timestamps */
    .order-times { display:flex; flex-wrap:wrap; gap:8px 18px; font-size:11.5px; color:#94A3B8; padding-top:2px; }
    .order-times b { color:#475569; }
    .pickup-grace { color:#C2410C; font-weight:700; }
    .pickup-alert-banner {
        display:flex; flex-direction:column; gap:10px;
        margin:0 0 18px; padding:16px 18px; border:1px solid rgba(249,115,22,.25);
        background:linear-gradient(135deg, rgba(255,247,237,.95), rgba(254,215,170,.7));
        border-radius:16px; box-shadow:0 10px 30px rgba(249,115,22,.08);
    }
    .pickup-alert-item {
        display:flex; flex-direction:column; gap:2px; color:#7C2D12;
        font-size:13px;
    }
    .pickup-alert-item strong { font-size:14px; }
    .pickup-alert-item small { font-size:11px; opacity:.9; }

    /* Actions bar */
    .order-actions {
        display:flex; flex-wrap:wrap; gap:8px;
        padding:12px 20px;
        border-top:1px solid #F1F5F9;
        background:#FAFBFC;
    }
    .oab {
        display:inline-flex; align-items:center; gap:6px;
        padding:8px 16px; border-radius:10px;
        font:inherit; font-size:12.5px; font-weight:700;
        cursor:pointer; border:1.5px solid;
        transition:all .18s; white-space:nowrap;
    }
    .oab:hover { transform:translateY(-1px); filter:brightness(.92); }
    .oab:disabled { opacity:.5; cursor:not-allowed; transform:none; }
    .oab-reject  { background:rgba(239,68,68,.07);   border-color:rgba(239,68,68,.3);   color:#DC2626; }
    .oab-ready   { background:rgba(99,102,241,.08);  border-color:rgba(99,102,241,.35); color:#4338CA; }
    .oab-picked  { background:rgba(16,185,129,.1);   border-color:rgba(16,185,129,.4);  color:#047857; }
    .oab-cancel  { background:rgba(234,179,8,.08);   border-color:rgba(234,179,8,.35);  color:#854D0E; }

    /* Filter bar */
    .filter-bar { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
    .filter-pill {
        padding:6px 16px; border-radius:999px;
        font:inherit; font-size:12.5px; font-weight:600;
        cursor:pointer; border:1.5px solid #E2E8F0;
        background:#F8FAFC; color:#475569; transition:all .18s;
    }
    .filter-pill:hover { border-color:#CBD5E1; background:#F1F5F9; }
    .filter-pill.active {
        background:linear-gradient(135deg,#FF5B2E,#E04A1F);
        border-color:transparent; color:#fff;
        box-shadow:0 4px 12px rgba(255,91,46,.3);
    }

    .empty-state { text-align:center; padding:48px 0; color:#94A3B8; font-size:15px; }

    /* Confirm overlay */
    .confirm-overlay {
        position:fixed; inset:0;
        background:rgba(0,0,0,.45); backdrop-filter:blur(4px);
        z-index:9999; display:flex; align-items:center; justify-content:center;
        padding:16px; opacity:0; pointer-events:none; transition:opacity .22s;
    }
    .confirm-overlay.open { opacity:1; pointer-events:all; }
    .confirm-box {
        background:#fff; border-radius:20px;
        box-shadow:0 32px 80px rgba(0,0,0,.28);
        width:min(420px,100%); padding:28px;
        display:flex; flex-direction:column; gap:16px;
        transform:translateY(16px) scale(.97);
        transition:transform .25s cubic-bezier(.16,1,.3,1);
    }
    .confirm-overlay.open .confirm-box { transform:none; }
    .confirm-box h3 { margin:0; font-size:18px; color:#0F172A; }
    .confirm-box p { margin:0; font-size:14px; color:#475569; line-height:1.6; }
    .confirm-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:4px; }
    .btn-confirm-cancel {
        padding:9px 20px; border-radius:12px; border:1.5px solid #E2E8F0;
        background:#F8FAFC; color:#475569; font:inherit; font-size:13.5px;
        font-weight:600; cursor:pointer; transition:all .15s;
    }
    .btn-confirm-cancel:hover { background:#F1F5F9; }
    .btn-confirm-ok {
        padding:9px 22px; border-radius:12px; border:none;
        background:linear-gradient(135deg,#FF5B2E,#E04A1F); color:#fff;
        font:inherit; font-size:13.5px; font-weight:700; cursor:pointer;
        box-shadow:0 4px 12px rgba(255,91,46,.32); transition:all .2s;
    }
    .btn-confirm-ok:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(255,91,46,.42); }
    .btn-confirm-ok.danger { background:linear-gradient(135deg,#EF4444,#DC2626); box-shadow:0 4px 12px rgba(239,68,68,.3); }
    </style>
</head>
<body class="store-admin-body">
    <header class="top-bar">
        <a class="logo" href="home.php" style="text-decoration:none">
            <span style="color:var(--primary,#FF4D2E)">LOKAL</span>
        </a>
        <nav class="store-admin-nav" aria-label="Orders navigation">
            <a class="store-admin-tab" href="home.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Home</span>
            </a>
            <a class="store-admin-tab" href="account_profile.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Profile</span>
            </a>
            <a class="store-admin-tab active" href="order_history.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span>Orders</span>
            </a>
            <?php if ($isStore): ?>
                <a class="store-admin-tab" href="store_products.php">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    <span>Products</span>
                </a>
            <?php else: ?>
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

    <main class="store-admin-shell">
        <section class="store-admin-card oh-card">
            <div class="oh-header">
                <div>
                    <h1><?php echo $isStore ? "Store Orders" : "My Orders"; ?></h1>
                    <p class="oh-count"><?php echo count($orders); ?> order<?php echo count($orders) !== 1 ? "s" : ""; ?> found</p>
                </div>
            </div>

            <?php if ($isStore): ?>
                <?php
                $pickupAlerts = [];
                foreach ($orders as $order) {
                    if (($order["order_type"] ?? "delivery") === "pickup" && in_array($order["status"], ["pending", "ready_for_pickup", "for_pickup"], true)) {
                        $pickupAlerts[] = $order;
                    }
                }
                ?>
                <?php if ($pickupAlerts): ?>
                    <div class="pickup-alert-banner">
                        <?php foreach ($pickupAlerts as $alert): ?>
                            <?php $pickupTime = $alert["scheduled_time"] !== "" ? $alert["scheduled_time"] : "ASAP"; ?>
                            <div class="pickup-alert-item">
                                <strong>Pickup order #<?php echo (int) $alert["id"]; ?></strong>
                                <span>Customer pickup time: <?php echo escape($pickupTime); ?></span>
                                <small>Grace period: 15-30 mins</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$orders): ?>
                <div class="empty-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#CBD5E1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 12px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    No orders yet.
                </div>
            <?php else: ?>
                <div class="filter-bar">
                    <button class="filter-pill active" data-filter="all" type="button">All</button>
                    <button class="filter-pill" data-filter="pending" type="button">Pending</button>
                    <?php if ($isStore): ?>
                        <button class="filter-pill" data-filter="ready_for_pickup" type="button">Ready for Pickup</button>
                    <?php endif; ?>
                    <button class="filter-pill" data-filter="delivering" type="button">Delivering</button>
                    <button class="filter-pill" data-filter="completed" type="button">Completed</button>
                    <button class="filter-pill" data-filter="declined" type="button">Rejected</button>
                    <button class="filter-pill" data-filter="cancelled" type="button">Cancelled</button>
                </div>

                <div class="order-list" id="order-list">
                <?php foreach ($orders as $order):
                    [$statusText, $statusClass] = status_badge_info($order["status"]);
                    $isPickup = $order["order_type"] === "pickup";
                    $s = $order["status"];
                    // Store action flags
                    $canReject  = $isStore && $s === "pending";
                    $canReady   = $isStore && in_array($s, ["pending","delivering"], true) && $isPickup;
                    $canPickedUp= $isStore && in_array($s, ["ready_for_pickup","for_pickup"], true);
                    $canCancel  = $isStore && in_array($s, ["pending","delivering","for_pickup","ready_for_pickup"], true);
                    $hasActions = $canReject || $canReady || $canPickedUp || $canCancel;
                    $sTime = $order["scheduled_time"] !== "" ? $order["scheduled_time"] : "ASAP";
                ?>
                <article class="order-card" data-status="<?php echo escape($s); ?>" data-order-id="<?php echo (int)$order["id"]; ?>">
                    <div class="order-card-head">
                        <div class="order-card-id">
                            Order #<?php echo (int)$order["id"]; ?>
                            <?php if ($sTime !== "ASAP"): ?>
                                <small><?php echo escape($sTime); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="order-card-meta">
                            <span class="status-badge <?php echo escape($statusClass); ?>"><?php echo escape($statusText); ?></span>
                            <span class="type-badge <?php echo $isPickup ? "pickup" : ""; ?>"><?php echo $isPickup ? "Pickup" : "Delivery"; ?></span>
                            <span class="order-card-total"><?php echo escape(fmt_money($order["total_amount"])); ?></span>
                        </div>
                    </div>

                    <div class="order-card-body">
                        <div class="order-info-row">
                            <?php if ($isStore): ?>
                                <div><div class="info-label">Customer</div><div class="info-value"><?php echo escape($order["customer_name"]); ?></div></div>
                                <div>
                                    <div class="info-label"><?php echo $isPickup ? "Pickup at" : "Deliver to"; ?></div>
                                    <div class="info-value"><?php echo escape($isPickup ? ($order["store_address"] ?: "Store") : ($order["delivery_address"] ?: "Address not available")); ?></div>
                                </div>
                                <?php if ($isPickup): ?>
                                    <div>
                                        <div class="info-label">Pickup time</div>
                                        <div class="info-value"><?php echo escape($sTime); ?> <span class="pickup-grace"><?php echo escape(pickup_grace_label($sTime)); ?></span></div>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div><div class="info-label">Store</div><div class="info-value"><?php echo escape($order["store_name"]); ?></div></div>
                                <div>
                                    <div class="info-label"><?php echo $isPickup ? "Pickup at" : "Delivery address"; ?></div>
                                    <div class="info-value"><?php echo escape($isPickup ? ($order["store_address"] ?: "Store address") : ($order["delivery_address"] ?: "Not available")); ?></div>
                                </div>
                                <?php if ($isPickup): ?>
                                    <div>
                                        <div class="info-label">Pickup time</div>
                                        <div class="info-value"><?php echo escape($sTime); ?> <span class="pickup-grace"><?php echo escape(pickup_grace_label($sTime)); ?></span></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($order["items"]): ?>
                        <div class="order-items-list">
                            <div class="oi-head"><span>Item</span><span>Total</span></div>
                            <?php foreach ($order["items"] as $item): ?>
                            <div class="oi-row">
                                <div>
                                    <div class="oi-name"><?php echo escape($item["product_name"]); ?></div>
                                    <div class="oi-qty">&times; <?php echo (int)$item["quantity"]; ?></div>
                                </div>
                                <div class="oi-price"><?php echo escape(fmt_money($item["line_total"])); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="order-totals">
                            <div class="ot-row"><span>Subtotal</span><span><?php echo escape(fmt_money($order["subtotal_amount"])); ?></span></div>
                            <div class="ot-row">
                                <span><?php echo $isPickup ? "Pickup Fee" : "Delivery Fee" . ($order["delivery_distance_km"] > 0 ? " (" . number_format($order["delivery_distance_km"], 2) . " km)" : ""); ?></span>
                                <span><?php echo $isPickup ? "PHP 0.00" : escape(fmt_money($order["delivery_fee"])); ?></span>
                            </div>
                            <div class="ot-row grand"><span>Total</span><strong><?php echo escape(fmt_money($order["total_amount"])); ?></strong></div>
                        </div>

                        <div class="order-times">
                            <span>Ordered: <b><?php echo escape($order["created_at"] ?: "--"); ?></b></span>
                            <?php if ($order["accepted_at"]): ?><span>Accepted: <b><?php echo escape($order["accepted_at"]); ?></b></span><?php endif; ?>
                            <?php if ($order["pickup_at"]): ?><span>Ready at: <b><?php echo escape($order["pickup_at"]); ?></b></span><?php endif; ?>
                            <?php if ($order["delivered_at"]): ?><span>Completed: <b><?php echo escape($order["delivered_at"]); ?></b></span><?php endif; ?>
                        </div>
                    </div>

                    <?php if ($hasActions): ?>
                    <div class="order-actions">
                        <?php if ($canReject): ?>
                            <button type="button" class="oab oab-reject"
                                data-action="reject"
                                data-confirm="Reject Order #<?php echo (int)$order['id']; ?>? The customer will be notified."
                                data-danger="true">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                Reject Order
                            </button>
                        <?php endif; ?>
                        <?php if ($canReady): ?>
                            <button type="button" class="oab oab-ready"
                                data-action="ready_for_pickup"
                                data-confirm="Mark Order #<?php echo (int)$order['id']; ?> as ready to pickup?">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                Ready to Pickup
                            </button>
                        <?php endif; ?>
                        <?php if ($canPickedUp): ?>
                            <button type="button" class="oab oab-picked"
                                data-action="picked_up"
                                data-confirm="Confirm Order #<?php echo (int)$order['id']; ?> has been picked up by the customer?">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                                Picked Up
                            </button>
                        <?php endif; ?>
                        <?php if ($canCancel): ?>
                            <button type="button" class="oab oab-cancel"
                                data-action="cancel"
                                data-confirm="Cancel Order #<?php echo (int)$order['id']; ?>? This cannot be undone."
                                data-danger="true">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                Cancel Order
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Confirm modal -->
    <div class="confirm-overlay" id="confirm-overlay" role="dialog" aria-modal="true">
        <div class="confirm-box">
            <h3>Confirm Action</h3>
            <p id="confirm-msg">Are you sure?</p>
            <div class="confirm-actions">
                <button type="button" class="btn-confirm-cancel" id="confirm-no">Cancel</button>
                <button type="button" class="btn-confirm-ok" id="confirm-yes">Confirm</button>
            </div>
        </div>
    </div>

    <script>
    // Filter pills
    document.querySelectorAll(".filter-pill").forEach(pill => {
        pill.addEventListener("click", () => {
            document.querySelectorAll(".filter-pill").forEach(p => p.classList.remove("active"));
            pill.classList.add("active");
            const f = pill.dataset.filter;
            document.querySelectorAll(".order-card").forEach(card => {
                const st = card.dataset.status;
                const show = f === "all" || st === f
                    || (f === "ready_for_pickup" && (st === "ready_for_pickup" || st === "for_pickup"));
                card.style.display = show ? "" : "none";
            });
        });
    });

    // Confirm modal
    const overlay = document.getElementById("confirm-overlay");
    const msgEl   = document.getElementById("confirm-msg");
    const yesBtn  = document.getElementById("confirm-yes");
    const noBtn   = document.getElementById("confirm-no");
    let pending = null;

    function openConfirm(msg, isDanger, cb) {
        msgEl.textContent = msg;
        yesBtn.classList.toggle("danger", !!isDanger);
        pending = cb;
        overlay.classList.add("open");
        noBtn.focus();
    }
    function closeConfirm() { overlay.classList.remove("open"); pending = null; }
    noBtn.addEventListener("click", closeConfirm);
    overlay.addEventListener("click", e => { if (e.target === overlay) closeConfirm(); });
    document.addEventListener("keydown", e => { if (e.key === "Escape") closeConfirm(); });
    yesBtn.addEventListener("click", () => { if (pending) { pending(); closeConfirm(); } });

    // Action buttons
    document.querySelectorAll(".oab").forEach(btn => {
        btn.addEventListener("click", () => {
            const card    = btn.closest(".order-card");
            const oid     = card?.dataset.orderId;
            const action  = btn.dataset.action;
            const msg     = btn.dataset.confirm || "Are you sure?";
            const danger  = btn.dataset.danger === "true";
            openConfirm(msg, danger, () => doAction(oid, action, btn, card));
        });
    });

    async function doAction(orderId, action, btn, card) {
        btn.disabled = true;
        const orig = btn.innerHTML;
        btn.textContent = "Updating...";

        try {
            const body = new URLSearchParams({ action, order_id: orderId });
            const res  = await fetch("order_history.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString()
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.message || "Failed.");

            const ns = data.new_status;
            const labelMap = {
                declined:         ["Rejected",          "status-badge sb-declined"],
                cancelled:        ["Cancelled",         "status-badge sb-cancelled"],
                ready_for_pickup: ["Ready for Pickup",  "status-badge sb-ready"],
                completed:        ["Completed",         "status-badge sb-completed"],
            };
            const [lbl, cls] = labelMap[ns] || [ns, "status-badge"];
            const badge = card.querySelector(".status-badge");
            if (badge) { badge.textContent = lbl; badge.className = cls; }
            card.dataset.status = ns;

            const bar = card.querySelector(".order-actions");
            if (bar) bar.remove();

            if (ns === "ready_for_pickup") {
                const newBar = document.createElement("div");
                newBar.className = "order-actions";
                newBar.innerHTML = `<button type="button" class="oab oab-picked"
                    data-action="picked_up"
                    data-confirm="Confirm Order #${orderId} has been picked up by the customer?">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    Picked Up</button>`;
                card.appendChild(newBar);
                newBar.querySelector(".oab").addEventListener("click", function() {
                    openConfirm(this.dataset.confirm, false, () => doAction(orderId, "picked_up", this, card));
                });
            }
        } catch (err) {
            alert(err.message || "Something went wrong.");
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }
    </script>
</body>
</html>
