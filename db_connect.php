<?php
$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "lokal_app";


function quote_identifier(string $identifier): string
{
    return "`" . str_replace("`", "``", $identifier) . "`";
}

function open_database_connection(string $host, string $user, string $pass, string $dbName): mysqli
{
    try {
        $mysqli = new mysqli($host, $user, $pass, $dbName);
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() !== 1049) {
            die("Database connection failed: " . $exception->getMessage());
        }

        try {
            $mysqli = new mysqli($host, $user, $pass);
            $quotedDbName = quote_identifier($dbName);
            $mysqli->query(
                "CREATE DATABASE IF NOT EXISTS {$quotedDbName}
                 CHARACTER SET utf8mb4
                 COLLATE utf8mb4_unicode_ci"
            );
            $mysqli->select_db($dbName);
        } catch (mysqli_sql_exception $bootstrapException) {
            die("Database connection failed: " . $bootstrapException->getMessage());
        }
    }

    $mysqli->set_charset("utf8mb4");

    return $mysqli;
}

function get_table_columns(mysqli $mysqli, string $table): array
{
    $columns = [];
    $quotedTable = quote_identifier($table);
    $result = $mysqli->query("SHOW COLUMNS FROM {$quotedTable}");

    while ($row = $result->fetch_assoc()) {
        $field = $row["Field"] ?? "";
        if ($field !== "") {
            $columns[$field] = true;
        }
    }

    $result->close();

    return $columns;
}

function ensure_users_schema(mysqli $mysqli): void
{
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_type VARCHAR(20) NOT NULL DEFAULT 'user',
            store_name VARCHAR(150) DEFAULT NULL,
            store_contact VARCHAR(30) DEFAULT NULL,
            store_address VARCHAR(255) DEFAULT NULL,
            store_lat DECIMAL(10, 7) DEFAULT NULL,
            store_lng DECIMAL(10, 7) DEFAULT NULL,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            contact VARCHAR(30) NOT NULL,
            vehicle_registration VARCHAR(100) DEFAULT NULL,
            id_image VARCHAR(255) DEFAULT NULL,
            orcr_image VARCHAR(255) DEFAULT NULL,
            is_approved TINYINT(1) NOT NULL DEFAULT 0,
            email VARCHAR(191) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = get_table_columns($mysqli, "users");
    $columnStatements = [
        "account_type" => "ALTER TABLE users ADD COLUMN account_type VARCHAR(20) NOT NULL DEFAULT 'user' AFTER id",
        "store_name" => "ALTER TABLE users ADD COLUMN store_name VARCHAR(150) DEFAULT NULL AFTER account_type",
        "store_contact" => "ALTER TABLE users ADD COLUMN store_contact VARCHAR(30) DEFAULT NULL AFTER store_name",
        "store_address" => "ALTER TABLE users ADD COLUMN store_address VARCHAR(255) DEFAULT NULL AFTER store_contact",
        "store_lat" => "ALTER TABLE users ADD COLUMN store_lat DECIMAL(10, 7) DEFAULT NULL AFTER store_address",
        "store_lng" => "ALTER TABLE users ADD COLUMN store_lng DECIMAL(10, 7) DEFAULT NULL AFTER store_lat",
        "user_address" => "ALTER TABLE users ADD COLUMN user_address VARCHAR(255) DEFAULT NULL AFTER store_lng",
        "user_lat" => "ALTER TABLE users ADD COLUMN user_lat DECIMAL(10, 7) DEFAULT NULL AFTER user_address",
        "user_lng" => "ALTER TABLE users ADD COLUMN user_lng DECIMAL(10, 7) DEFAULT NULL AFTER user_lat",
        "password_reset_token" => "ALTER TABLE users ADD COLUMN password_reset_token VARCHAR(6) DEFAULT NULL AFTER password_hash",
        "password_reset_expires" => "ALTER TABLE users ADD COLUMN password_reset_expires DATETIME DEFAULT NULL AFTER password_reset_token",
        "store_category" => "ALTER TABLE users ADD COLUMN store_category VARCHAR(80) DEFAULT NULL AFTER store_name",
        "vehicle_registration" => "ALTER TABLE users ADD COLUMN vehicle_registration VARCHAR(100) DEFAULT NULL AFTER contact",
        "id_image" => "ALTER TABLE users ADD COLUMN id_image VARCHAR(255) DEFAULT NULL AFTER vehicle_registration",
        "orcr_image" => "ALTER TABLE users ADD COLUMN orcr_image VARCHAR(255) DEFAULT NULL AFTER id_image",
        "is_approved" => "ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER orcr_image",
    ];

    foreach ($columnStatements as $column => $statement) {
        if (!isset($columns[$column])) {
            $mysqli->query($statement);
        }
    }

    $mysqli->query(
        "UPDATE users
         SET store_contact = contact
         WHERE account_type = 'store'
           AND (store_contact IS NULL OR store_contact = '')"
    );
}

