<?php
require_once "auth.php";
require_once "db.php";

if (is_logged_in()) {
    header("Location: home.php");
    exit;
}

$errors = [];
$values = [
    "account_type"   => "user",
    "first_name"     => "",
    "middle_name"    => "",
    "last_name"      => "",
    "contact"        => "",
    "email"          => "",
    "user_address"   => "",
    "user_lat"       => "",
    "user_lng"       => "",
    "store_name"     => "",
    "store_address"  => "",
    "store_lat"      => "",
    "store_lng"      => "",
    "store_category" => "",
    "vehicle_registration" => "",
    "orcr_image" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $values["account_type"]   = strtolower(trim($_POST["account_type"] ?? "user"));
    $values["first_name"]     = trim($_POST["first_name"] ?? "");
    $values["middle_name"]    = trim($_POST["middle_name"] ?? "");
    $values["last_name"]      = trim($_POST["last_name"] ?? "");
    $values["contact"]        = trim($_POST["contact"] ?? "");
    $values["email"]          = trim($_POST["email"] ?? "");
    $values["user_address"]   = trim($_POST["user_address"] ?? "");
    $values["user_lat"]       = trim($_POST["user_lat"] ?? "");
    $values["user_lng"]       = trim($_POST["user_lng"] ?? "");
    $values["store_name"]     = trim($_POST["store_name"] ?? "");
    $values["store_address"]  = trim($_POST["store_address"] ?? "");
    $values["store_lat"]      = trim($_POST["store_lat"] ?? "");
    $values["store_lng"]      = trim($_POST["store_lng"] ?? "");
    $values["store_category"] = trim($_POST["store_category"] ?? "");
    $values["vehicle_registration"] = trim($_POST["vehicle_registration"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (!in_array($values["account_type"], ["user", "store", "driver"], true)) {
        $errors[] = "Select a valid account type.";
    }
    if ($values["first_name"] === "") {
        $errors[] = "First name is required.";
    }
    if ($values["middle_name"] === "") {
        $errors[] = "Middle name is required.";
    }
    if ($values["last_name"] === "") {
        $errors[] = "Last name is required.";
    }
    if ($values["contact"] === "") {
        $errors[] = "Contact is required.";
    }
    if (!filter_var($values["email"], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email is required.";
    }
    if ($values["account_type"] === "user") {
        if ($values["user_address"] === "") {
            $errors[] = "Delivery address is required.";
        }
        if ($values["user_lat"] === "" || $values["user_lng"] === "") {
            $errors[] = "Pin your delivery location on the map.";
        } elseif (!is_numeric($values["user_lat"]) || !is_numeric($values["user_lng"])) {
            $errors[] = "Delivery location coordinates are invalid.";
        }
    }
    if ($values["account_type"] === "store") {
        if ($values["store_name"] === "") {
            $errors[] = "Store name is required.";
        }
        if ($values["store_address"] === "") {
            $errors[] = "Store address is required.";
        }
        if ($values["store_lat"] === "" || $values["store_lng"] === "") {
            $errors[] = "Pin your store location on the map.";
        } elseif (!is_numeric($values["store_lat"]) || !is_numeric($values["store_lng"])) {
            $errors[] = "Store location coordinates are invalid.";
        }
    }
    $profile_image_file = null;
    if (isset($_FILES["driver_profile_image"]) && is_uploaded_file($_FILES["driver_profile_image"]["tmp_name"])) {
        $profile_image_file = $_FILES["driver_profile_image"];
    } elseif (isset($_FILES["profile_image"]) && is_uploaded_file($_FILES["profile_image"]["tmp_name"])) {
        $profile_image_file = $_FILES["profile_image"];
    }

    if ($values["account_type"] === "driver") {
        if ($values["vehicle_registration"] === "") {
            $errors[] = "Vehicle registration is required for drivers.";
        }
        // Profile photo is REQUIRED for driver accounts
        if (!$profile_image_file) {
            $errors[] = "Profile photo is required for driver registration.";
        } else {
            $allowed_ext = ["jpg", "jpeg", "png", "webp"];
            $orig_p = $profile_image_file["name"];
            $ext_p = strtolower(pathinfo($orig_p, PATHINFO_EXTENSION));
            if (!in_array($ext_p, $allowed_ext, true)) {
                $errors[] = "Profile photo must be JPG, PNG, or WEBP.";
            }
        }
        // ID image is required for driver accounts
        if (!isset($_FILES["id_image"]) || !is_uploaded_file($_FILES["id_image"]["tmp_name"])) {
            $errors[] = "Valid ID image is required for driver registration.";
        } else {
            $allowed_ext = ["jpg", "jpeg", "png", "webp"];
            $orig = $_FILES["id_image"]["name"];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext, true)) {
                $errors[] = "ID image must be JPG, PNG, or WEBP.";
            }
        }
        // OR/CR image required
        if (!isset($_FILES["orcr_image"]) || !is_uploaded_file($_FILES["orcr_image"]["tmp_name"])) {
            $errors[] = "OR/CR image is required for driver registration.";
        } else {
            $orig2 = $_FILES["orcr_image"]["name"];
            $ext2 = strtolower(pathinfo($orig2, PATHINFO_EXTENSION));
            if (!in_array($ext2, $allowed_ext, true)) {
                $errors[] = "OR/CR image must be JPG, PNG, or WEBP.";
            }
        }
    } else {
        // Optional profile photo for user/store accounts
        if ($profile_image_file) {
            $allowed_ext = ["jpg", "jpeg", "png", "webp"];
            $orig_p = $profile_image_file["name"];
            $ext_p = strtolower(pathinfo($orig_p, PATHINFO_EXTENSION));
            if (!in_array($ext_p, $allowed_ext, true)) {
                $errors[] = "Profile photo must be JPG, PNG, or WEBP.";
            }
        }
    }
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    if (!$errors) {
        $check = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
        if ($check) {
            $check->bind_param("s", $values["email"]);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $errors[] = "Email is already registered.";
            }
            $check->close();
        } else {
            $errors[] = "Unable to validate email.";
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare(
            "INSERT INTO users (account_type, store_name, first_name, middle_name, last_name, contact, email, password_hash, user_address, user_lat, user_lng, store_address, store_lat, store_lng, store_category, vehicle_registration, orcr_image, id_image, profile_image)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $store_name      = $values["account_type"] === "store" ? $values["store_name"] : null;
            $user_address    = $values["account_type"] === "user"  ? $values["user_address"]   : null;
            $user_lat        = $values["account_type"] === "user"  ? $values["user_lat"]        : null;
            $user_lng        = $values["account_type"] === "user"  ? $values["user_lng"]        : null;
            $store_address   = $values["account_type"] === "store" ? $values["store_address"]   : null;
            $store_lat       = $values["account_type"] === "store" ? $values["store_lat"]       : null;
            $store_lng       = $values["account_type"] === "store" ? $values["store_lng"]       : null;
            $store_category  = $values["account_type"] === "store" && $values["store_category"] !== ""
                ? $values["store_category"] : null;
            $id_image_filename = null;
            $orcr_image_filename = null;
            $profile_image_filename = null;

            $allowed_ext = ["jpg", "jpeg", "png", "webp"];

            if ($profile_image_file) {
                $orig_p = $profile_image_file["name"];
                $ext_p = strtolower(pathinfo($orig_p, PATHINFO_EXTENSION));
                if (in_array($ext_p, $allowed_ext, true)) {
                    $uploads_dir_p = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "profiles";
                    if (!is_dir($uploads_dir_p)) {
                        mkdir($uploads_dir_p, 0777, true);
                    }
                    $profile_image_filename = bin2hex(random_bytes(8)) . "_" . time() . "." . $ext_p;
                    $dest_p = $uploads_dir_p . DIRECTORY_SEPARATOR . $profile_image_filename;
                    move_uploaded_file($profile_image_file["tmp_name"], $dest_p);
                }
            }

            if ($values["account_type"] === "driver") {
                if (isset($_FILES["id_image"]) && is_uploaded_file($_FILES["id_image"]["tmp_name"])) {
                    $orig = $_FILES["id_image"]["name"];
                    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed_ext, true)) {
                        $uploads_dir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "ids";
                        if (!is_dir($uploads_dir)) {
                            mkdir($uploads_dir, 0755, true);
                        }
                        $id_image_filename = bin2hex(random_bytes(8)) . "_" . time() . "." . $ext;
                        $dest = $uploads_dir . DIRECTORY_SEPARATOR . $id_image_filename;
                        move_uploaded_file($_FILES["id_image"]["tmp_name"], $dest);
                    }
                }
                if (isset($_FILES["orcr_image"]) && is_uploaded_file($_FILES["orcr_image"]["tmp_name"])) {
                    $orig2 = $_FILES["orcr_image"]["name"];
                    $ext2 = strtolower(pathinfo($orig2, PATHINFO_EXTENSION));
                    if (in_array($ext2, $allowed_ext, true)) {
                        $uploads_dir2 = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "orcr";
                        if (!is_dir($uploads_dir2)) {
                            mkdir($uploads_dir2, 0755, true);
                        }
                        $orcr_image_filename = bin2hex(random_bytes(8)) . "_" . time() . "." . $ext2;
                        $dest2 = $uploads_dir2 . DIRECTORY_SEPARATOR . $orcr_image_filename;
                        move_uploaded_file($_FILES["orcr_image"]["tmp_name"], $dest2);
                    }
                }
            }

            $vehicle_registration = $values["account_type"] === "driver" ? $values["vehicle_registration"] : null;

            $stmt->bind_param(
                str_repeat("s", 19),
                $values["account_type"],
                $store_name,
                $values["first_name"],
                $values["middle_name"],
                $values["last_name"],
                $values["contact"],
                $values["email"],
                $hash,
                $user_address,
                $user_lat,
                $user_lng,
                $store_address,
                $store_lat,
                $store_lng,
                $store_category,
                $vehicle_registration,
                $orcr_image_filename,
                $id_image_filename,
                $profile_image_filename
            );
            if ($stmt->execute()) {
                header("Location: login.php?registered=1");
                exit;
            }
            $stmt->close();
        }
        $errors[] = "Registration failed. Please try again.";
    }
}

