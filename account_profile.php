<?php
require_once "auth.php";
require_once "db.php";
require_login();

$userId = (int) ($_SESSION["user_id"] ?? 0);
$accountType = $_SESSION["account_type"] ?? "";
if ($accountType === "admin") {
    header("Location: admin/profile.php");
    exit;
}
$isStore = $accountType === "store";
$errors = [];
$notice = "";

$profile = [
    "first_name" => "",
    "middle_name" => "",
    "last_name" => "",
    "contact" => "",
    "email" => "",
    "user_address" => "",
    "user_lat" => "",
    "user_lng" => "",
    "store_name" => "",
    "store_contact" => "",
    "store_address" => "",
    "store_lat" => "",
    "store_lng" => "",
];

function load_account_profile(mysqli $mysqli, int $userId): array
{
    $profile = [
        "first_name" => "",
        "middle_name" => "",
        "last_name" => "",
        "contact" => "",
        "email" => "",
        "user_address" => "",
        "user_lat" => "",
        "user_lng" => "",
        "store_name" => "",
        "store_contact" => "",
        "store_address" => "",
        "store_lat" => "",
        "store_lng" => "",
    ];

    $stmt = $mysqli->prepare(
        "SELECT first_name, middle_name, last_name, contact, email,
                user_address, user_lat, user_lng,
                store_name, store_contact, store_address, store_lat, store_lng
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return $profile;
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result(
        $firstName,
        $middleName,
        $lastName,
        $contact,
        $email,
        $userAddress,
        $userLat,
        $userLng,
        $storeName,
        $storeContact,
        $storeAddress,
        $storeLat,
        $storeLng
    );
    if ($stmt->fetch()) {
        $profile = [
            "first_name" => trim((string) ($firstName ?? "")),
            "middle_name" => trim((string) ($middleName ?? "")),
            "last_name" => trim((string) ($lastName ?? "")),
            "contact" => trim((string) ($contact ?? "")),
            "email" => trim((string) ($email ?? "")),
            "user_address" => trim((string) ($userAddress ?? "")),
            "user_lat" => $userLat !== null ? (string) $userLat : "",
            "user_lng" => $userLng !== null ? (string) $userLng : "",
            "store_name" => trim((string) ($storeName ?? "")),
            "store_contact" => trim((string) ($storeContact ?? "")),
            "store_address" => trim((string) ($storeAddress ?? "")),
            "store_lat" => $storeLat !== null ? (string) $storeLat : "",
            "store_lng" => $storeLng !== null ? (string) $storeLng : "",
        ];
    }
    $stmt->close();

    return $profile;
}

$profile = load_account_profile($mysqli, $userId);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach (["first_name", "middle_name", "last_name", "contact", "email"] as $field) {
        $profile[$field] = trim($_POST[$field] ?? "");
    }

    $currentPassword = $_POST["current_password"] ?? "";
    $newPassword = $_POST["new_password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    $shouldChangePassword = $newPassword !== "" || $confirmPassword !== "";

    if ($isStore) {
        foreach (["store_name", "store_contact", "store_address", "store_lat", "store_lng"] as $field) {
            $profile[$field] = trim($_POST[$field] ?? "");
        }
    } else {
        foreach (["user_address", "user_lat", "user_lng"] as $field) {
            $profile[$field] = trim($_POST[$field] ?? "");
        }
    }

    if ($profile["first_name"] === "" || $profile["middle_name"] === "" || $profile["last_name"] === "") {
        $errors[] = "Complete name is required.";
    }
    if ($profile["contact"] === "") {
        $errors[] = "Contact is required.";
    }
    if (!filter_var($profile["email"], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email is required.";
    }
    if ($currentPassword === "") {
        $errors[] = "Current password is required to save changes.";
    }
    if ($shouldChangePassword && strlen($newPassword) < 6) {
        $errors[] = "New password must be at least 6 characters.";
    }
    if ($shouldChangePassword && $newPassword !== $confirmPassword) {
        $errors[] = "New passwords do not match.";
    }

    if ($isStore) {
        if ($profile["store_name"] === "" || $profile["store_contact"] === "" || $profile["store_address"] === "") {
            $errors[] = "Store name, contact, and address are required.";
        }
        if ($profile["store_lat"] === "" || $profile["store_lng"] === "" || !is_numeric($profile["store_lat"]) || !is_numeric($profile["store_lng"])) {
            $errors[] = "Pin a valid store location on the map.";
        }
    } else {
        if ($profile["user_address"] === "") {
            $errors[] = "Delivery address is required.";
        }
        if ($profile["user_lat"] === "" || $profile["user_lng"] === "" || !is_numeric($profile["user_lat"]) || !is_numeric($profile["user_lng"])) {
            $errors[] = "Pin a valid delivery location on the map.";
        }
    }

    if (!$errors) {
        $stmt = $mysqli->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->bind_result($currentHash);
            $found = $stmt->fetch();
            $stmt->close();

            if (!$found || !password_verify($currentPassword, (string) $currentHash)) {
                $errors[] = "Current password is incorrect.";
            }
        } else {
            $errors[] = "Unable to verify current password.";
        }
    }

    if (!$errors) {
        $check = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        if ($check) {
            $check->bind_param("si", $profile["email"], $userId);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $errors[] = "Email is already used by another account.";
            }
            $check->close();
        }
    }

    if (!$errors) {
        $passwordSql = "";
        $newHash = "";
        if ($shouldChangePassword) {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $passwordSql = ", password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL";
        }

        if ($isStore) {
            $lat = (float) $profile["store_lat"];
            $lng = (float) $profile["store_lng"];
            $stmt = $mysqli->prepare(
                "UPDATE users
                 SET first_name = ?, middle_name = ?, last_name = ?, contact = ?, email = ?,
                     store_name = ?, store_contact = ?, store_address = ?, store_lat = ?, store_lng = ?
                     {$passwordSql}
                 WHERE id = ?"
            );
            if ($stmt) {
                if ($shouldChangePassword) {
                    $stmt->bind_param(
                        "ssssssssddsi",
                        $profile["first_name"],
                        $profile["middle_name"],
                        $profile["last_name"],
                        $profile["contact"],
                        $profile["email"],
                        $profile["store_name"],
                        $profile["store_contact"],
                        $profile["store_address"],
                        $lat,
                        $lng,
                        $newHash,
                        $userId
                    );
                } else {
                    $stmt->bind_param(
                        "ssssssssddi",
                        $profile["first_name"],
                        $profile["middle_name"],
                        $profile["last_name"],
                        $profile["contact"],
                        $profile["email"],
                        $profile["store_name"],
                        $profile["store_contact"],
                        $profile["store_address"],
                        $lat,
                        $lng,
                        $userId
                    );
                }
            }
        } else {
            $lat = (float) $profile["user_lat"];
            $lng = (float) $profile["user_lng"];
            $stmt = $mysqli->prepare(
                "UPDATE users
                 SET first_name = ?, middle_name = ?, last_name = ?, contact = ?, email = ?,
                     user_address = ?, user_lat = ?, user_lng = ?
                     {$passwordSql}
                 WHERE id = ?"
            );
            if ($stmt) {
                if ($shouldChangePassword) {
                    $stmt->bind_param(
                        "ssssssddsi",
                        $profile["first_name"],
                        $profile["middle_name"],
                        $profile["last_name"],
                        $profile["contact"],
                        $profile["email"],
                        $profile["user_address"],
                        $lat,
                        $lng,
                        $newHash,
                        $userId
                    );
                } else {
                    $stmt->bind_param(
                        "ssssssddi",
                        $profile["first_name"],
                        $profile["middle_name"],
                        $profile["last_name"],
                        $profile["contact"],
                        $profile["email"],
                        $profile["user_address"],
                        $lat,
                        $lng,
                        $userId
                    );
                }
            }
        }

        if (isset($stmt) && $stmt && $stmt->execute()) {
            $_SESSION["user_name"] = trim($profile["first_name"] . " " . $profile["last_name"]);
            $notice = $shouldChangePassword ? "Profile and password updated." : "Profile updated.";
            $profile = load_account_profile($mysqli, $userId);
        } else {
            $errors[] = "Unable to update profile. Please try again.";
        }
        if (isset($stmt) && $stmt) {
            $stmt->close();
        }
    }
}