function ensure_store_products_table(mysqli $mysqli): void
{
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS store_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            store_user_id INT NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            product_description VARCHAR(255) DEFAULT NULL,
            product_image VARCHAR(255) DEFAULT NULL,
            product_price DECIMAL(10, 2) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_store_products_store_user_id (store_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $columns = get_table_columns($mysqli, "store_products");
    if (!isset($columns["product_image"])) {
        $mysqli->query("ALTER TABLE store_products ADD COLUMN product_image VARCHAR(255) DEFAULT NULL AFTER product_description");
    }
}

function ensure_gps_logs_table(mysqli $mysqli): void
{
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS gps_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device VARCHAR(100) NOT NULL,
            status VARCHAR(100) DEFAULT '',
            device_time VARCHAR(20) NOT NULL,
            valid TINYINT(1) DEFAULT NULL,
            updated INT DEFAULT NULL,
            sat INT DEFAULT NULL,
            lat DECIMAL(10, 7) DEFAULT NULL,
            lng DECIMAL(10, 7) DEFAULT NULL,
            chars_count INT DEFAULT NULL,
            hdop DECIMAL(6, 2) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_gps_logs_device_created_at (device, created_at),
            INDEX idx_gps_logs_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_orders_schema(mysqli $mysqli): void
{
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_user_id INT NOT NULL,
            store_user_id INT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            order_type VARCHAR(20) NOT NULL DEFAULT 'delivery',
            customer_name VARCHAR(201) NOT NULL,
            delivery_address VARCHAR(255) DEFAULT NULL,
            delivery_lat DECIMAL(10, 7) NOT NULL,
            delivery_lng DECIMAL(10, 7) NOT NULL,
            store_name VARCHAR(150) NOT NULL,
            store_address VARCHAR(255) DEFAULT NULL,
            store_lat DECIMAL(10, 7) NOT NULL,
            store_lng DECIMAL(10, 7) NOT NULL,
            subtotal_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            delivery_distance_km DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            accepted_at TIMESTAMP NULL DEFAULT NULL,
            declined_at TIMESTAMP NULL DEFAULT NULL,
            pickup_at TIMESTAMP NULL DEFAULT NULL,
            delivered_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_orders_customer_status (customer_user_id, status),
            INDEX idx_orders_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = get_table_columns($mysqli, "orders");
    $columnStatements = [
        "order_type" => "ALTER TABLE orders ADD COLUMN order_type VARCHAR(20) NOT NULL DEFAULT 'delivery' AFTER status",
        "subtotal_amount" => "ALTER TABLE orders ADD COLUMN subtotal_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER store_lng",
        "delivery_fee" => "ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER subtotal_amount",
        "delivery_distance_km" => "ALTER TABLE orders ADD COLUMN delivery_distance_km DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER delivery_fee",
    ];

    foreach ($columnStatements as $column => $statement) {
        if (!isset($columns[$column])) {
            $mysqli->query($statement);
        }
    }

    $mysqli->query(
        "UPDATE orders
         SET subtotal_amount = total_amount
         WHERE subtotal_amount = 0.00
           AND delivery_fee = 0.00
           AND total_amount > 0.00"
    );
}

function ensure_order_items_table(mysqli $mysqli): void
{
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            unit_price DECIMAL(10, 2) DEFAULT NULL,
            quantity INT NOT NULL,
            line_total DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            INDEX idx_order_items_order_id (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_categories_table(mysqli $mysqli): void
{
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            slug VARCHAR(80) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Seed default categories if table is empty
    $check = $mysqli->query("SELECT COUNT(*) AS total FROM categories");
    if ($check) {
        $row = $check->fetch_assoc();
        $check->close();
        if ((int) ($row["total"] ?? 0) === 0) {
            $defaults = [
                ["Store",      "store",      1],
                ["Tech",       "tech",       2],
                ["Restaurant", "restaurant", 3],
                ["Art",        "art",        4],
                ["Music",      "music",      5],
                ["Coffee",     "coffee",     6],
                ["Auto",       "auto",       7],
            ];
            $ins = $mysqli->prepare(
                "INSERT IGNORE INTO categories (name, slug, is_active, sort_order) VALUES (?, ?, 1, ?)"
            );
            if ($ins) {
                foreach ($defaults as [$catName, $catSlug, $catOrder]) {
                    $ins->bind_param("ssi", $catName, $catSlug, $catOrder);
                    $ins->execute();
                }
                $ins->close();
            }
        }
    }
}

$conn = open_database_connection($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$mysqli = $conn;

ensure_users_schema($conn);
ensure_store_products_table($conn);
ensure_gps_logs_table($conn);
ensure_orders_schema($conn);
ensure_order_items_table($conn);
ensure_categories_table($conn);
