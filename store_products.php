<?php
require_once "auth.php";
require_once "db.php";
require_login();

if (($_SESSION["account_type"] ?? "") !== "store") {
    header("Location: home.php");
    exit;
}

$user_id = (int) ($_SESSION["user_id"] ?? 0);
$user_name = $_SESSION["user_name"] ?? "Store";
$store_name = "";

$product_values = [
    "product_name" => "",
    "product_price" => "",
    "product_description" => ""
];
$products = [];
$errors = [];
$notice = "";

$format_price_label = static function ($price): string {
    if ($price === null || $price === "") {
        return "";
    }
    return "PHP " . number_format((float) $price, 2);
};

$store_stmt = $mysqli->prepare(
    "SELECT store_name
     FROM users
     WHERE id = ?
     LIMIT 1"
);
if ($store_stmt) {
    $store_stmt->bind_param("i", $user_id);
    $store_stmt->execute();
    $store_stmt->bind_result($row_store_name);
    if ($store_stmt->fetch()) {
        $store_name = trim((string) ($row_store_name ?? ""));
    }
    $store_stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["product_add_submit"])) {
        $product_values["product_name"] = trim($_POST["product_name"] ?? "");
        $product_values["product_price"] = trim($_POST["product_price"] ?? "");
        $product_values["product_description"] = trim($_POST["product_description"] ?? "");

        if ($product_values["product_name"] === "") {
            $errors[] = "Product name is required.";
        }

        if ($product_values["product_price"] !== "") {
            if (!is_numeric($product_values["product_price"])) {
                $errors[] = "Product price must be numeric.";
            } elseif ((float) $product_values["product_price"] < 0) {
                $errors[] = "Product price cannot be negative.";
            }
        }

        if (!$errors) {
            $description_value = $product_values["product_description"] !== "" ? $product_values["product_description"] : null;
            $saved = false;

            if ($product_values["product_price"] === "") {
                $insert_stmt = $mysqli->prepare(
                    "INSERT INTO store_products (store_user_id, product_name, product_description)
                     VALUES (?, ?, ?)"
                );
                if ($insert_stmt) {
                    $insert_stmt->bind_param("iss", $user_id, $product_values["product_name"], $description_value);
                    $saved = $insert_stmt->execute();
                    $insert_stmt->close();
                }
            } else {
                $price_value = (float) $product_values["product_price"];
                $insert_stmt = $mysqli->prepare(
                    "INSERT INTO store_products (store_user_id, product_name, product_description, product_price)
                     VALUES (?, ?, ?, ?)"
                );
                if ($insert_stmt) {
                    $insert_stmt->bind_param("issd", $user_id, $product_values["product_name"], $description_value, $price_value);
                    $saved = $insert_stmt->execute();
                    $insert_stmt->close();
                }
            }

            if ($saved) {
                $notice = "Product added.";
                $product_values = [
                    "product_name" => "",
                    "product_price" => "",
                    "product_description" => ""
                ];
            } else {
                $errors[] = "Unable to add product. Please try again.";
            }
        }
    }

    if (isset($_POST["product_delete_submit"])) {
        $product_id = (int) ($_POST["product_id"] ?? 0);
        if ($product_id <= 0) {
            $errors[] = "Invalid product selected.";
        } else {
            $delete_stmt = $mysqli->prepare(
                "DELETE FROM store_products
                 WHERE id = ? AND store_user_id = ?
                 LIMIT 1"
            );
            if ($delete_stmt) {
                $delete_stmt->bind_param("ii", $product_id, $user_id);
                if ($delete_stmt->execute() && $delete_stmt->affected_rows > 0) {
                    $notice = "Product removed.";
                } else {
                    $errors[] = "Unable to remove product.";
                }
                $delete_stmt->close();
            } else {
                $errors[] = "Unable to remove product.";
            }
        }
    }
}

$list_stmt = $mysqli->prepare(
    "SELECT id, product_name, product_description, product_price
     FROM store_products
     WHERE store_user_id = ?
     ORDER BY id DESC"
);
if ($list_stmt) {
    $list_stmt->bind_param("i", $user_id);
    $list_stmt->execute();
    $list_stmt->bind_result($product_id, $product_name, $product_description, $product_price);
    while ($list_stmt->fetch()) {
        $products[] = [
            "id" => (int) $product_id,
            "name" => trim((string) ($product_name ?? "")),
            "description" => trim((string) ($product_description ?? "")),
            "price_label" => $format_price_label($product_price)
        ];
    }
    $list_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Product | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/store-admin.css?v=hover-effects-1">
</head>
<body class="store-admin-body">
    <header class="top-bar">
        <div class="logo">Lokal Store</div>
        <nav class="store-admin-nav" aria-label="Store pages">
            <a class="store-admin-tab" href="home.php">Home</a>
            <a class="store-admin-tab" href="account_profile.php">Profile</a>
            <a class="store-admin-tab" href="order_history.php">Orders</a>
            <a class="store-admin-tab active" href="store_products.php">Product</a>
            <a class="store-admin-tab" href="logout.php">Log out</a>
        </nav>
    </header>

    <main class="store-admin-shell">
        <section class="store-admin-card">
            <h1>Store Product</h1>
            <p class="status-text">Add products that users can view on store cards and map popups.</p>

            <div class="account-meta">
                <span><?php echo $store_name !== "" ? escape($store_name) : escape($user_name); ?></span>
            </div>

            <?php if ($notice !== ""): ?>
                <div class="notice success"><?php echo escape($notice); ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="notice error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo escape($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="form-stack store-product-form">
                <input type="hidden" name="product_add_submit" value="1">
                <div class="field">
                    <label for="product_name">Product name</label>
                    <input type="text" id="product_name" name="product_name" value="<?php echo escape($product_values["product_name"]); ?>" required>
                </div>
                <div class="field">
                    <label for="product_price">Price (optional)</label>
                    <input type="text" id="product_price" name="product_price" value="<?php echo escape($product_values["product_price"]); ?>" placeholder="e.g. 120.00">
                </div>
                <div class="field">
                    <label for="product_description">Description (optional)</label>
                    <textarea id="product_description" name="product_description" rows="3"><?php echo escape($product_values["product_description"]); ?></textarea>
                </div>
                <button class="btn" type="submit">Add Product</button>
            </form>

            <div class="store-products-list">
                <?php if ($products): ?>
                    <?php foreach ($products as $product): ?>
                        <article class="store-product-item">
                            <div class="store-product-copy">
                                <h3><?php echo escape($product["name"] !== "" ? $product["name"] : "Product"); ?></h3>
                                <?php if ($product["price_label"] !== ""): ?>
                                    <p class="store-product-price"><?php echo escape($product["price_label"]); ?></p>
                                <?php endif; ?>
                                <?php if ($product["description"] !== ""): ?>
                                    <p class="store-product-description"><?php echo escape($product["description"]); ?></p>
                                <?php endif; ?>
                            </div>
                            <form method="post">
                                <input type="hidden" name="product_delete_submit" value="1">
                                <input type="hidden" name="product_id" value="<?php echo (int) $product["id"]; ?>">
                                <button type="submit" class="product-delete-btn">Remove</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="store-products-empty">No products yet. Add your first product above.</p>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
