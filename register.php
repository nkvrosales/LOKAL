<?php
require_once "auth.php";
require_once "db.php";

if (is_logged_in()) {
    header("Location: home.php");
    exit;
}

$errors = [];
$values = [
    "account_type" => "user",
    "first_name" => "",
    "middle_name" => "",
    "last_name" => "",
    "contact" => "",
    "email" => "",
    "user_address" => "",
    "user_lat" => "",
    "user_lng" => "",
    "store_address" => "",
    "store_lat" => "",
    "store_lng" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $values["account_type"] = strtolower(trim($_POST["account_type"] ?? "user"));
    $values["first_name"] = trim($_POST["first_name"] ?? "");
    $values["middle_name"] = trim($_POST["middle_name"] ?? "");
    $values["last_name"] = trim($_POST["last_name"] ?? "");
    $values["contact"] = trim($_POST["contact"] ?? "");
    $values["email"] = trim($_POST["email"] ?? "");
    $values["user_address"] = trim($_POST["user_address"] ?? "");
    $values["user_lat"] = trim($_POST["user_lat"] ?? "");
    $values["user_lng"] = trim($_POST["user_lng"] ?? "");
    $values["store_address"] = trim($_POST["store_address"] ?? "");
    $values["store_lat"] = trim($_POST["store_lat"] ?? "");
    $values["store_lng"] = trim($_POST["store_lng"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (!in_array($values["account_type"], ["user", "store"], true)) {
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
        if ($values["store_address"] === "") {
            $errors[] = "Store address is required.";
        }
        if ($values["store_lat"] === "" || $values["store_lng"] === "") {
            $errors[] = "Pin your store location on the map.";
        } elseif (!is_numeric($values["store_lat"]) || !is_numeric($values["store_lng"])) {
            $errors[] = "Store location coordinates are invalid.";
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
            "INSERT INTO users (account_type, first_name, middle_name, last_name, contact, email, password_hash, user_address, user_lat, user_lng, store_address, store_lat, store_lng)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $user_address = $values["account_type"] === "user" ? $values["user_address"] : null;
            $user_lat = $values["account_type"] === "user" ? $values["user_lat"] : null;
            $user_lng = $values["account_type"] === "user" ? $values["user_lng"] : null;
            $store_address = $values["account_type"] === "store" ? $values["store_address"] : null;
            $store_lat = $values["account_type"] === "store" ? $values["store_lat"] : null;
            $store_lng = $values["account_type"] === "store" ? $values["store_lng"] : null;
            $stmt->bind_param(
                "sssssssssssss",
                $values["account_type"],
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
                $store_lng
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
                <form method="post" class="form-stack">
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
                        </div>
                    </div>
                    <div class="field store-only" data-store-only>
                        <label for="store_address">Store address</label>
                        <input type="text" id="store_address" name="store_address" value="<?php echo escape($values["store_address"]); ?>" placeholder="Street, city, province" autocomplete="street-address">
                        <input type="hidden" id="store_lat" name="store_lat" value="<?php echo escape($values["store_lat"]); ?>">
                        <input type="hidden" id="store_lng" name="store_lng" value="<?php echo escape($values["store_lng"]); ?>">
                    </div>
                    <div class="field user-only" data-user-only>
                        <label for="user_address">Delivery address</label>
                        <input type="text" id="user_address" name="user_address" value="<?php echo escape($values["user_address"]); ?>" placeholder="Street, city, province" autocomplete="street-address">
                        <input type="hidden" id="user_lat" name="user_lat" value="<?php echo escape($values["user_lat"]); ?>">
                        <input type="hidden" id="user_lng" name="user_lng" value="<?php echo escape($values["user_lng"]); ?>">
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
        const storeMapWrap = document.getElementById("store-map-wrap");
        const userMapWrap = document.getElementById("user-map-wrap");
        const brandCopy = document.getElementById("brand-copy");
        const storeAddressInput = document.getElementById("store_address");
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

        function toggleStoreMode() {
            const isStore = document.querySelector('input[name="account_type"]:checked')?.value === "store";
            storeOnlyFields.forEach((field) => {
                field.hidden = !isStore;
            });
            userOnlyFields.forEach((field) => {
                field.hidden = isStore;
            });
            if (storeAddressInput) {
                storeAddressInput.required = isStore;
                storeAddressInput.disabled = !isStore;
            }
            if (userAddressInput) {
                userAddressInput.required = !isStore;
                userAddressInput.disabled = isStore;
            }
            if (storeLatInput && storeLngInput) {
                storeLatInput.disabled = !isStore;
                storeLngInput.disabled = !isStore;
            }
            if (userLatInput && userLngInput) {
                userLatInput.disabled = isStore;
                userLngInput.disabled = isStore;
            }
            if (storeMapWrap) {
                storeMapWrap.hidden = !isStore;
            }
            if (userMapWrap) {
                userMapWrap.hidden = isStore;
            }
            if (brandCopy) {
                brandCopy.hidden = true;
            }
            if (isStore) {
                initStoreMap();
                if (storeMap) {
                    setTimeout(() => storeMap.invalidateSize(), 0);
                }
            } else {
                initUserMap();
                if (userMap) {
                    setTimeout(() => userMap.invalidateSize(), 0);
                }
            }
        }

        accountRadios.forEach((radio) => {
            radio.addEventListener("change", toggleStoreMode);
        });

        toggleStoreMode();
    </script>
</body>
</html>
