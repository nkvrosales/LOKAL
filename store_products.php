<?php
require_once "auth.php";
require_once "db.php";
require_login();

if (($_SESSION["account_type"] ?? "") !== "store") {
    header("Location: home.php");
    exit;
}

$user_id    = (int) ($_SESSION["user_id"] ?? 0);
$user_name  = $_SESSION["user_name"] ?? "Store";
$store_name = "";
$errors     = [];
$notice     = "";

$format_price_label = static function ($price): string {
    if ($price === null || $price === "") return "";
    return "PHP " . number_format((float) $price, 2);
};

// Load store name
$store_stmt = $mysqli->prepare("SELECT store_name FROM users WHERE id = ? LIMIT 1");
if ($store_stmt) {
    $store_stmt->bind_param("i", $user_id);
    $store_stmt->execute();
    $store_stmt->bind_result($row_store_name);
    if ($store_stmt->fetch()) $store_name = trim((string)($row_store_name ?? ""));
    $store_stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // ADD
    if (isset($_POST["product_add_submit"])) {
        $name  = trim($_POST["product_name"]  ?? "");
        $price = trim($_POST["product_price"] ?? "");
        $desc  = trim($_POST["product_description"] ?? "");
        if ($name  === "") $errors[] = "Product name is required.";
        if ($price === "") $errors[] = "Price is required.";
        elseif (!is_numeric($price))      $errors[] = "Price must be a number.";
        elseif ((float)$price < 0)        $errors[] = "Price cannot be negative.";
        $img_file = null;
        if (!$errors && isset($_FILES["product_image"]) && is_uploaded_file($_FILES["product_image"]["tmp_name"])) {
            $ext = strtolower(pathinfo($_FILES["product_image"]["name"], PATHINFO_EXTENSION));
            if (in_array($ext, ["jpg","jpeg","png","webp"], true)) {
                $dir = __DIR__ . "/uploads/products";
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $img_file = bin2hex(random_bytes(8)) . "_" . time() . "." . $ext;
                move_uploaded_file($_FILES["product_image"]["tmp_name"], "$dir/$img_file");
            } else { $errors[] = "Image must be JPG, PNG, or WEBP."; }
        }
        if (!$errors) {
            $price_val = (float)$price;
            $desc_val  = $desc !== "" ? $desc : null;
            $ins = $mysqli->prepare("INSERT INTO store_products (store_user_id, product_name, product_description, product_price, product_image, is_active) VALUES (?,?,?,?,?,1)");
            if ($ins) {
                $ins->bind_param("issds", $user_id, $name, $desc_val, $price_val, $img_file);
                $notice = $ins->execute() ? "Product added." : "Unable to add product.";
                if (!$ins->execute()) $errors[] = "Unable to add product.";
                $ins->close();
            }
        }
    }

    // EDIT
    if (isset($_POST["product_edit_submit"])) {
        $pid   = (int)($_POST["product_id"] ?? 0);
        $name  = trim($_POST["product_name"]  ?? "");
        $price = trim($_POST["product_price"] ?? "");
        $desc  = trim($_POST["product_description"] ?? "");
        if ($pid <= 0)     $errors[] = "Invalid product.";
        if ($name  === "") $errors[] = "Product name is required.";
        if ($price === "") $errors[] = "Price is required.";
        elseif (!is_numeric($price)) $errors[] = "Price must be a number.";
        elseif ((float)$price < 0)   $errors[] = "Price cannot be negative.";
        $new_img = null; $has_img = false;
        if (!$errors && isset($_FILES["product_image"]) && is_uploaded_file($_FILES["product_image"]["tmp_name"])) {
            $ext = strtolower(pathinfo($_FILES["product_image"]["name"], PATHINFO_EXTENSION));
            if (in_array($ext, ["jpg","jpeg","png","webp"], true)) {
                $dir = __DIR__ . "/uploads/products";
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $new_img = bin2hex(random_bytes(8)) . "_" . time() . "." . $ext;
                move_uploaded_file($_FILES["product_image"]["tmp_name"], "$dir/$new_img");
                $has_img = true;
            } else { $errors[] = "Image must be JPG, PNG, or WEBP."; }
        }
        if (!$errors) {
            $pv = (float)$price; $dv = $desc !== "" ? $desc : null;
            if ($has_img) {
                $upd = $mysqli->prepare("UPDATE store_products SET product_name=?,product_description=?,product_price=?,product_image=? WHERE id=? AND store_user_id=? LIMIT 1");
                if ($upd) { $upd->bind_param("ssdsis", $name, $dv, $pv, $new_img, $pid, $user_id); $upd->execute(); $notice = "Product updated."; $upd->close(); }
            } else {
                $upd = $mysqli->prepare("UPDATE store_products SET product_name=?,product_description=?,product_price=? WHERE id=? AND store_user_id=? LIMIT 1");
                if ($upd) { $upd->bind_param("ssdii", $name, $dv, $pv, $pid, $user_id); $upd->execute(); $notice = "Product updated."; $upd->close(); }
            }
        }
    }

    // TOGGLE
    if (isset($_POST["product_toggle_submit"])) {
        $pid = (int)($_POST["product_id"] ?? 0); $ns = (int)($_POST["new_state"] ?? 0);
        if ($pid > 0) {
            $tog = $mysqli->prepare("UPDATE store_products SET is_active=? WHERE id=? AND store_user_id=? LIMIT 1");
            if ($tog) { $tog->bind_param("iii", $ns, $pid, $user_id); $tog->execute(); $notice = $ns ? "Product activated." : "Product deactivated."; $tog->close(); }
        }
    }

    // DELETE
    if (isset($_POST["product_delete_submit"])) {
        $pid = (int)($_POST["product_id"] ?? 0);
        if ($pid > 0) {
            $del = $mysqli->prepare("DELETE FROM store_products WHERE id=? AND store_user_id=? LIMIT 1");
            if ($del) { $del->bind_param("ii", $pid, $user_id); $del->execute(); $notice = $del->affected_rows > 0 ? "Product removed." : "Unable to remove."; $del->close(); }
        }
    }
}