// Fetch active categories for store dropdown
$reg_categories = [];
$cat_res = $mysqli->query("SELECT name, slug FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
if ($cat_res) {
    while ($cr = $cat_res->fetch_assoc()) {
        $reg_categories[] = ["name" => $cr["name"], "slug" => $cr["slug"]];
    }
    $cat_res->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=no-scroll-reg-1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        /* ── Fullscreen Zero-Scroll Wrapper ── */
        html, body {
            height: 100vh;
            max-height: 100vh;
            overflow: hidden !important;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        .reg-shell {
            height: 100vh;
            max-height: 100vh;
            width: 100vw;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px 20px;
            box-sizing: border-box;
            overflow: hidden;
            position: relative;
            z-index: 1;
        }

        .reg-container {
            display: grid;
            grid-template-columns: 1.18fr 0.82fr;
            gap: 16px;
            width: 100%;
            max-width: 1140px;
            height: min(95vh, 730px);
            align-items: stretch;
        }

        /* ── Left Side: Form Panel ── */
        .reg-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: 20px;
            border: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 16px 40px -8px rgba(15, 23, 42, 0.12);
            padding: 20px 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            box-sizing: border-box;
        }

        .reg-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid #F1F5F9;
        }

        .reg-header-titles h1 {
            font-size: 21px;
            margin: 0 0 2px;
            color: #0F172A;
            line-height: 1.2;
            font-weight: 800;
        }

        .reg-header-titles p {
            margin: 0;
            font-size: 12.5px;
            color: #64748B;
            font-weight: 500;
        }

        .reg-signin-btn {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--primary, #FF4D2E);
            background: rgba(255, 77, 46, 0.08);
            padding: 5px 12px;
            border-radius: 999px;
            border: 1px solid rgba(255, 77, 46, 0.2);
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }

        .reg-signin-btn:hover {
            background: rgba(255, 77, 46, 0.16);
            border-color: #FF4D2E;
        }

        /* ── Role Selector Tabs (Segmented Control) ── */
        .reg-role-nav {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 6px;
            background: #F1F5F9;
            padding: 4px;
            border-radius: 12px;
            margin-bottom: 10px;
        }

        .reg-role-tab {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 9px;
            font-size: 12.5px;
            font-weight: 700;
            color: #64748B;
            cursor: pointer;
            border: none;
            background: transparent;
            user-select: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .reg-role-tab input {
            display: none;
        }

        .reg-role-tab:has(input:checked) {
            background: #FFFFFF;
            color: var(--primary, #FF4D2E);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
        }

        .reg-role-tab svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
        }

        /* ── Compact Form Grid ── */
        .reg-form-body {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            justify-content: center;
        }

        .reg-grid-row {
            display: grid;
            gap: 10px;
        }

        .reg-grid-3 {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .reg-grid-2 {
            grid-template-columns: 1fr 1fr;
        }

        .reg-field {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .reg-field label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #475569;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .reg-field input, 
        .reg-field select {
            width: 100%;
            height: 36px;
            padding: 6px 11px;
            border-radius: 8px;
            border: 1.5px solid #E2E8F0;
            background: #FFFFFF;
            color: #0F172A;
            font-family: inherit;
            font-size: 13px;
            font-weight: 500;
            outline: none;
            box-sizing: border-box;
            transition: all 0.15s ease;
        }

        .reg-field input:focus, 
        .reg-field select:focus {
            border-color: #FF4D2E;
            box-shadow: 0 0 0 3px rgba(255, 77, 46, 0.12);
        }

        .reg-field input[type="file"] {
            padding: 4px 8px;
            font-size: 11.5px;
            height: 36px;
            cursor: pointer;
        }

        /* Inline Current Location Button */
        .inline-loc-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: rgba(255, 77, 46, 0.08);
            border: 1px solid rgba(255, 77, 46, 0.25);
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 10.5px;
            font-weight: 700;
            color: #FF4D2E;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .inline-loc-btn:hover {
            background: rgba(255, 77, 46, 0.18);
            border-color: #FF4D2E;
        }

        .inline-loc-btn:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        /* Password Toggle */
        .reg-pw-wrap {
            position: relative;
        }
        .reg-pw-wrap input {
            padding-right: 32px;
        }
        .reg-pw-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94A3B8;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .reg-pw-btn:hover {
            color: #0F172A;
        }

        /* Error Notice */
        .reg-errors {
            background: #FEF2F2;
            border: 1px solid #FCA5A5;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 11.5px;
            color: #991B1B;
            margin-bottom: 6px;
            line-height: 1.35;
        }

        /* Submit Action Row */
        .reg-footer {
            margin-top: 8px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .reg-submit-btn {
            width: 100%;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #FF4D2E 0%, #E03E22 100%);
            color: #FFFFFF;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.2px;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(255, 77, 46, 0.35);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .reg-submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(255, 77, 46, 0.45);
            filter: brightness(1.04);
        }

        .reg-terms-text {
            text-align: center;
            font-size: 11px;
            color: #94A3B8;
            margin: 0;
        }

        /* ── Right Side: Map & Live Context Panel (White Theme) ── */
        .reg-side-panel {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: 20px;
            border: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 16px 40px -8px rgba(15, 23, 42, 0.12);
            padding: 20px 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: #0F172A;
            overflow: hidden;
            position: relative;
            box-sizing: border-box;
        }

        .reg-side-panel::before {
            content: "";
            position: absolute;
            top: -40%;
            right: -40%;
            width: 80%;
            height: 80%;
            background: radial-gradient(circle, rgba(255, 77, 46, 0.06) 0%, transparent 70%);
            pointer-events: none;
        }

        .reg-side-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            position: relative;
            z-index: 2;
        }

        .reg-side-header-text h2 {
            font-size: 16px;
            margin: 0 0 2px;
            color: #0F172A;
            font-weight: 800;
            line-height: 1.2;
        }

        .reg-side-header-text p {
            margin: 0;
            font-size: 12px;
            color: #64748B;
            font-weight: 500;
        }

        .reg-map-container {
            flex: 1;
            min-height: 0;
            width: 100%;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 2;
        }

        .reg-map-frame {
            flex: 1;
            min-height: 180px;
            width: 100%;
            border-radius: 14px;
            border: 1.5px solid #E2E8F0;
            overflow: hidden;
            background: #F8FAFC;
            box-shadow: inset 0 2px 4px rgba(15, 23, 42, 0.03);
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .reg-map-frame #reg-map,
        .reg-map-frame #user-map,
        .reg-map-frame #store-map,
        .reg-map-frame .leaflet-container {
            width: 100% !important;
            height: 100% !important;
            min-height: 100% !important;
            flex: 1 !important;
            border-radius: 0 !important;
        }

        /* ── Map Pin Markers (Synced with home.php) ── */
        .custom-marker {
            background: transparent;
            border: none;
        }

        .map-marker {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 3px solid #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            background: var(--primary, #FF4D2E);
            box-shadow: 0 8px 20px rgba(255, 77, 46, 0.4);
            transition: transform 0.2s ease;
        }

        .map-marker:hover {
            transform: scale(1.12);
        }

        .map-marker.store {
            background: #10B981;
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.45);
        }

        .map-marker.user,
        .map-marker.customer {
            background: #FF4D2E;
            box-shadow: 0 8px 20px rgba(255, 77, 46, 0.45);
        }

        .map-marker.rider {
            background: #FFFFFF;
            color: #FF4D2E;
            border-color: #FF4D2E;
            box-shadow: 0 0 0 3px rgba(255, 77, 46, 0.15), 0 8px 20px rgba(0, 0, 0, 0.25);
        }

        .marker-svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .reg-map-footer {
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .reg-map-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 600;
            color: #475569;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 6px 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }

        .reg-map-status-pill svg {
            color: #FF4D2E;
            flex-shrink: 0;
        }

        .reg-map-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 700;
            color: #FF4D2E;
            background: rgba(255, 77, 46, 0.08);
            border: 1.5px solid rgba(255, 77, 46, 0.25);
            border-radius: 8px;
            padding: 6px 14px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            white-space: nowrap;
        }

        .reg-map-action-btn:hover {
            background: rgba(255, 77, 46, 0.16);
            border-color: #FF4D2E;
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(255, 77, 46, 0.15);
        }

        .reg-map-action-btn:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        /* ── Driver Onboarding Info View (White Theme) ── */
        .reg-driver-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 10px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 16px;
        }

        .driver-perk-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 12px;
            color: #64748B;
            line-height: 1.45;
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 10px 12px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
        }

        .driver-perk-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: rgba(255, 77, 46, 0.1);
            color: #FF4D2E;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 13px;
        }

        /* ── Address Autofill Dropdown ── */
        .address-autofill-wrap {
            position: relative;
            width: 100%;
        }
        .address-suggestions {
            position: fixed;
            background: #fff;
            border: 1px solid rgba(255, 91, 46, .28);
            border-radius: 10px;
            box-shadow: 0 8px 28px rgba(0,0,0,.18);
            z-index: 999999;
            overflow-y: auto;
            display: none;
            max-height: 180px;
        }
        .address-suggestions.open {
            display: block;
        }
        .address-suggestion-item {
            padding: 8px 12px;
            font-size: 12px;
            color: #333;
            cursor: pointer;
            border-bottom: 1px solid rgba(0,0,0,.06);
            display: flex;
            align-items: flex-start;
            gap: 6px;
            transition: background .12s;
        }
        .address-suggestion-item:last-child {
            border-bottom: none;
        }
        .address-suggestion-item:hover,
        .address-suggestion-item.active {
            background: rgba(255, 91, 46, .08);
        }
        .address-suggestion-item .sug-icon {
            flex-shrink: 0;
            margin-top: 1px;
            color: #ff5b2e;
            font-size: 12px;
        }
        .address-suggestion-item .sug-text strong {
            display: block;
            font-weight: 600;
            color: #222;
        }
        .address-suggestion-item .sug-text span {
            color: #777;
            font-size: 11px;
        }
        .address-autofill-spinner {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 91, 46, .2);
            border-top-color: #ff5b2e;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            display: none;
            pointer-events: none;
        }
        .address-autofill-spinner.visible {
            display: block;
        }
        @keyframes spin { to { transform: translateY(-50%) rotate(360deg); } }

        /* Responsive Fallback */
        @media (max-width: 920px) {
            html, body {
                height: auto;
                max-height: none;
                overflow-y: auto !important;
            }
            .reg-shell {
                height: auto;
                max-height: none;
                padding: 16px;
                align-items: stretch;
                justify-content: flex-start;
                min-height: 100vh;
            }
            .reg-container {
                grid-template-columns: 1fr;
                height: auto;
                max-height: none;
                gap: 12px;
            }
            .reg-card {
                padding: 16px 14px;
                max-height: none;
            }
            .reg-form {
                max-height: none;
                overflow-y: auto;
                padding-right: 4px;
            }
            .reg-side-panel {
                min-height: 280px;
            }
            .reg-form-stack {
                gap: 11px;
            }
            .field {
                gap: 4px;
            }
            .field input, .field select, .field textarea {
                padding: 11px 14px;
                font-size: 14px;
            }
            .choice-group {
                grid-template-columns: repeat(2, 1fr);
            }
            .btn {
                padding: 11px 20px;
                font-size: 14px;
            }
        }
        
        @media (max-width: 620px) {
            .reg-container {
                gap: 10px;
            }
            .reg-card {
                padding: 14px 12px;
            }
            .reg-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .reg-header-titles h1 {
                font-size: 18px;
            }
            .reg-header-titles p {
                font-size: 12px;
            }
            .reg-signin-btn {
                align-self: flex-end;
                width: 100%;
                justify-content: center;
            }
            .choice-group {
                grid-template-columns: 1fr;
            }
            .reg-map-container {
                height: 260px;
            }
            .reg-map-frame {
                min-height: 260px;
            }
        }
    </style>