$mapLat = $isStore ? $profile["store_lat"] : $profile["user_lat"];
$mapLng = $isStore ? $profile["store_lng"] : $profile["user_lng"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Profile | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/store-admin.css?v=hover-effects-1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
</head>
<body class="store-admin-body">
    <header class="top-bar">
        <a class="logo" href="home.php" style="text-decoration:none">Lokal</a>
        <nav class="store-admin-nav" aria-label="Account pages">
            <a class="store-admin-tab" href="home.php">Home</a>
            <a class="store-admin-tab active" href="account_profile.php">Profile</a>
            <a class="store-admin-tab" href="order_history.php">Orders</a>
            <?php if ($isStore): ?>
                <a class="store-admin-tab" href="store_products.php">Product</a>
            <?php else: ?>
                <a class="store-admin-tab" href="cart.php">Cart</a>
            <?php endif; ?>
            <a class="store-admin-tab" href="logout.php">Log out</a>
        </nav>
    </header>

    <main class="store-admin-shell">
        <section class="store-admin-card">
            <h1><?php echo $isStore ? "Store Profile" : "User Profile"; ?></h1>
            <p class="status-text">Update your account details and saved map location. Enter your current password to save changes. New password is optional.</p>

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

            <form method="post" class="form-stack">
                <div class="split">
                    <div class="field">
                        <label for="first_name">First name</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo escape($profile["first_name"]); ?>" required>
                    </div>
                    <div class="field">
                        <label for="middle_name">Middle name</label>
                        <input type="text" id="middle_name" name="middle_name" value="<?php echo escape($profile["middle_name"]); ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo escape($profile["last_name"]); ?>" required>
                </div>
                <div class="field">
                    <label for="contact">Contact</label>
                    <input type="text" id="contact" name="contact" value="<?php echo escape($profile["contact"]); ?>" required>
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo escape($profile["email"]); ?>" required>
                </div>

                <?php if ($isStore): ?>
                    <div class="field">
                        <label for="store_name">Store name</label>
                        <input type="text" id="store_name" name="store_name" value="<?php echo escape($profile["store_name"]); ?>" required>
                    </div>
                    <div class="field">
                        <label for="store_contact">Store contact</label>
                        <input type="text" id="store_contact" name="store_contact" value="<?php echo escape($profile["store_contact"]); ?>" required>
                    </div>
                    <div class="field">
                        <label for="store_address">Store address</label>
                        <input type="text" id="store_address" name="store_address" value="<?php echo escape($profile["store_address"]); ?>" required>
                    </div>
                    <input type="hidden" id="map_lat" name="store_lat" value="<?php echo escape($profile["store_lat"]); ?>">
                    <input type="hidden" id="map_lng" name="store_lng" value="<?php echo escape($profile["store_lng"]); ?>">
                <?php else: ?>
                    <div class="field">
                        <label for="user_address">Delivery address</label>
                        <input type="text" id="user_address" name="user_address" value="<?php echo escape($profile["user_address"]); ?>" required>
                    </div>
                    <input type="hidden" id="map_lat" name="user_lat" value="<?php echo escape($profile["user_lat"]); ?>">
                    <input type="hidden" id="map_lng" name="user_lng" value="<?php echo escape($profile["user_lng"]); ?>">
                <?php endif; ?>

                <div class="profile-map-actions">
                    <button class="profile-location-btn" id="use-current-location" type="button">Use my current location</button>
                </div>
                <div id="profile-map"></div>
                <p class="store-pin-status" id="pin-status">
                    <?php if ($mapLat !== "" && $mapLng !== ""): ?>
                        Pinned at <?php echo escape($mapLat); ?>, <?php echo escape($mapLng); ?>.
                    <?php else: ?>
                        No location pinned yet. Tap on the map to set it.
                    <?php endif; ?>
                </p>

                <h2>Security</h2>
                <div class="field">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="split">
                    <div class="field">
                        <label for="new_password">New password</label>
                        <input type="password" id="new_password" name="new_password" placeholder="Leave blank to keep current password">
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm new password</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Leave blank to keep current password">
                    </div>
                </div>

                <button class="btn" type="submit">Save Changes</button>
            </form>
        </section>
    </main>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const map = L.map("profile-map", { zoomControl: true }).setView([14.5995, 120.9842], 12);
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "&copy; OpenStreetMap contributors"
        }).addTo(map);

        const latInput = document.getElementById("map_lat");
        const lngInput = document.getElementById("map_lng");
        const pinStatus = document.getElementById("pin-status");
        const locationButton = document.getElementById("use-current-location");
        const addressInput = document.getElementById(<?php echo $isStore ? '"store_address"' : '"user_address"'; ?>);
        let marker = null;

        function setPin(lat, lng, centerMap = false) {
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                return;
            }
            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.circleMarker([lat, lng], {
                    radius: 10,
                    color: "#a80000",
                    weight: 2,
                    fillColor: "#f0c95d",
                    fillOpacity: 0.95
                }).addTo(map);
            }
            latInput.value = lat.toFixed(6);
            lngInput.value = lng.toFixed(6);
            pinStatus.textContent = `Pinned at ${latInput.value}, ${lngInput.value}.`;
            if (centerMap) {
                map.setView([lat, lng], 15);
            }
        }

        async function suggestAddressFromPin(lat, lng) {
            if (!addressInput) {
                return;
            }

            try {
                const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=18&addressdetails=0`, {
                    cache: "force-cache"
                });
                if (!response.ok) {
                    return;
                }
                const data = await response.json();
                if (data && data.display_name) {
                    addressInput.value = data.display_name;
                }
            } catch (error) {
                // Keep the pinned coordinates even if address lookup is unavailable.
            }
        }

        function pinCurrentLocation() {
            if (!("geolocation" in navigator)) {
                pinStatus.textContent = "Location access is not available in this browser.";
                return;
            }

            if (locationButton) {
                locationButton.disabled = true;
                locationButton.textContent = "Locating...";
            }
            pinStatus.textContent = "Getting your current location...";

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    setPin(lat, lng, true);
                    pinStatus.textContent = `Pinned from your current location at ${latInput.value}, ${lngInput.value}.`;
                    suggestAddressFromPin(lat, lng);
                    if (locationButton) {
                        locationButton.disabled = false;
                        locationButton.textContent = "Use my current location";
                    }
                },
                () => {
                    pinStatus.textContent = "Unable to access your current location. Allow location permission and try again.";
                    if (locationButton) {
                        locationButton.disabled = false;
                        locationButton.textContent = "Use my current location";
                    }
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }

        const existingLat = Number(latInput.value);
        const existingLng = Number(lngInput.value);
        if (Number.isFinite(existingLat) && Number.isFinite(existingLng)) {
            setPin(existingLat, existingLng, true);
        } else if ("geolocation" in navigator) {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    map.setView([position.coords.latitude, position.coords.longitude], 14);
                    pinStatus.textContent = "Current location found. Click Use my current location to pin it.";
                },
                () => map.setView([14.5995, 120.9842], 12),
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }

        map.on("click", (event) => {
            setPin(event.latlng.lat, event.latlng.lng, false);
            suggestAddressFromPin(event.latlng.lat, event.latlng.lng);
        });

        if (locationButton) {
            locationButton.addEventListener("click", pinCurrentLocation);
        }
    </script>
</body>
</html>