// Load products
$products  = [];
$list_stmt = $mysqli->prepare("SELECT id,product_name,product_description,product_price,product_image,is_active FROM store_products WHERE store_user_id=? ORDER BY id DESC");
if ($list_stmt) {
    $list_stmt->bind_param("i", $user_id);
    $list_stmt->execute();
    $list_stmt->bind_result($pid,$pname,$pdesc,$pprice,$pimg,$pactive);
    while ($list_stmt->fetch()) {
        $products[] = [
            "id"          => (int)$pid,
            "name"        => trim((string)($pname ?? "")),
            "description" => trim((string)($pdesc ?? "")),
            "price_raw"   => $pprice !== null ? (float)$pprice : null,
            "price_label" => $format_price_label($pprice),
            "image"       => $pimg ? trim((string)$pimg) : null,
            "is_active"   => (int)$pactive,
        ];
    }
    $list_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Store Products | Lokal</title>
<link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
<link rel="stylesheet" href="assets/store-admin.css?v=products-modal-1">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
<style>
/* ── Overrides for DataTables to match design ── */
.products-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.products-header h1{margin:0}
.products-header p{margin:4px 0 0;font-size:13.5px;color:#475569}
.add-product-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:14px;border:none;background:linear-gradient(135deg,#FF5B2E 0%,#e04a1f 100%);color:#fff;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;box-shadow:none;transition:filter .15s ease;white-space:nowrap}
.add-product-btn:hover{transform:none;filter:brightness(.92);box-shadow:none}

/* DataTables wrapper */
#products-table-wrap{overflow-x:auto;margin-top:4px;min-height:220px;padding-bottom:70px}
table.products-dt{width:100%;border-collapse:separate;border-spacing:0;font-size:13.5px;color:#0F172A}
table.products-dt thead th{background:#F8FAFC;color:#374151;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.5px;padding:11px 14px;border-bottom:2px solid rgba(255,91,46,.15);white-space:nowrap;cursor:pointer;user-select:none}
table.products-dt tbody tr{transition:background .15s}
table.products-dt tbody tr:hover{background:#FFF5F2}
table.products-dt tbody td{padding:11px 14px;border-bottom:1px solid #F1F5F9;vertical-align:middle}
.dt-product-img{width:52px;height:52px;border-radius:10px;object-fit:cover;display:block;background:#F1F5F9;border:1.5px solid rgba(255,91,46,.12)}
.dt-product-img-ph{width:52px;height:52px;border-radius:10px;background:#F1F5F9;display:flex;align-items:center;justify-content:center;color:#CBD5E1;border:1.5px solid rgba(255,91,46,.12)}
.dt-product-img-ph svg{width:24px;height:24px}
.dt-name{font-weight:700;color:#0F172A;font-size:14px}
.dt-desc{font-size:12px;color:#64748B;margin-top:2px;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dt-price{font-weight:800;color:#FF5B2E;white-space:nowrap}
.pstatus{font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;display:inline-block}
.pstatus.active{background:#D1FAE5;color:#065F46}
.pstatus.inactive{background:#FEE2E2;color:#991B1B}
.pcard-actions{display:flex;gap:6px;align-items:center}
.pa-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 11px;border-radius:10px;font:inherit;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid;transition:filter .15s;white-space:nowrap}
.pa-btn:hover{filter:brightness(.88)}
.pa-btn.edit{background:rgba(59,130,246,.08);border-color:rgba(59,130,246,.3);color:#2563EB}
.pa-btn.ton{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.35);color:#047857}
.pa-btn.toff{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.3);color:#DC2626}
.pa-btn.del{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.3);color:#DC2626}

/* DataTables controls styling */
.dataTables_wrapper .dataTables_filter input{border:1.5px solid #E2E8F0;border-radius:10px;padding:6px 12px;font:inherit;font-size:13px;color:#0F172A;outline:none;transition:border-color .18s}
.dataTables_wrapper .dataTables_filter input:focus{border-color:#FF5B2E;box-shadow:none}
.dataTables_wrapper .dataTables_length select{border:1.5px solid #E2E8F0;border-radius:10px;padding:5px 10px;font:inherit;font-size:13px;color:#0F172A;outline:none}
.dataTables_wrapper .dataTables_info{font-size:12.5px;color:#64748B}
.dataTables_wrapper .dataTables_paginate .paginate_button{padding:5px 10px;border-radius:8px;border:none;font:inherit;font-size:13px;cursor:pointer;color:#374151 !important}
.dataTables_wrapper .dataTables_paginate .paginate_button.current{background:linear-gradient(135deg,#FF5B2E,#e04a1f) !important;color:#fff !important;border-radius:8px}
.dataTables_wrapper .dataTables_paginate .paginate_button:hover:not(.current){background:#F1F5F9 !important}
.dataTables_wrapper{display:flex;flex-direction:column;gap:12px}
.dt-top-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}

/* Modal */
.pm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;pointer-events:none;transition:opacity .15s}
.pm-overlay.open{opacity:1;pointer-events:all}
.pm-box{background:#fff;border-radius:22px;border:1px solid #CBD5E1;box-shadow:none;width:min(500px,100%);max-height:92vh;overflow-y:auto;padding:28px;display:flex;flex-direction:column;gap:16px}
.pm-overlay.open .pm-box{transform:none}
.pm-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.pm-head h2{margin:0;font-size:20px;color:#0F172A}
.pm-close{width:36px;height:36px;border-radius:10px;border:1.5px solid #E2E8F0;background:#F8FAFC;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:18px;color:#64748B;transition:background .15s,color .15s}
.pm-close:hover{background:#FEE2E2;border-color:#FCA5A5;color:#DC2626}
.pm-img-wrap{width:100%;height:180px;border-radius:14px;border:2px dashed #E2E8F0;background:#F8FAFC;display:flex;align-items:center;justify-content:center;cursor:pointer;position:relative;transition:border-color .15s;overflow:hidden}
.pm-img-wrap:hover{border-color:#FF5B2E}
.pm-img-preview{width:100%;height:100%;object-fit:cover;display:none}
.pm-img-ph{display:flex;flex-direction:column;align-items:center;gap:8px;color:#94A3B8;font-size:13px;pointer-events:none}
.pm-img-ph svg{width:36px;height:36px}
.pm-img-input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.pm-fields{display:flex;flex-direction:column;gap:14px}
.pm-field{display:flex;flex-direction:column;gap:6px}
.pm-field label{font-size:12.5px;font-weight:700;color:#374151;letter-spacing:.4px;text-transform:uppercase}
.pm-field input,.pm-field textarea{width:100%;padding:11px 14px;border-radius:12px;border:1.5px solid #E2E8F0;background:#FAFAFA;font:inherit;font-size:14px;color:#0F172A;transition:border-color .15s;box-sizing:border-box}
.pm-field input:focus,.pm-field textarea:focus{outline:none;border-color:#FF5B2E;box-shadow:none;background:#fff}
.pm-field textarea{min-height:80px;resize:vertical}
.pm-actions{display:flex;gap:10px;justify-content:flex-end;padding-top:4px}
.pm-cancel{padding:10px 20px;border-radius:12px;border:1.5px solid #E2E8F0;background:#F8FAFC;color:#475569;font:inherit;font-size:13.5px;font-weight:600;cursor:pointer;transition:background .15s}
.pm-cancel:hover{background:#F1F5F9}
.pm-save{padding:10px 22px;border-radius:12px;border:none;background:linear-gradient(135deg,#FF5B2E,#e04a1f);color:#fff;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;box-shadow:none;transition:filter .15s;display:inline-flex;align-items:center;gap:7px}
.pm-save:hover{transform:none;filter:brightness(.92);box-shadow:none}
@media(max-width:768px){
  .top-bar{flex-direction:column;align-items:stretch;padding:10px 14px;gap:8px}
  .store-admin-nav{border-radius:12px;overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch;scrollbar-width:none;width:100%;box-sizing:border-box}
  .store-admin-nav::-webkit-scrollbar{display:none}
  .store-admin-tab{white-space:nowrap;flex-shrink:0;padding:6px 12px;font-size:12px}
}

/* Dropdown action button */
.act-dropdown{position:relative;display:inline-block}
.act-trigger{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:10px;border:1.5px solid #E2E8F0;background:#F8FAFC;color:#374151;font:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s,border-color .15s;white-space:nowrap}
.act-trigger:hover{border-color:#CBD5E1;background:#F1F5F9}
.act-dropdown.open .act-trigger{border-color:#FF5B2E;background:#FFF5F2;color:#FF5B2E}
.act-menu{display:none;position:absolute;right:0;top:calc(100% + 6px);background:#fff;border:1.5px solid #E2E8F0;border-radius:14px;box-shadow:none;min-width:180px;z-index:500;padding:6px;overflow:hidden}
.act-dropdown.open .act-menu{display:block}
.act-item{display:flex;align-items:center;gap:9px;width:100%;padding:9px 12px;border-radius:9px;border:none;background:transparent;font:inherit;font-size:13px;color:#374151;cursor:pointer;text-align:left;transition:background .12s,color .12s}
.act-item:hover{background:#F8FAFC;color:#0F172A}
.act-item.act-deactivate:hover{background:#FEF2F2;color:#DC2626}
.act-item.act-activate:hover{background:#ECFDF5;color:#047857}
.act-item.act-remove{color:#DC2626}
.act-item.act-remove:hover{background:#FEF2F2;color:#991B1B}
.act-divider{border:none;border-top:1px solid #F1F5F9;margin:4px 0}
</style>
</head>
<body class="store-admin-body">
<header class="top-bar">
  <a class="logo" href="home.php" style="text-decoration:none"><span style="color:var(--primary,#FF4D2E)">LOKAL</span></a>
  <nav class="store-admin-nav" aria-label="Store pages">
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
    <a class="store-admin-tab active" href="store_products.php">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
      <span>Products</span>
    </a>
    <a class="store-admin-tab" href="logout.php">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      <span>Log out</span>
    </a>
  </nav>
</header>

<main class="store-admin-shell">
  <section class="store-admin-card">
    <div class="products-header">
      <div>
        <h1>Products</h1>
        <p><?php echo escape($store_name !== "" ? $store_name : $user_name); ?></p>
      </div>
      <button class="add-product-btn" id="open-add-modal" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Product
      </button>
    </div>

    <?php if ($notice !== ""): ?>
      <div class="notice success" id="product-notice">
        <span><?php echo escape($notice); ?></span>
        <button class="notice-dismiss" onclick="dismissNotice()" type="button" aria-label="Dismiss">&times;</button>
      </div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="notice error"><?php foreach ($errors as $e): ?><div><?php echo escape($e); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div id="products-table-wrap">
      <?php if ($products): ?>
      <table class="products-dt" id="products-dt">
        <thead>
          <tr>
            <th style="width:64px">Image</th>
            <th>Name</th>
            <th>Price</th>
            <th>Status</th>
            <th style="width:200px" class="dt-nosort">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p): ?>
          <tr class="<?php echo $p['is_active'] ? '' : 'inactive-row'; ?>">
            <!-- Image -->
            <td>
              <?php if (!empty($p['image']) && file_exists(__DIR__ . '/uploads/products/' . $p['image'])): ?>
                <img class="dt-product-img" src="uploads/products/<?php echo escape($p['image']); ?>" alt="<?php echo escape($p['name']); ?>">
              <?php else: ?>
                <div class="dt-product-img-ph">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </div>
              <?php endif; ?>
            </td>
            <!-- Name + description -->
            <td>
              <div class="dt-name"><?php echo escape($p['name'] !== '' ? $p['name'] : 'Product'); ?></div>
              <?php if ($p['description'] !== ''): ?>
                <div class="dt-desc"><?php echo escape($p['description']); ?></div>
              <?php endif; ?>
            </td>
            <!-- Price -->
            <td class="dt-price"><?php echo escape($p['price_label'] !== '' ? $p['price_label'] : '—'); ?></td>
            <!-- Status -->
            <td><span class="pstatus <?php echo $p['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $p['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
            <!-- Actions dropdown -->
            <td style="position:relative">
              <div class="act-dropdown" id="act-<?php echo $p['id']; ?>">
                <button class="act-trigger" type="button" onclick="toggleDrop('act-<?php echo $p['id']; ?>')">
                  Actions
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="act-menu">
                  <!-- Edit -->
                  <button class="act-item" type="button"
                    data-edit-id="<?php echo $p['id']; ?>"
                    data-edit-name="<?php echo escape($p['name']); ?>"
                    data-edit-price="<?php echo $p['price_raw'] !== null ? $p['price_raw'] : ''; ?>"
                    data-edit-desc="<?php echo escape($p['description']); ?>"
                    data-edit-img="<?php echo !empty($p['image']) ? escape('uploads/products/' . $p['image']) : ''; ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit Product
                  </button>
                  <!-- Toggle Modal Trigger -->
                  <button type="button" class="act-item <?php echo $p['is_active'] ? 'act-deactivate' : 'act-activate'; ?>"
                    data-toggle-id="<?php echo $p['id']; ?>"
                    data-toggle-name="<?php echo escape($p['name']); ?>"
                    data-toggle-state="<?php echo $p['is_active'] ? 0 : 1; ?>">
                    <?php if ($p['is_active']): ?>
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                      Deactivate
                    <?php else: ?>
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                      Activate
                    <?php endif; ?>
                  </button>
                  <hr class="act-divider">
                  <!-- Remove -->
                  <button class="act-item act-remove" type="button"
                    data-delete-id="<?php echo $p['id']; ?>"
                    data-delete-name="<?php echo escape($p['name']); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    Remove Product
                  </button>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <div style="padding:48px 24px;text-align:center;border:2px dashed rgba(255,91,46,.2);border-radius:18px;color:#94A3B8">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:12px"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
          <p style="margin:0;font-size:14px">No products yet. Click <strong>Add Product</strong> to get started.</p>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<!-- ADD MODAL -->
<div class="pm-overlay" id="add-overlay" role="dialog" aria-modal="true" aria-labelledby="add-title">
  <div class="pm-box">
    <div class="pm-head">
      <h2 id="add-title">Add Product</h2>
      <button class="pm-close" id="close-add" type="button">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="product_add_submit" value="1">
      <div class="pm-img-wrap" id="add-img-wrap">
        <img class="pm-img-preview" id="add-img-preview" src="" alt="">
        <div class="pm-img-ph" id="add-img-ph">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          <span>Click to upload image</span>
        </div>
        <input class="pm-img-input" type="file" name="product_image" id="add-img-input" accept="image/*">
      </div>
      <div class="pm-fields" style="margin-top:16px">
        <div class="pm-field">
          <label for="add-name">Product Name *</label>
          <input type="text" id="add-name" name="product_name" placeholder="e.g. Chicken Adobo" required>
        </div>
        <div class="pm-field">
          <label for="add-price">Price (PHP) *</label>
          <input type="number" id="add-price" name="product_price" placeholder="e.g. 120.00" step="0.01" min="0" required>
        </div>
        <div class="pm-field">
          <label for="add-desc">Description <span style="font-weight:400;text-transform:none;color:#94A3B8">(optional)</span></label>
          <textarea id="add-desc" name="product_description" placeholder="Brief description…"></textarea>
        </div>
      </div>
      <div class="pm-actions" style="margin-top:16px">
        <button class="pm-cancel" type="button" id="cancel-add">Cancel</button>
        <button class="pm-save" type="submit">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Product
        </button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="pm-overlay" id="edit-overlay" role="dialog" aria-modal="true" aria-labelledby="edit-title">
  <div class="pm-box">
    <div class="pm-head">
      <h2 id="edit-title">Edit Product</h2>
      <button class="pm-close" id="close-edit" type="button">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="product_edit_submit" value="1">
      <input type="hidden" name="product_id" id="edit-id">
      <div class="pm-img-wrap" id="edit-img-wrap">
        <img class="pm-img-preview" id="edit-img-preview" src="" alt="" style="display:none">
        <div class="pm-img-ph" id="edit-img-ph">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          <span>Click to change image</span>
        </div>
        <input class="pm-img-input" type="file" name="product_image" id="edit-img-input" accept="image/*">
      </div>
      <div class="pm-fields" style="margin-top:16px">
        <div class="pm-field">
          <label for="edit-name">Product Name *</label>
          <input type="text" id="edit-name" name="product_name" required>
        </div>
        <div class="pm-field">
          <label for="edit-price">Price (PHP) *</label>
          <input type="number" id="edit-price" name="product_price" step="0.01" min="0" required>
        </div>
        <div class="pm-field">
          <label for="edit-desc">Description <span style="font-weight:400;text-transform:none;color:#94A3B8">(optional)</span></label>
          <textarea id="edit-desc" name="product_description"></textarea>
        </div>
      </div>
      <div class="pm-actions" style="margin-top:16px">
        <button class="pm-cancel" type="button" id="cancel-edit">Cancel</button>
        <button class="pm-save" type="submit">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="pm-overlay" id="delete-overlay" role="dialog" aria-modal="true" aria-labelledby="delete-title">
  <div class="pm-box" style="max-width:400px">
    <div class="pm-head">
      <h2 id="delete-title" style="font-size:18px">Remove Product</h2>
      <button class="pm-close" id="close-delete" type="button">&times;</button>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <div style="width:56px;height:56px;border-radius:14px;background:#FEE2E2;display:flex;align-items:center;justify-content:center;color:#DC2626">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
      </div>
      <p style="margin:4px 0 0;font-size:14px;color:#374151">Are you sure you want to remove <strong id="delete-product-name"></strong>? This cannot be undone.</p>
    </div>
    <form method="post" id="delete-form">
      <input type="hidden" name="product_delete_submit" value="1">
      <input type="hidden" name="product_id" id="delete-product-id">
      <div class="pm-actions" style="margin-top:8px">
        <button class="pm-cancel" type="button" id="cancel-delete">Cancel</button>
        <button type="submit" style="padding:10px 22px;border-radius:12px;border:none;background:linear-gradient(135deg,#DC2626,#b91c1c);color:#fff;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(220,38,38,.3);transition:all .2s">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
          Yes, Remove
        </button>
      </div>
    </form>
  </div>
</div>

<!-- TOGGLE CONFIRM MODAL -->
<div class="pm-overlay" id="toggle-overlay" role="dialog" aria-modal="true" aria-labelledby="toggle-title">
  <div class="pm-box" style="max-width:400px">
    <div class="pm-head">
      <h2 id="toggle-title" style="font-size:18px">Product Status</h2>
      <button class="pm-close" id="close-toggle" type="button">&times;</button>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <div id="toggle-icon-wrap" style="width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center">
      </div>
      <p style="margin:4px 0 0;font-size:14px;color:#374151" id="toggle-modal-msg">Are you sure you want to change the status of <strong id="toggle-product-name"></strong>?</p>
    </div>
    <form method="post" id="toggle-form">
      <input type="hidden" name="product_toggle_submit" value="1">
      <input type="hidden" name="product_id" id="toggle-product-id">
      <input type="hidden" name="new_state" id="toggle-new-state">
      <div class="pm-actions" style="margin-top:8px">
        <button class="pm-cancel" type="button" id="cancel-toggle">Cancel</button>
        <button type="submit" id="toggle-confirm-btn" style="padding:10px 22px;border-radius:12px;border:none;color:#fff;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;transition:all .2s">
          Confirm
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(o){if(!o)return;o.classList.add("open");document.body.style.overflow="hidden"}
function closeModal(o){if(!o)return;o.classList.remove("open");document.body.style.overflow=""}
function imgPreview(input,preview,ph){
  input.addEventListener("change",()=>{
    const f=input.files&&input.files[0];
    if(f){const r=new FileReader();r.onload=e=>{preview.src=e.target.result;preview.style.display="block";ph.style.display="none"};r.readAsDataURL(f)}
  });
}

function toggleDrop(id){
  const el=document.getElementById(id);
  if(!el)return;
  const isOpen=el.classList.contains("open");
  document.querySelectorAll(".act-dropdown.open").forEach(d=>d.classList.remove("open"));
  if(!isOpen){el.classList.add("open");}
}

const addO    = document.getElementById("add-overlay");
const editO   = document.getElementById("edit-overlay");
const deleteO = document.getElementById("delete-overlay");
const toggleO = document.getElementById("toggle-overlay");

imgPreview(document.getElementById("add-img-input"),document.getElementById("add-img-preview"),document.getElementById("add-img-ph"));
imgPreview(document.getElementById("edit-img-input"),document.getElementById("edit-img-preview"),document.getElementById("edit-img-ph"));

document.getElementById("open-add-modal").onclick=()=>openModal(addO);
document.getElementById("close-add").onclick=()=>closeModal(addO);
document.getElementById("cancel-add").onclick=()=>closeModal(addO);
addO.addEventListener("click",e=>{if(e.target===addO)closeModal(addO)});

document.getElementById("close-edit").onclick=()=>closeModal(editO);
document.getElementById("cancel-edit").onclick=()=>closeModal(editO);
editO.addEventListener("click",e=>{if(e.target===editO)closeModal(editO)});

if(document.getElementById("close-delete")) document.getElementById("close-delete").onclick=()=>closeModal(deleteO);
if(document.getElementById("cancel-delete")) document.getElementById("cancel-delete").onclick=()=>closeModal(deleteO);
if(deleteO) deleteO.addEventListener("click",e=>{if(e.target===deleteO)closeModal(deleteO)});

if(document.getElementById("close-toggle")) document.getElementById("close-toggle").onclick=()=>closeModal(toggleO);
if(document.getElementById("cancel-toggle")) document.getElementById("cancel-toggle").onclick=()=>closeModal(toggleO);
if(toggleO) toggleO.addEventListener("click",e=>{if(e.target===toggleO)closeModal(toggleO)});

function escapeHtml(str){
  const d=document.createElement("div");
  d.textContent=str||"";
  return d.innerHTML;
}

// Delegate Edit, Delete, and Toggle button clicks (works even after DataTables sorts/paginates)
document.addEventListener("click",e=>{
  // Close dropdown if clicked outside
  if(!e.target.closest(".act-dropdown")){
    document.querySelectorAll(".act-dropdown.open").forEach(d=>d.classList.remove("open"));
  }

  const editBtn=e.target.closest("[data-edit-id]");
  if(editBtn){
    document.querySelectorAll(".act-dropdown.open").forEach(d=>d.classList.remove("open"));
    document.getElementById("edit-id").value=editBtn.dataset.editId;
    document.getElementById("edit-name").value=editBtn.dataset.editName;
    document.getElementById("edit-price").value=editBtn.dataset.editPrice;
    document.getElementById("edit-desc").value=editBtn.dataset.editDesc;
    document.getElementById("edit-img-input").value="";
    const prev=document.getElementById("edit-img-preview");
    const ph=document.getElementById("edit-img-ph");
    if(editBtn.dataset.editImg){
      prev.src=editBtn.dataset.editImg;
      prev.style.display="block";
      ph.style.display="none";
    }else{
      prev.src="";
      prev.style.display="none";
      ph.style.display="flex";
    }
    openModal(editO);
    return;
  }

  const delBtn=e.target.closest("[data-delete-id]");
  if(delBtn){
    document.querySelectorAll(".act-dropdown.open").forEach(d=>d.classList.remove("open"));
    document.getElementById("delete-product-id").value=delBtn.dataset.deleteId;
    document.getElementById("delete-product-name").textContent=delBtn.dataset.deleteName||"this product";
    openModal(deleteO);
    return;
  }

  const togBtn=e.target.closest("[data-toggle-id]");
  if(togBtn){
    document.querySelectorAll(".act-dropdown.open").forEach(d=>d.classList.remove("open"));
    const pid=togBtn.dataset.toggleId;
    const pname=togBtn.dataset.toggleName||"this product";
    const nstate=parseInt(togBtn.dataset.toggleState,10);

    document.getElementById("toggle-product-id").value=pid;
    document.getElementById("toggle-new-state").value=nstate;
    document.getElementById("toggle-product-name").textContent=pname;

    const titleEl=document.getElementById("toggle-title");
    const iconWrap=document.getElementById("toggle-icon-wrap");
    const msgEl=document.getElementById("toggle-modal-msg");
    const confirmBtn=document.getElementById("toggle-confirm-btn");

    if(nstate===1){
      titleEl.textContent="Activate Product";
      iconWrap.style.background="#D1FAE5";
      iconWrap.style.color="#047857";
      iconWrap.innerHTML='<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
      msgEl.innerHTML='Are you sure you want to activate <strong>'+escapeHtml(pname)+'</strong>? It will become visible to customers.';
      confirmBtn.textContent="Yes, Activate";
      confirmBtn.style.background="linear-gradient(135deg,#059669,#047857)";
      confirmBtn.style.boxShadow="0 4px 12px rgba(5,150,105,.3)";
    }else{
      titleEl.textContent="Deactivate Product";
      iconWrap.style.background="#FEF3C7";
      iconWrap.style.color="#D97706";
      iconWrap.innerHTML='<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>';
      msgEl.innerHTML='Are you sure you want to deactivate <strong>'+escapeHtml(pname)+'</strong>? It will be hidden from customers.';
      confirmBtn.textContent="Yes, Deactivate";
      confirmBtn.style.background="linear-gradient(135deg,#D97706,#B45309)";
      confirmBtn.style.boxShadow="0 4px 12px rgba(217,119,6,.3)";
    }

    openModal(toggleO);
    return;
  }
});

document.addEventListener("keydown",e=>{
  if(e.key==="Escape"){
    closeModal(addO);
    closeModal(editO);
    closeModal(deleteO);
    closeModal(toggleO);
    document.querySelectorAll(".act-dropdown.open").forEach(d=>d.classList.remove("open"));
  }
});

<?php if ($errors && isset($_POST["product_add_submit"])): ?>
openModal(addO);
document.getElementById("add-name").value=<?php echo json_encode($_POST["product_name"] ?? ""); ?>;
document.getElementById("add-price").value=<?php echo json_encode($_POST["product_price"] ?? ""); ?>;
document.getElementById("add-desc").value=<?php echo json_encode($_POST["product_description"] ?? ""); ?>;
<?php endif; ?>

// Notice dismiss
function dismissNotice() {
  const n = document.getElementById("product-notice");
  if (!n) return;
  n.style.transition = "opacity 0.3s ease, transform 0.3s ease";
  n.style.opacity = "0";
  n.style.transform = "translateY(-6px)";
  setTimeout(() => n.remove(), 320);
}
<?php if ($notice !== ""): ?>
setTimeout(dismissNotice, 3500);
<?php endif; ?>
</script>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
  if ($.fn.DataTable && document.getElementById('products-dt')) {
    $('#products-dt').DataTable({
      pageLength: 10,
      order: [[1, 'asc']],
      columnDefs: [
        { orderable: false, targets: [0, 4] } // image + actions cols not sortable
      ],
      language: {
        search: '',
        searchPlaceholder: 'Search products…',
        lengthMenu: 'Show _MENU_ per page',
        info: 'Showing _START_–_END_ of _TOTAL_ products',
        infoEmpty: 'No products found',
        emptyTable: 'No products added yet',
        paginate: { previous: '‹', next: '›' }
      },
      dom: '<"dt-top-bar"fl>t<"dt-top-bar"ip>'
    });
  }
});
</script>
</body>
</html>