</head>
<body>
    <main class="reg-shell">
        <div class="reg-container">
            <!-- Left Side: Registration Form Card -->
            <section class="reg-card">
                <!-- Header -->
                <div class="reg-header">
                    <div class="reg-header-titles">
                        <h1>Create Account</h1>
                        <p>Join the Lokal merchant & delivery network</p>
                    </div>
                    <a href="login.php" class="reg-signin-btn">Sign In</a>
                </div>

                <!-- Error Messages if any -->
                <?php if ($errors): ?>
                    <div class="reg-errors">
                        <?php foreach ($errors as $error): ?>
                            <div>• <?php echo escape($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" id="registration-form" enctype="multipart/form-data" style="display:contents;">
                    <!-- Segmented Account Selector -->
                    <div class="reg-role-nav" role="radiogroup" aria-label="Account type">
                        <label class="reg-role-tab" id="tab-user">
                            <input type="radio" name="account_type" value="user" <?php echo $values["account_type"] === "user" ? "checked" : ""; ?> required>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/></svg>
                            <span>Customer</span>
                        </label>
                        <label class="reg-role-tab" id="tab-store">
                            <input type="radio" name="account_type" value="store" <?php echo $values["account_type"] === "store" ? "checked" : ""; ?> required>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l2-5h14l2 5"/><path d="M5 9v11h14V9"/><path d="M9 20v-5h6v5"/></svg>
                            <span>Store Owner</span>
                        </label>
                        <label class="reg-role-tab" id="tab-driver">
                            <input type="radio" name="account_type" value="driver" <?php echo $values["account_type"] === "driver" ? "checked" : ""; ?> required>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/><path d="M9 7H6"/><path d="M8.5 10.5 11 17.5"/><path d="M11 10.5h4l2.5 7"/><path d="M10.5 10.5 14 7.5"/></svg>
                            <span>Rider</span>
                        </label>
                    </div>

                    <div class="reg-form-body">
                        <!-- Row 1: Full Name (3 Cols) -->
                        <div class="reg-grid-row reg-grid-3">
                            <div class="reg-field">
                                <label for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" value="<?php echo escape($values["first_name"]); ?>" placeholder="Juan" required>
                            </div>
                            <div class="reg-field">
                                <label for="middle_name">Middle Name</label>
                                <input type="text" id="middle_name" name="middle_name" value="<?php echo escape($values["middle_name"]); ?>" placeholder="Santos" required>
                            </div>
                            <div class="reg-field">
                                <label for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" value="<?php echo escape($values["last_name"]); ?>" placeholder="Dela Cruz" required>
                            </div>
                        </div>

                        <!-- Row 2: Contact & Email (2 Cols) -->
                        <div class="reg-grid-row reg-grid-2">
                            <div class="reg-field">
                                <label for="contact">Phone Number</label>
                                <input type="tel" id="contact" name="contact" value="<?php echo escape($values["contact"]); ?>" placeholder="0912 345 6789" required>
                            </div>
                            <div class="reg-field">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" value="<?php echo escape($values["email"]); ?>" placeholder="juan@example.com" required>
                            </div>
                        </div>

                        <!-- USER SPECIFIC: Delivery Address & Profile Photo -->
                        <div class="user-only" data-user-only>
                            <div class="reg-field">
                                <label for="user_address">
                                    <span>Delivery Address</span>
                                    <button type="button" class="inline-loc-btn" id="use-current-location-field-btn">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
                                        <span>GPS</span>
                                    </button>
                                </label>
                                <div class="address-autofill-wrap">
                                    <input type="text" id="user_address" name="user_address" value="<?php echo escape($values["user_address"]); ?>" placeholder="Type your street, barangay or city..." autocomplete="off">
                                    <div class="address-autofill-spinner" id="user-addr-spinner"></div>
                                    <div class="address-suggestions" id="user-addr-suggestions" role="listbox" aria-label="Address suggestions"></div>
                                </div>
                                <input type="hidden" id="user_lat" name="user_lat" value="<?php echo escape($values["user_lat"]); ?>">
                                <input type="hidden" id="user_lng" name="user_lng" value="<?php echo escape($values["user_lng"]); ?>">
                            </div>
                            <div class="reg-field" style="margin-top: 8px;">
                                <label for="user_profile_image">Profile Photo <span style="font-weight:normal; font-size:11.5px; color:#64748B;">(Optional)</span></label>
                                <input type="file" id="user_profile_image" name="profile_image" accept="image/*">
                            </div>
                        </div>

                        <!-- STORE SPECIFIC: Store Info -->
                        <div class="store-only" data-store-only hidden>
                            <div class="reg-grid-row reg-grid-2" style="margin-bottom: 8px;">
                                <div class="reg-field">
                                    <label for="store_name">Store Name</label>
                                    <input type="text" id="store_name" name="store_name" value="<?php echo escape($values["store_name"]); ?>" placeholder="e.g. Bella Bakery">
                                </div>
                                <div class="reg-field">
                                    <label for="store_category">Category</label>
                                    <select id="store_category" name="store_category">
                                        <option value="">— Select category —</option>
                                        <?php foreach ($reg_categories as $rc): ?>
                                            <option value="<?php echo escape($rc['slug']); ?>" <?php echo $values['store_category'] === $rc['slug'] ? 'selected' : ''; ?>>
                                                <?php echo escape($rc['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="reg-field">
                                <label for="store_address">
                                    <span>Store Address</span>
                                    <button type="button" class="inline-loc-btn" id="use-current-location-store-btn">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
                                        <span>GPS</span>
                                    </button>
                                </label>
                                <div class="address-autofill-wrap">
                                    <input type="text" id="store_address" name="store_address" value="<?php echo escape($values["store_address"]); ?>" placeholder="Type store address or street..." autocomplete="off">
                                    <div class="address-autofill-spinner" id="store-addr-spinner"></div>
                                    <div class="address-suggestions" id="store-addr-suggestions" role="listbox" aria-label="Store address suggestions"></div>
                                </div>
                                <input type="hidden" id="store_lat" name="store_lat" value="<?php echo escape($values["store_lat"]); ?>">
                                <input type="hidden" id="store_lng" name="store_lng" value="<?php echo escape($values["store_lng"]); ?>">
                            </div>
                        </div>

                        <!-- DRIVER SPECIFIC: Vehicle & Documents -->
                        <div class="driver-only" data-driver-only hidden>
                            <div class="reg-grid-row reg-grid-2" style="margin-bottom: 8px;">
                                <div class="reg-field">
                                    <label for="vehicle_registration">Vehicle Type / Plate</label>
                                    <input type="text" id="vehicle_registration" name="vehicle_registration" value="<?php echo escape($values['vehicle_registration']); ?>" placeholder="e.g. Motorcycle (ABC-1234)">
                                </div>
                                <div class="reg-field">
                                    <label for="driver_profile_image">Driver Profile Photo <span style="font-weight:700; font-size:11.5px; color:#EF4444;">*Required</span></label>
                                    <input type="file" id="driver_profile_image" name="driver_profile_image" accept="image/*">
                                </div>
                            </div>
                            <div class="reg-grid-row reg-grid-2">
                                <div class="reg-field">
                                    <label for="id_image">Valid ID Document <span style="font-weight:700; font-size:11.5px; color:#EF4444;">*Required</span></label>
                                    <input type="file" id="id_image" name="id_image" accept="image/*">
                                </div>
                                <div class="reg-field">
                                    <label for="orcr_image">OR / CR Document <span style="font-weight:700; font-size:11.5px; color:#EF4444;">*Required</span></label>
                                    <input type="file" id="orcr_image" name="orcr_image" accept="image/*">
                                </div>
                            </div>
                        </div>

                        <!-- Row 3: Passwords (2 Cols) -->
                        <div class="reg-grid-row reg-grid-2">
                            <div class="reg-field">
                                <label for="password">Password</label>
                                <div class="reg-pw-wrap">
                                    <input type="password" id="password" name="password" placeholder="Min. 6 characters" required>
                                    <button type="button" class="reg-pw-btn" onclick="togglePasswordVisibility('password', this)" title="Toggle password">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="reg-field">
                                <label for="confirm_password">Confirm Password</label>
                                <div class="reg-pw-wrap">
                                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat password" required>
                                    <button type="button" class="reg-pw-btn" onclick="togglePasswordVisibility('confirm_password', this)" title="Toggle password">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer Action -->
                    <div class="reg-footer">
                        <button type="submit" class="reg-submit-btn">
                            <span>Complete Registration</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </button>
                        <p class="reg-terms-text">By registering, you agree to Lokal Terms of Service & Privacy Policy.</p>
                    </div>
                </form>
            </section>

            <!-- Right Side: Live Map & Context Panel -->
            <section class="reg-side-panel">
                <!-- User & Store Map Panel -->
                <div class="reg-map-container" id="side-map-container">
                    <div class="reg-side-header">
                        <div class="reg-side-header-text">
                            <h2 id="side-map-title">Pin Delivery Location</h2>
                            <p id="side-map-desc">Tap anywhere on the map to set your exact location</p>
                        </div>
                    </div>

                    <div class="reg-map-frame">
                        <div id="reg-map" style="width:100%; height:100%;"></div>
                    </div>

                    <div class="reg-map-footer">
                        <div class="reg-map-status-pill" id="active-map-status">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
                            <span id="map-status-text">No location pinned yet</span>
                        </div>
                        <button type="button" class="reg-map-action-btn" id="use-current-location">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
                            <span>Use My GPS</span>
                        </button>
                    </div>
                </div>

                <!-- Driver Information Panel (Shown when Driver is chosen) -->
                <div class="reg-driver-info" id="driver-info-panel" style="display:none;">
                    <div class="reg-side-header">
                        <div class="reg-side-header-text">
                            <h2>Rider Onboarding Hub</h2>
                            <p>Fast approval & flexible local delivery earnings</p>
                        </div>
                    </div>

                    <div class="driver-perk-item">
                        <div class="driver-perk-icon">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        </div>
                        <div>
                            <strong style="color:#0F172A; display:block; font-size:12.5px; margin-bottom:2px;">Instant Order Routing</strong>
                            Receive delivery requests within your neighborhood automatically with real-time turn directions.
                        </div>
                    </div>

                    <div class="driver-perk-item">
                        <div class="driver-perk-icon">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        </div>
                        <div>
                            <strong style="color:#0F172A; display:block; font-size:12.5px; margin-bottom:2px;">Daily Payouts</strong>
                            Keep 100% of tips and earn competitive rates per completed delivery run.
                        </div>
                    </div>

                    <div class="driver-perk-item">
                        <div class="driver-perk-icon">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                        </div>
                        <div>
                            <strong style="color:#0F172A; display:block; font-size:12.5px; margin-bottom:2px;">Fast Verification</strong>
                            Upload clear photos of your Government ID and OR/CR for 24-hour express account activation.
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const accountRadios = document.querySelectorAll('input[name="account_type"]');
        const storeOnlyFields = document.querySelectorAll('[data-store-only]');
        const userOnlyFields = document.querySelectorAll('[data-user-only]');
        const driverOnlyFields = document.querySelectorAll('[data-driver-only]');
        
        const sideMapContainer = document.getElementById("side-map-container");
        const driverInfoPanel = document.getElementById("driver-info-panel");
        const sideMapTitle = document.getElementById("side-map-title");
        const sideMapDesc = document.getElementById("side-map-desc");
        const mapStatusText = document.getElementById("map-status-text");

        const storeAddressInput = document.getElementById("store_address");
        const storeNameInput = document.getElementById("store_name");
        const storeLatInput = document.getElementById("store_lat");
        const storeLngInput = document.getElementById("store_lng");
        
        const userAddressInput = document.getElementById("user_address");
        const userLatInput = document.getElementById("user_lat");
        const userLngInput = document.getElementById("user_lng");
        
        let regMap = null;
        let regMarker = null;

        function setStatus(msg) {
            if (mapStatusText) {
                mapStatusText.textContent = msg;
            }
        }

        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            if (input.type === "password") {
                input.type = "text";
                btn.style.color = "#FF4D2E";
            } else {
                input.type = "password";
                btn.style.color = "#94A3B8";
            }
        }

        const storeIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker store">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 9l2-5h14l2 5"></path>
                        <path d="M5 9v11h14V9"></path>
                        <path d="M9 20v-5h6v5"></path>
                    </svg>
                </div>`,
            iconSize: [36, 36],
            iconAnchor: [18, 36],
            popupAnchor: [0, -32]
        });

        const customerIcon = L.divIcon({
            className: "custom-marker",
            html: `<div class="map-marker user">
                    <svg class="marker-svg" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M5 21a7 7 0 0 1 14 0"></path>
                    </svg>
                </div>`,
            iconSize: [36, 36],
            iconAnchor: [18, 36],
            popupAnchor: [0, -30]
        });

        function getActiveMarkerIcon() {
            const selected = document.querySelector('input[name="account_type"]:checked')?.value || "user";
            return selected === "store" ? storeIcon : customerIcon;
        }

        function setMapMarker(latlng) {
            if (!regMap) return;
            const currentIcon = getActiveMarkerIcon();
            if (regMarker) {
                regMarker.setLatLng(latlng);
                regMarker.setIcon(currentIcon);
            } else {
                regMarker = L.marker(latlng, { icon: currentIcon }).addTo(regMap);
            }
        }

        async function reverseGeocodeAddress(lat, lng, targetInput) {
            if (!targetInput) return;
            try {
                setStatus("Looking up address…");
                const res = await fetch(
                    `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=18&addressdetails=0`,
                    { cache: "force-cache" }
                );
                if (!res.ok) return;
                const data = await res.json();
                if (data && data.display_name) {
                    targetInput.value = data.display_name;
                    setStatus(`Pinned: ${lat.toFixed(5)}, ${lng.toFixed(5)}`);
                }
            } catch (e) {
                setStatus(`Pinned: ${lat.toFixed(5)}, ${lng.toFixed(5)}`);
            }
        }

        function initRegMap() {
            if (regMap) {
                regMap.invalidateSize();
                return;
            }
            const mapContainer = document.getElementById("reg-map");
            if (!mapContainer) return;

            regMap = L.map("reg-map").setView([14.5995, 120.9842], 13);
            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(regMap);

            regMap.on("click", (event) => {
                const selected = document.querySelector('input[name="account_type"]:checked')?.value || "user";
                const isStore = selected === "store";
                const lat = event.latlng.lat;
                const lng = event.latlng.lng;

                setMapMarker(event.latlng);

                if (isStore) {
                    if (storeLatInput) storeLatInput.value = lat.toFixed(6);
                    if (storeLngInput) storeLngInput.value = lng.toFixed(6);
                    setStatus(`Pinned: ${lat.toFixed(5)}, ${lng.toFixed(5)}`);
                    reverseGeocodeAddress(lat, lng, storeAddressInput);
                } else {
                    if (userLatInput) userLatInput.value = lat.toFixed(6);
                    if (userLngInput) userLngInput.value = lng.toFixed(6);
                    setStatus(`Pinned: ${lat.toFixed(5)}, ${lng.toFixed(5)}`);
                    reverseGeocodeAddress(lat, lng, userAddressInput);
                }
            });

            // Auto-resize observer to guarantee zero gap on any layout reflow
            if (window.ResizeObserver) {
                const mapFrame = document.querySelector(".reg-map-frame");
                if (mapFrame) {
                    const ro = new ResizeObserver(() => {
                        if (regMap) regMap.invalidateSize();
                    });
                    ro.observe(mapFrame);
                }
            }

            // Fallback initial geolocation if no coords entered
            const hasUserCoords = userLatInput && userLatInput.value && userLngInput && userLngInput.value;
            const hasStoreCoords = storeLatInput && storeLatInput.value && storeLngInput && storeLngInput.value;
            if (!hasUserCoords && !hasStoreCoords && ("geolocation" in navigator)) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        if (regMap) {
                            regMap.setView([position.coords.latitude, position.coords.longitude], 14);
                        }
                    },
                    () => {},
                    { enableHighAccuracy: true, timeout: 8000 }
                );
            }
        }

        function toggleAccountMode() {
            const selected = document.querySelector('input[name="account_type"]:checked')?.value || "user";
            const isStore = selected === "store";
            const isDriver = selected === "driver";

            storeOnlyFields.forEach((field) => field.hidden = !isStore);
            userOnlyFields.forEach((field) => field.hidden = isStore || isDriver);
            driverOnlyFields.forEach((field) => field.hidden = !isDriver);

            if (storeAddressInput) {
                storeAddressInput.required = isStore;
                storeAddressInput.disabled = !isStore;
            }
            if (storeNameInput) {
                storeNameInput.required = isStore;
                storeNameInput.disabled = !isStore;
            }
            if (userAddressInput) {
                userAddressInput.required = !isStore && !isDriver;
                userAddressInput.disabled = isStore || isDriver;
            }
            if (storeLatInput && storeLngInput) {
                storeLatInput.disabled = !isStore;
                storeLngInput.disabled = !isStore;
            }
            if (userLatInput && userLngInput) {
                userLatInput.disabled = isStore || isDriver;
                userLngInput.disabled = isStore || isDriver;
            }

            // Adjust Right Panel
            if (isDriver) {
                sideMapContainer.style.display = "none";
                driverInfoPanel.style.display = "flex";
            } else {
                sideMapContainer.style.display = "flex";
                driverInfoPanel.style.display = "none";
                initRegMap();

                if (isStore) {
                    sideMapTitle.textContent = "Pin Store Location";
                    sideMapDesc.textContent = "Drop a pin to help customers find your store";
                    if (storeLatInput && storeLatInput.value && storeLngInput && storeLngInput.value) {
                        const coords = [Number(storeLatInput.value), Number(storeLngInput.value)];
                        setMapMarker(coords);
                        regMap.setView(coords, 15);
                        setStatus(`Pinned: ${storeLatInput.value}, ${storeLngInput.value}`);
                    } else {
                        setStatus("Tap map to pin store location");
                    }
                } else {
                    sideMapTitle.textContent = "Pin Delivery Location";
                    sideMapDesc.textContent = "Drop a pin to ensure accurate home delivery";
                    if (userLatInput && userLatInput.value && userLngInput && userLngInput.value) {
                        const coords = [Number(userLatInput.value), Number(userLngInput.value)];
                        setMapMarker(coords);
                        regMap.setView(coords, 15);
                        setStatus(`Pinned: ${userLatInput.value}, ${userLngInput.value}`);
                    } else {
                        setStatus("Tap map to pin delivery location");
                    }
                }

                if (regMarker) {
                    regMarker.setIcon(getActiveMarkerIcon());
                }

                // Invalidate size immediately and after layout settle
                if (regMap) {
                    regMap.invalidateSize();
                    setTimeout(() => regMap && regMap.invalidateSize(), 60);
                    setTimeout(() => regMap && regMap.invalidateSize(), 200);
                }
            }
        }

        accountRadios.forEach((radio) => {
            radio.addEventListener("change", toggleAccountMode);
        });

        // Initialize mode and map on load
        toggleAccountMode();

        /* ── Current Location Geolocation Button Handler ── */
        function fetchCurrentLocation() {
            if (!("geolocation" in navigator)) {
                setStatus("Geolocation is not supported by your browser.");
                return;
            }

            const btnMap = document.getElementById("use-current-location");
            const btnField = document.getElementById("use-current-location-field-btn");
            const btnStoreField = document.getElementById("use-current-location-store-btn");

            if (btnMap) { btnMap.disabled = true; btnMap.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 1s linear infinite;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="2" x2="12" y2="6"/></svg> <span>Locating…</span>'; }
            if (btnField) { btnField.disabled = true; btnField.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 1s linear infinite;"><circle cx="12" cy="12" r="10"/></svg> <span>Locating…</span>'; }
            if (btnStoreField) { btnStoreField.disabled = true; btnStoreField.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 1s linear infinite;"><circle cx="12" cy="12" r="10"/></svg> <span>Locating…</span>'; }
            setStatus("Getting GPS location…");

            const selected = document.querySelector('input[name="account_type"]:checked')?.value || "user";
            const isStore = selected === "store";

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    initRegMap();
                    setMapMarker([lat, lng]);
                    if (regMap) {
                        regMap.setView([lat, lng], 15);
                        regMap.invalidateSize();
                    }

                    if (isStore) {
                        if (storeLatInput) storeLatInput.value = lat.toFixed(6);
                        if (storeLngInput) storeLngInput.value = lng.toFixed(6);
                        reverseGeocodeAddress(lat, lng, storeAddressInput);
                    } else {
                        if (userLatInput) userLatInput.value = lat.toFixed(6);
                        if (userLngInput) userLngInput.value = lng.toFixed(6);
                        reverseGeocodeAddress(lat, lng, userAddressInput);
                    }

                    const mapIconHtml = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>';
                    if (btnMap) { btnMap.disabled = false; btnMap.innerHTML = `${mapIconHtml} <span>Use My GPS</span>`; }
                    if (btnField) { btnField.disabled = false; btnField.innerHTML = `${mapIconHtml} <span>GPS</span>`; }
                    if (btnStoreField) { btnStoreField.disabled = false; btnStoreField.innerHTML = `${mapIconHtml} <span>GPS</span>`; }
                },
                () => {
                    setStatus("Location access denied or unavailable.");
                    const mapIconHtml = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>';
                    if (btnMap) { btnMap.disabled = false; btnMap.innerHTML = `${mapIconHtml} <span>Use My GPS</span>`; }
                    if (btnField) { btnField.disabled = false; btnField.innerHTML = `${mapIconHtml} <span>GPS</span>`; }
                    if (btnStoreField) { btnStoreField.disabled = false; btnStoreField.innerHTML = `${mapIconHtml} <span>GPS</span>`; }
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }

        const mapLocBtn = document.getElementById("use-current-location");
        const fieldLocBtn = document.getElementById("use-current-location-field-btn");
        const storeFieldLocBtn = document.getElementById("use-current-location-store-btn");
        if (mapLocBtn) mapLocBtn.addEventListener("click", fetchCurrentLocation);
        if (fieldLocBtn) fieldLocBtn.addEventListener("click", fetchCurrentLocation);
        if (storeFieldLocBtn) storeFieldLocBtn.addEventListener("click", fetchCurrentLocation);

        /* ── Address Autofill Helper (Nominatim) for User & Store ── */
        function setupAddressAutofill(inputEl, sugBoxEl, spinnerEl, latInp, lngInp) {
            if (!inputEl || !sugBoxEl) return;
            document.body.appendChild(sugBoxEl);

            let debounceTimer  = null;
            let activeIndex    = -1;
            let currentResults = [];

            function positionDropdown() {
                const rect = inputEl.getBoundingClientRect();
                sugBoxEl.style.top   = (rect.bottom + 4) + "px";
                sugBoxEl.style.left  = rect.left + "px";
                sugBoxEl.style.width = rect.width + "px";
            }

            function showSpinner(show) {
                spinnerEl && spinnerEl.classList.toggle("visible", show);
            }

            function closeSuggestions() {
                sugBoxEl.classList.remove("open");
                sugBoxEl.innerHTML = "";
                activeIndex    = -1;
                currentResults = [];
            }

            function openSuggestions(results) {
                sugBoxEl.innerHTML = "";
                currentResults = results;
                activeIndex    = -1;
                if (!results.length) {
                    const empty = document.createElement("div");
                    empty.className = "address-suggestion-item";
                    empty.innerHTML = `<span class="sug-icon">⚠</span><span class="sug-text"><strong>No results found</strong><span>Try a more specific address</span></span>`;
                    sugBoxEl.appendChild(empty);
                } else {
                    results.forEach((r, i) => {
                        const parts     = r.display_name.split(", ");
                        const primary   = parts.slice(0, 2).join(", ");
                        const secondary = parts.slice(2).join(", ");
                        const item      = document.createElement("div");
                        item.className  = "address-suggestion-item";
                        item.setAttribute("role", "option");
                        item.setAttribute("data-index", i);
                        item.innerHTML  = `<span class="sug-icon">📍</span><span class="sug-text"><strong>${primary}</strong><span>${secondary}</span></span>`;
                        item.addEventListener("mousedown", (e) => {
                            e.preventDefault();
                            selectResult(i);
                        });
                        sugBoxEl.appendChild(item);
                    });
                }
                positionDropdown();
                sugBoxEl.classList.add("open");
            }

            function highlightItem(index) {
                const items = sugBoxEl.querySelectorAll(".address-suggestion-item");
                items.forEach((el, i) => el.classList.toggle("active", i === index));
            }

            function selectResult(index) {
                const r = currentResults[index];
                if (!r) return;
                inputEl.value = r.display_name;
                const lat = parseFloat(r.lat);
                const lng = parseFloat(r.lon);
                if (latInp) latInp.value = lat.toFixed(6);
                if (lngInp) lngInp.value = lng.toFixed(6);
                initRegMap();
                setMapMarker([lat, lng]);
                if (regMap) {
                    regMap.setView([lat, lng], 15);
                    regMap.invalidateSize();
                }
                setStatus(`Pinned: ${lat.toFixed(5)}, ${lng.toFixed(5)}`);
                closeSuggestions();
                inputEl.focus();
            }

            async function fetchSuggestions(query) {
                showSpinner(true);
                try {
                    const url = `https://nominatim.openstreetmap.org/search?format=jsonv2&q=${encodeURIComponent(query)}&limit=5&addressdetails=0`;
                    const res = await fetch(url, { cache: "no-store" });
                    if (!res.ok) throw new Error("Network error");
                    const data = await res.json();
                    openSuggestions(data);
                } catch (err) {
                    closeSuggestions();
                } finally {
                    showSpinner(false);
                }
            }

            inputEl.addEventListener("input", () => {
                clearTimeout(debounceTimer);
                const val = inputEl.value.trim();
                if (val.length < 3) {
                    closeSuggestions();
                    showSpinner(false);
                    return;
                }
                showSpinner(true);
                debounceTimer = setTimeout(() => fetchSuggestions(val), 400);
            });

            inputEl.addEventListener("keydown", (e) => {
                const items = sugBoxEl.querySelectorAll(".address-suggestion-item[data-index]");
                if (!sugBoxEl.classList.contains("open") || !items.length) return;
                if (e.key === "ArrowDown") {
                    e.preventDefault();
                    activeIndex = (activeIndex + 1) % items.length;
                    highlightItem(activeIndex);
                } else if (e.key === "ArrowUp") {
                    e.preventDefault();
                    activeIndex = (activeIndex - 1 + items.length) % items.length;
                    highlightItem(activeIndex);
                } else if (e.key === "Enter" && activeIndex >= 0) {
                    e.preventDefault();
                    selectResult(activeIndex);
                } else if (e.key === "Escape") {
                    closeSuggestions();
                }
            });

            inputEl.addEventListener("blur", () => {
                setTimeout(closeSuggestions, 180);
            });

            window.addEventListener("resize", () => {
                if (sugBoxEl.classList.contains("open")) positionDropdown();
            });
        }

        setupAddressAutofill(
            document.getElementById("user_address"),
            document.getElementById("user-addr-suggestions"),
            document.getElementById("user-addr-spinner"),
            document.getElementById("user_lat"),
            document.getElementById("user_lng")
        );

        setupAddressAutofill(
            document.getElementById("store_address"),
            document.getElementById("store-addr-suggestions"),
            document.getElementById("store-addr-spinner"),
            document.getElementById("store_lat"),
            document.getElementById("store_lng")
        );
    </script>
</body>
</html>
