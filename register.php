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
    if ($values["account_type"] === "driver") {
        if ($values["vehicle_registration"] === "") {
            $errors[] = "Vehicle registration is required for drivers.";
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
              "INSERT INTO users (account_type, store_name, first_name, middle_name, last_name, contact, email, password_hash, user_address, user_lat, user_lng, store_address, store_lat, store_lng, store_category, vehicle_registration, orcr_image, id_image)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
            // Handle driver ID and OR/CR uploads if provided
            $id_image_filename = null;
            $orcr_image_filename = null;
            if ($values["account_type"] === "driver") {
                $allowed_ext = ["jpg", "jpeg", "png", "webp"];
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
                str_repeat("s", 18),
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
                $id_image_filename
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
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        .address-autofill-wrap {
            position: relative;
        }
        .address-suggestions {
            position: fixed;
            background: #fff;
            border: 1px solid rgba(255,91,46,.28);
            border-radius: 10px;
            box-shadow: 0 8px 28px rgba(0,0,0,.15);
            z-index: 999999;
            overflow-y: auto;
            display: none;
            max-height: 240px;
        }
        .address-suggestions.open {
            display: block;
        }
        .address-suggestion-item {
            padding: 10px 14px;
            font-size: 13px;
            color: #333;
            cursor: pointer;
            border-bottom: 1px solid rgba(0,0,0,.06);
            display: flex;
            align-items: flex-start;
            gap: 8px;
            transition: background .15s;
        }
        .address-suggestion-item:last-child {
            border-bottom: none;
        }
        .address-suggestion-item:hover,
        .address-suggestion-item.active {
            background: rgba(255,91,46,.08);
        }
        .address-suggestion-item .sug-icon {
            flex-shrink: 0;
            margin-top: 1px;
            color: #ff5b2e;
            font-size: 14px;
        }
        .address-suggestion-item .sug-text {
            line-height: 1.4;
        }
        .address-suggestion-item .sug-text strong {
            display: block;
            font-weight: 600;
            color: #222;
        }
        .address-suggestion-item .sug-text span {
            color: #777;
            font-size: 12px;
        }
        .address-autofill-spinner {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,91,46,.2);
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

        .profile-location-btn {
            border: 1px solid rgba(255, 91, 46, 0.22);
            border-radius: 10px;
            padding: 8px 14px;
            background: rgba(255, 91, 46, 0.1);
            color: #FF5B2E;
            cursor: pointer;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            transition: background 0.15s ease, opacity 0.15s ease;
        }
        .profile-location-btn:hover,
        .profile-location-btn:focus-visible {
            background: rgba(255, 91, 46, 0.18);
        }
        .profile-location-btn:disabled {
            cursor: wait;
            opacity: 0.68;
        }
    </style>
</head>
<body>
    <main class="auth-shell">
        <div class="auth-grid">
            <section class="brand-panel">
                <div class="brand-top">
                    <div class="badge"><span></span> Lokal Access</div>
                    <div id="brand-copy">
                        <h1>Create your account</h1>
                        <p>Join the red and gold network. Your account unlocks the map dashboard and guided routes.</p>
                    </div>
                    <div class="store-map-wrap" id="store-map-wrap" hidden>
                        <h2>Store location</h2>
                        <p class="status-text">Tap the map to drop a pin for your store.</p>
                        <div id="store-map"></div>
                        <div class="map-status" id="store-map-status">No location pinned yet.</div>
                    </div>
                    <div class="store-map-wrap" id="user-map-wrap">
                        <h2>Delivery location</h2>
                        <p class="status-text">Tap the map to drop a pin for your delivery address.</p>
                        <div style="margin-bottom:10px;">
                            <button class="profile-location-btn" id="use-current-location" type="button">📍 Use my current location</button>
                        </div>
                        <div id="user-map"></div>
                        <div class="map-status" id="user-map-status">No location pinned yet.</div>
                    </div>
                </div>
                <p>Already have an account? <a class="text-link" href="login.php">Sign in here</a>.</p>
            </section>
            <section class="card">
                <h2>Registration</h2>
                <p class="status-text">Complete all fields to continue.</p>
                <?php if ($errors): ?>
                    <div class="notice error">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo escape($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="post" class="form-stack" enctype="multipart/form-data">
                    <div class="field">
                        <label>Register as</label>
                        <div class="choice-group" role="radiogroup" aria-label="Account type">
                            <label class="choice-option">
                                <input type="radio" name="account_type" value="user" <?php echo $values["account_type"] === "user" ? "checked" : ""; ?> required>
                                <span>User</span>
                            </label>
                            <label class="choice-option">
                                <input type="radio" name="account_type" value="store" <?php echo $values["account_type"] === "store" ? "checked" : ""; ?> required>
                                <span>Store</span>
                            </label>
                            <label class="choice-option">
                                <input type="radio" name="account_type" value="driver" <?php echo $values["account_type"] === "driver" ? "checked" : ""; ?> required>
                                <span>Delivery Rider</span>
                            </label>
                        </div>
                    </div>
                    <div class="field store-only" data-store-only>
                        <label for="store_name">Store name</label>
                        <input type="text" id="store_name" name="store_name" value="<?php echo escape($values["store_name"]); ?>" placeholder="Your store name">
                    </div>
                    <div class="field store-only" data-store-only>
                        <label for="store_address">Store address</label>
                        <input type="text" id="store_address" name="store_address" value="<?php echo escape($values["store_address"]); ?>" placeholder="Street, city, province" autocomplete="street-address">
                        <input type="hidden" id="store_lat" name="store_lat" value="<?php echo escape($values["store_lat"]); ?>">
                        <input type="hidden" id="store_lng" name="store_lng" value="<?php echo escape($values["store_lng"]); ?>">
                    </div>
                    <div class="field store-only" data-store-only>
                        <label for="store_category">Store category</label>
                        <select id="store_category" name="store_category" style="height:44px;padding:0 12px;border:1px solid rgba(255,91,46,.22);border-radius:10px;font-size:13.5px;outline:none;width:100%;background:#fff;box-sizing:border-box;">
                            <option value="">— Select a category —</option>
                            <?php foreach ($reg_categories as $rc): ?>
                                <option value="<?php echo escape($rc['slug']); ?>" <?php echo $values['store_category'] === $rc['slug'] ? 'selected' : ''; ?>>
                                    <?php echo escape($rc['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field user-only" data-user-only>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label for="user_address" style="margin-bottom:0;">Delivery address</label>
                            <button class="profile-location-btn" id="use-current-location-field-btn" type="button" style="padding: 4px 10px; font-size: 11.5px;">📍 Use current location</button>
                        </div>
                        <div class="address-autofill-wrap">
                            <input type="text" id="user_address" name="user_address" value="<?php echo escape($values["user_address"]); ?>" placeholder="Start typing your delivery address…" autocomplete="off">
                            <div class="address-autofill-spinner" id="user-addr-spinner"></div>
                            <div class="address-suggestions" id="user-addr-suggestions" role="listbox" aria-label="Address suggestions"></div>
                        </div>
                        <input type="hidden" id="user_lat" name="user_lat" value="<?php echo escape($values["user_lat"]); ?>">
                        <input type="hidden" id="user_lng" name="user_lng" value="<?php echo escape($values["user_lng"]); ?>">
                    </div>
                    <div class="driver-only" data-driver-only hidden>
                        <div class="field">
                            <label for="vehicle_registration">Vehicle registration</label>
                            <input type="text" id="vehicle_registration" name="vehicle_registration" value="<?php echo escape($values['vehicle_registration']); ?>" placeholder="e.g. Motor, Tricycle, Car">
                        </div>
                        <div class="field">
                            <label for="id_image">Valid ID (upload)</label>
                            <input type="file" id="id_image" name="id_image" accept="image/*">
                        </div>
                        <div class="field">
                            <label for="orcr_image">OR/CR document (upload)</label>
                            <input type="file" id="orcr_image" name="orcr_image" accept="image/*">
                        </div>
                    </div>
                    <div class="split">
                        <div class="field">
                            <label for="first_name">First name</label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo escape($values["first_name"]); ?>" required>
                        </div>
                        <div class="field">
                            <label for="middle_name">Middle name</label>
                            <input type="text" id="middle_name" name="middle_name" value="<?php echo escape($values["middle_name"]); ?>" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="last_name">Last name</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo escape($values["last_name"]); ?>" required>
                    </div>
                    <div class="field">
                        <label for="contact">Contact</label>
                        <input type="text" id="contact" name="contact" value="<?php echo escape($values["contact"]); ?>" required>
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo escape($values["email"]); ?>" required>
                    </div>
                    <div class="split">
                        <div class="field">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <div class="field">
                            <label for="confirm_password">Confirm password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                        </div>
                    </div>
                    <button class="btn" type="submit">Create account</button>
                    <p class="status-text">By continuing, you agree to keep your credentials secure.</p>
                </form>
            </section>
        </div>
    </main>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const accountRadios = document.querySelectorAll('input[name="account_type"]');
        const storeOnlyFields = document.querySelectorAll('[data-store-only]');
        const userOnlyFields = document.querySelectorAll('[data-user-only]');
        const driverOnlyFields = document.querySelectorAll('[data-driver-only]');
        const storeMapWrap = document.getElementById("store-map-wrap");
        const userMapWrap = document.getElementById("user-map-wrap");
        const brandCopy = document.getElementById("brand-copy");
        const storeAddressInput = document.getElementById("store_address");
        const storeNameInput = document.getElementById("store_name");
        const storeLatInput = document.getElementById("store_lat");
        const storeLngInput = document.getElementById("store_lng");
        const storeMapStatus = document.getElementById("store-map-status");
        const userAddressInput = document.getElementById("user_address");
        const userLatInput = document.getElementById("user_lat");
        const userLngInput = document.getElementById("user_lng");
        const userMapStatus = document.getElementById("user-map-status");

        let storeMap = null;
        let storeMarker = null;
        let userMap = null;
        let userMarker = null;

        function setStoreMapStatus(message) {
            if (storeMapStatus) {
                storeMapStatus.textContent = message;
            }
        }

        function setUserMapStatus(message) {
            if (userMapStatus) {
                userMapStatus.textContent = message;
            }
        }

        function setStoreMarker(latlng) {
            if (!storeMap) {
                return;
            }
            if (storeMarker) {
                storeMarker.setLatLng(latlng);
            } else {
                storeMarker = L.marker(latlng).addTo(storeMap);
            }
            storeLatInput.value = latlng.lat.toFixed(6);
            storeLngInput.value = latlng.lng.toFixed(6);
            setStoreMapStatus(`Pinned at ${storeLatInput.value}, ${storeLngInput.value}`);
        }

        function setUserMarker(latlng) {
            if (!userMap) {
                return;
            }
            if (userMarker) {
                userMarker.setLatLng(latlng);
            } else {
                userMarker = L.marker(latlng).addTo(userMap);
            }
            userLatInput.value = latlng.lat.toFixed(6);
            userLngInput.value = latlng.lng.toFixed(6);
            setUserMapStatus(`Pinned at ${userLatInput.value}, ${userLngInput.value}`);
        }

        async function reverseGeocodeUserAddress(lat, lng) {
            if (!userAddressInput) return;
            try {
                setUserMapStatus("Looking up address…");
                const res = await fetch(
                    `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=18&addressdetails=0`,
                    { cache: "force-cache" }
                );
                if (!res.ok) return;
                const data = await res.json();
                if (data && data.display_name) {
                    userAddressInput.value = data.display_name;
                    setUserMapStatus(`Pinned at ${lat.toFixed(6)}, ${lng.toFixed(6)}.`);
                }
            } catch (e) {
                setUserMapStatus(`Pinned at ${lat.toFixed(6)}, ${lng.toFixed(6)}.`);
            }
        }

        function initStoreMap() {
            if (storeMap) {
                return;
            }
            storeMap = L.map("store-map").setView([0, 0], 2);
            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                attribution: "&copy; OpenStreetMap contributors"
            }).addTo(storeMap);

            storeMap.on("click", (event) => {
                setStoreMarker(event.latlng);
            });

            if (storeLatInput.value && storeLngInput.value) {
                setStoreMarker({ lat: Number(storeLatInput.value), lng: Number(storeLngInput.value) });
                storeMap.setView([Number(storeLatInput.value), Number(storeLngInput.value)], 15);
                return;
            }

            if ("geolocation" in navigator) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const coords = [position.coords.latitude, position.coords.longitude];
                        storeMap.setView(coords, 14);
                        setStoreMapStatus("Tap the map to pin your store.");
                    },
                    () => {
                        setStoreMapStatus("Tap the map to pin your store.");
                    },
                    { enableHighAccuracy: true, timeout: 8000 }
                );
            }
        }

        function initUserMap() {
            if (userMap) {
                return;
            }
            userMap = L.map("user-map").setView([0, 0], 2);
            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                attribution: "&copy; OpenStreetMap contributors"
            }).addTo(userMap);

            userMap.on("click", (event) => {
                setUserMarker(event.latlng);
                reverseGeocodeUserAddress(event.latlng.lat, event.latlng.lng);
            });

            if (userLatInput.value && userLngInput.value) {
                setUserMarker({ lat: Number(userLatInput.value), lng: Number(userLngInput.value) });
                userMap.setView([Number(userLatInput.value), Number(userLngInput.value)], 15);
                return;
            }

            if ("geolocation" in navigator) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const coords = [position.coords.latitude, position.coords.longitude];
                        userMap.setView(coords, 14);
                        setUserMapStatus("Tap the map to pin your delivery location.");
                    },
                    () => {
                        setUserMapStatus("Tap the map to pin your delivery location.");
                    },
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

            if (storeMapWrap) {
                storeMapWrap.hidden = !isStore;
            }
            if (userMapWrap) {
                userMapWrap.hidden = isStore || isDriver;
            }
            if (brandCopy) {
                brandCopy.hidden = true;
            }

            if (isStore) {
                initStoreMap();
                if (storeMap) {
                    setTimeout(() => storeMap.invalidateSize(), 0);
                }
            } else if (!isDriver) {
                initUserMap();
                if (userMap) {
                    setTimeout(() => userMap.invalidateSize(), 0);
                }
            }
        }

        accountRadios.forEach((radio) => {
            radio.addEventListener("change", toggleAccountMode);
        });

        toggleAccountMode();

        /* ── Current Location Geolocation Button Handler ── */
        function fetchCurrentLocation() {
            if (!("geolocation" in navigator)) {
                setUserMapStatus("Geolocation is not supported by your browser.");
                return;
            }

            const btnMap = document.getElementById("use-current-location");
            const btnField = document.getElementById("use-current-location-field-btn");

            if (btnMap) { btnMap.disabled = true; btnMap.textContent = "Locating…"; }
            if (btnField) { btnField.disabled = true; btnField.textContent = "Locating…"; }
            setUserMapStatus("Getting your current location…");

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    initUserMap();
                    if (typeof setUserMarker === "function" && userMap) {
                        setUserMarker({ lat, lng });
                        userMap.setView([lat, lng], 15);
                    }
                    reverseGeocodeUserAddress(lat, lng);
                    if (btnMap) { btnMap.disabled = false; btnMap.textContent = "📍 Use my current location"; }
                    if (btnField) { btnField.disabled = false; btnField.textContent = "📍 Use current location"; }
                },
                () => {
                    setUserMapStatus("Unable to access current location. Please check browser permissions.");
                    if (btnMap) { btnMap.disabled = false; btnMap.textContent = "📍 Use my current location"; }
                    if (btnField) { btnField.disabled = false; btnField.textContent = "📍 Use current location"; }
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }

        const mapLocBtn = document.getElementById("use-current-location");
        const fieldLocBtn = document.getElementById("use-current-location-field-btn");
        if (mapLocBtn) mapLocBtn.addEventListener("click", fetchCurrentLocation);
        if (fieldLocBtn) fieldLocBtn.addEventListener("click", fetchCurrentLocation);

        /* ── Address Autofill (Nominatim) for user delivery address ── */
        (function () {
            const addrInput   = document.getElementById("user_address");
            const addrSugBox  = document.getElementById("user-addr-suggestions");
            const addrSpinner = document.getElementById("user-addr-spinner");
            const addrLatInp  = document.getElementById("user_lat");
            const addrLngInp  = document.getElementById("user_lng");

            if (!addrInput || !addrSugBox) return;

            // Move dropdown to <body> to escape CSS Grid stacking context
            document.body.appendChild(addrSugBox);

            let debounceTimer  = null;
            let activeIndex    = -1;
            let currentResults = [];

            function positionDropdown() {
                const rect = addrInput.getBoundingClientRect();
                addrSugBox.style.top   = (rect.bottom + 4) + "px";
                addrSugBox.style.left  = rect.left + "px";
                addrSugBox.style.width = rect.width + "px";
            }

            function showSpinner(show) {
                addrSpinner && addrSpinner.classList.toggle("visible", show);
            }

            function closeSuggestions() {
                addrSugBox.classList.remove("open");
                addrSugBox.innerHTML = "";
                activeIndex    = -1;
                currentResults = [];
            }

            function openSuggestions(results) {
                addrSugBox.innerHTML = "";
                currentResults = results;
                activeIndex    = -1;
                if (!results.length) {
                    const empty = document.createElement("div");
                    empty.className = "address-suggestion-item";
                    empty.innerHTML = `<span class="sug-icon">⚠</span><span class="sug-text"><strong>No results found</strong><span>Try a more specific address</span></span>`;
                    addrSugBox.appendChild(empty);
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
                        addrSugBox.appendChild(item);
                    });
                }
                positionDropdown();
                addrSugBox.classList.add("open");
            }

            function highlightItem(index) {
                const items = addrSugBox.querySelectorAll(".address-suggestion-item");
                items.forEach((el, i) => el.classList.toggle("active", i === index));
            }

            function selectResult(index) {
                const r = currentResults[index];
                if (!r) return;
                addrInput.value = r.display_name;
                const lat = parseFloat(r.lat);
                const lng = parseFloat(r.lon);
                if (addrLatInp) addrLatInp.value = lat.toFixed(6);
                if (addrLngInp) addrLngInp.value = lng.toFixed(6);
                // ensure map is ready, then move marker
                if (typeof initUserMap === "function") initUserMap();
                if (typeof setUserMarker === "function" && userMap) {
                    setUserMarker({ lat, lng });
                    userMap.setView([lat, lng], 15);
                    setUserMapStatus(`Pinned at ${lat.toFixed(6)}, ${lng.toFixed(6)}.`);
                }
                closeSuggestions();
                addrInput.focus();
            }

            async function fetchSuggestions(query) {
                showSpinner(true);
                try {
                    const url = `https://nominatim.openstreetmap.org/search?format=jsonv2&q=${encodeURIComponent(query)}&limit=6&addressdetails=0`;
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

            addrInput.addEventListener("input", () => {
                clearTimeout(debounceTimer);
                const val = addrInput.value.trim();
                if (val.length < 3) {
                    closeSuggestions();
                    showSpinner(false);
                    return;
                }
                showSpinner(true);
                debounceTimer = setTimeout(() => fetchSuggestions(val), 400);
            });

            addrInput.addEventListener("keydown", (e) => {
                const items = addrSugBox.querySelectorAll(".address-suggestion-item[data-index]");
                if (!addrSugBox.classList.contains("open") || !items.length) return;
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

            addrInput.addEventListener("blur", () => {
                setTimeout(closeSuggestions, 180);
            });

            // Keep dropdown aligned on scroll or resize
            window.addEventListener("scroll", () => {
                if (addrSugBox.classList.contains("open")) positionDropdown();
            }, true);
            window.addEventListener("resize", () => {
                if (addrSugBox.classList.contains("open")) positionDropdown();
            });
        })();
    </script>
</body>
</html>
