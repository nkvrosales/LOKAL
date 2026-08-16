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
$isDriver = $accountType === "driver";
$errors = [];
$notice = "";

$profile = [
    "first_name"            => "",
    "middle_name"           => "",
    "last_name"             => "",
    "contact"               => "",
    "email"                 => "",
    "user_address"          => "",
    "user_lat"              => "",
    "user_lng"              => "",
    "store_name"            => "",
    "store_contact"         => "",
    "store_address"         => "",
    "store_lat"             => "",
    "store_lng"             => "",
    "store_category"        => "",
    "vehicle_registration"  => "",
    "id_image"              => "",
    "orcr_image"            => "",
    "profile_image"         => "",
    "is_approved"           => 0,
    "created_at"            => "",
];

function load_account_profile(mysqli $mysqli, int $userId): array
{
    $profile = [
        "first_name"            => "",
        "middle_name"           => "",
        "last_name"             => "",
        "contact"               => "",
        "email"                 => "",
        "user_address"          => "",
        "user_lat"              => "",
        "user_lng"              => "",
        "store_name"            => "",
        "store_contact"         => "",
        "store_address"         => "",
        "store_lat"             => "",
        "store_lng"             => "",
        "store_category"        => "",
        "vehicle_registration"  => "",
        "id_image"              => "",
        "orcr_image"            => "",
        "profile_image"         => "",
        "is_approved"           => 0,
        "created_at"            => "",
    ];

    $stmt = $mysqli->prepare(
        "SELECT first_name, middle_name, last_name, contact, email,
                user_address, user_lat, user_lng,
                store_name, store_contact, store_address, store_lat, store_lng, store_category,
                vehicle_registration, id_image, orcr_image, profile_image, is_approved, created_at
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
        $storeLng,
        $storeCategory,
        $vehicleReg,
        $idImage,
        $orcrImage,
        $profileImage,
        $isApproved,
        $createdAt
    );
    if ($stmt->fetch()) {
        $profile = [
            "first_name"            => trim((string) ($firstName ?? "")),
            "middle_name"           => trim((string) ($middleName ?? "")),
            "last_name"             => trim((string) ($lastName ?? "")),
            "contact"               => trim((string) ($contact ?? "")),
            "email"                 => trim((string) ($email ?? "")),
            "user_address"          => trim((string) ($userAddress ?? "")),
            "user_lat"              => $userLat !== null ? (string) $userLat : "",
            "user_lng"              => $userLng !== null ? (string) $userLng : "",
            "store_name"            => trim((string) ($storeName ?? "")),
            "store_contact"         => trim((string) ($storeContact ?? "")),
            "store_address"         => trim((string) ($storeAddress ?? "")),
            "store_lat"             => $storeLat !== null ? (string) $storeLat : "",
            "store_lng"             => $storeLng !== null ? (string) $storeLng : "",
            "store_category"        => trim((string) ($storeCategory ?? "")),
            "vehicle_registration"  => trim((string) ($vehicleReg ?? "")),
            "id_image"              => trim((string) ($idImage ?? "")),
            "orcr_image"            => trim((string) ($orcrImage ?? "")),
            "profile_image"         => trim((string) ($profileImage ?? "")),
            "is_approved"           => (int) ($isApproved ?? 0),
            "created_at"            => (string) ($createdAt ?? ""),
        ];
    }
    $stmt->close();

    return $profile;
}

$profile = load_account_profile($mysqli, $userId);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // â”€â”€ Handle Account Deletion â”€â”€
    if (isset($_POST["delete_account_submit"])) {
        $delPassword = $_POST["delete_confirm_password"] ?? "";
        if ($delPassword === "") {
            $errors[] = "Password is required to delete your account.";
        } else {
            $stmt = $mysqli->prepare("SELECT password_hash, profile_image, id_image, orcr_image, account_type FROM users WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $stmt->bind_result($curHash, $pImg, $idImg, $orcrImg, $accType);
                if ($stmt->fetch() && password_verify($delPassword, (string) $curHash)) {
                    $stmt->close();

                    // Delete user files
                    if (!empty($pImg) && file_exists(__DIR__ . "/uploads/profiles/" . $pImg)) {
                        @unlink(__DIR__ . "/uploads/profiles/" . $pImg);
                    }
                    if (!empty($idImg) && file_exists(__DIR__ . "/uploads/ids/" . $idImg)) {
                        @unlink(__DIR__ . "/uploads/ids/" . $idImg);
                    }
                    if (!empty($orcrImg) && file_exists(__DIR__ . "/uploads/orcr/" . $orcrImg)) {
                        @unlink(__DIR__ . "/uploads/orcr/" . $orcrImg);
                    }

                    // If store, remove products and images
                    if ($accType === "store") {
                        $pStmt = $mysqli->prepare("SELECT product_image FROM store_products WHERE store_user_id = ?");
                        if ($pStmt) {
                            $pStmt->bind_param("i", $userId);
                            $pStmt->execute();
                            $pStmt->bind_result($prodImg);
                            while ($pStmt->fetch()) {
                                if (!empty($prodImg) && file_exists(__DIR__ . "/uploads/products/" . $prodImg)) {
                                    @unlink(__DIR__ . "/uploads/products/" . $prodImg);
                                }
                            }
                            $pStmt->close();
                        }
                        $delP = $mysqli->prepare("DELETE FROM store_products WHERE store_user_id = ?");
                        if ($delP) {
                            $delP->bind_param("i", $userId);
                            $delP->execute();
                            $delP->close();
                        }
                    }

                    // Delete order items and orders
                    $delOi = $mysqli->prepare("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_user_id = ? OR store_user_id = ?)");
                    if ($delOi) {
                        $delOi->bind_param("ii", $userId, $userId);
                        $delOi->execute();
                        $delOi->close();
                    }

                    $delOrd = $mysqli->prepare("DELETE FROM orders WHERE customer_user_id = ? OR store_user_id = ?");
                    if ($delOrd) {
                        $delOrd->bind_param("ii", $userId, $userId);
                        $delOrd->execute();
                        $delOrd->close();
                    }

                    // Delete user record
                    $delU = $mysqli->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
                    if ($delU) {
                        $delU->bind_param("i", $userId);
                        $delU->execute();
                        $delU->close();
                    }

                    // Destroy session and redirect to login
                    $_SESSION = [];
                    if (ini_get("session.use_cookies")) {
                        $params = session_get_cookie_params();
                        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
                    }
                    session_destroy();

                    header("Location: login.php?deleted=1");
                    exit;
                } else {
                    $stmt->close();
                    $errors[] = "Incorrect password. Account deletion cancelled.";
                }
            } else {
                $errors[] = "Unable to process account deletion.";
            }
        }
    } else {
        foreach (["first_name", "middle_name", "last_name", "contact", "email"] as $field) {
            $profile[$field] = trim($_POST[$field] ?? "");
        }

        $currentPassword = $_POST["current_password"] ?? "";
        $newPassword = $_POST["new_password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";
        $shouldChangePassword = $newPassword !== "" || $confirmPassword !== "";

        if ($isStore) {
            foreach (["store_name", "store_contact", "store_address", "store_lat", "store_lng", "store_category"] as $field) {
                $profile[$field] = trim($_POST[$field] ?? "");
            }
    } elseif ($isDriver) {
        $profile["vehicle_registration"] = trim($_POST["vehicle_registration"] ?? "");
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
    } elseif ($isDriver) {
        if ($profile["vehicle_registration"] === "") {
            $errors[] = "Vehicle registration details are required.";
        }
    } else {
        if ($profile["user_address"] === "") {
            $errors[] = "Delivery address is required.";
        }
        if ($profile["user_lat"] === "" || $profile["user_lng"] === "" || !is_numeric($profile["user_lat"]) || !is_numeric($profile["user_lng"])) {
            $errors[] = "Pin a valid delivery location on the map.";
        }
    }

    // Handle Profile Photo Upload (Riders and Users)
    $newProfileImage = $profile["profile_image"];
    $allowedExts = ["jpg", "jpeg", "png", "webp"];

    if (isset($_FILES["profile_image"]) && is_uploaded_file($_FILES["profile_image"]["tmp_name"])) {
        $origName = $_FILES["profile_image"]["name"];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            $errors[] = "Profile photo must be JPG, PNG, or WEBP.";
        } else {
            $uploadsDirProfiles = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "profiles";
            if (!is_dir($uploadsDirProfiles)) @mkdir($uploadsDirProfiles, 0777, true);
            $newProfileFilename = bin2hex(random_bytes(8)) . "_" . time() . "." . $ext;
            $dest = $uploadsDirProfiles . DIRECTORY_SEPARATOR . $newProfileFilename;
            if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $dest)) {
                $newProfileImage = $newProfileFilename;
            }
        }
    }

    // Profile photo is strictly required for riders
    if ($isDriver && empty($newProfileImage)) {
        $errors[] = "Profile photo is required for delivery riders.";
    }

    // Handle Driver ID & ORCR File Uploads
    $newIdImage = $profile["id_image"];
    $newOrcrImage = $profile["orcr_image"];

    if ($isDriver && !$errors) {
        // Handle ID image
        if (isset($_FILES["id_image"]) && is_uploaded_file($_FILES["id_image"]["tmp_name"])) {
            $origName = $_FILES["id_image"]["name"];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) {
                $errors[] = "Valid ID image must be JPG, PNG, or WEBP.";
            } else {
                $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "ids";
                if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0777, true);
                $newIdFilename = bin2hex(random_bytes(8)) . "_" . time() . "." . $ext;
                $dest = $uploadsDir . DIRECTORY_SEPARATOR . $newIdFilename;
                if (move_uploaded_file($_FILES["id_image"]["tmp_name"], $dest)) {
                    $newIdImage = $newIdFilename;
                }
            }
        }

        // Handle ORCR image
        if (isset($_FILES["orcr_image"]) && is_uploaded_file($_FILES["orcr_image"]["tmp_name"])) {
            $origName2 = $_FILES["orcr_image"]["name"];
            $ext2 = strtolower(pathinfo($origName2, PATHINFO_EXTENSION));
            if (!in_array($ext2, $allowedExts, true)) {
                $errors[] = "OR/CR document image must be JPG, PNG, or WEBP.";
            } else {
                $uploadsDir2 = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "orcr";
                if (!is_dir($uploadsDir2)) @mkdir($uploadsDir2, 0777, true);
                $newOrcrFilename = bin2hex(random_bytes(8)) . "_" . time() . "." . $ext2;
                $dest2 = $uploadsDir2 . DIRECTORY_SEPARATOR . $newOrcrFilename;
                if (move_uploaded_file($_FILES["orcr_image"]["tmp_name"], $dest2)) {
                    $newOrcrImage = $newOrcrFilename;
                }
            }
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
            $storeCat = $profile["store_category"] !== "" ? $profile["store_category"] : null;
            $stmt = $mysqli->prepare(
                "UPDATE users
                 SET first_name = ?, middle_name = ?, last_name = ?, contact = ?, email = ?,
                     store_name = ?, store_contact = ?, store_address = ?, store_lat = ?, store_lng = ?,
                     store_category = ?, profile_image = ?
                     {$passwordSql}
                 WHERE id = ?"
            );
            if ($stmt) {
                if ($shouldChangePassword) {
                    $stmt->bind_param(
                        "ssssssssddssssi",
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
                        $storeCat,
                        $newProfileImage,
                        $newHash,
                        $userId
                    );
                } else {
                    $stmt->bind_param(
                        "ssssssssddssi",
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
                        $storeCat,
                        $newProfileImage,
                        $userId
                    );
                }
            }
        } elseif ($isDriver) {
            $stmt = $mysqli->prepare(
                "UPDATE users
                 SET first_name = ?, middle_name = ?, last_name = ?, contact = ?, email = ?,
                     vehicle_registration = ?, id_image = ?, orcr_image = ?, profile_image = ?
                     {$passwordSql}
                 WHERE id = ?"
            );
            if ($stmt) {
                if ($shouldChangePassword) {
                    $stmt->bind_param(
                        "ssssssssssi",
                        $profile["first_name"],
                        $profile["middle_name"],
                        $profile["last_name"],
                        $profile["contact"],
                        $profile["email"],
                        $profile["vehicle_registration"],
                        $newIdImage,
                        $newOrcrImage,
                        $newProfileImage,
                        $newHash,
                        $userId
                    );
                } else {
                    $stmt->bind_param(
                        "sssssssssi",
                        $profile["first_name"],
                        $profile["middle_name"],
                        $profile["last_name"],
                        $profile["contact"],
                        $profile["email"],
                        $profile["vehicle_registration"],
                        $newIdImage,
                        $newOrcrImage,
                        $newProfileImage,
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
                     user_address = ?, user_lat = ?, user_lng = ?, profile_image = ?
                     {$passwordSql}
                 WHERE id = ?"
            );
            if ($stmt) {
                if ($shouldChangePassword) {
                    $stmt->bind_param(
                        "ssssssddssi",
                        $profile["first_name"],
                        $profile["middle_name"],
                        $profile["last_name"],
                        $profile["contact"],
                        $profile["email"],
                        $profile["user_address"],
                        $lat,
                        $lng,
                        $newProfileImage,
                        $newHash,
                        $userId
                    );
                } else {
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
                        $newProfileImage,
                        $userId
                    );
                }
            }
        }

        if (isset($stmt) && $stmt && $stmt->execute()) {
            $_SESSION["user_name"] = trim($profile["first_name"] . " " . $profile["last_name"]);
            $_SESSION["profile_image"] = (string) $newProfileImage;
            $notice = $shouldChangePassword ? "Profile and password updated successfully." : "Profile updated successfully.";
            $profile = load_account_profile($mysqli, $userId);
        } else {
            $errors[] = "Unable to update profile. Please try again.";
        }
        if (isset($stmt) && $stmt) {
            $stmt->close();
        }
    }
}
}

$mapLat = $isStore ? $profile["store_lat"] : ($isDriver ? "" : $profile["user_lat"]);
$mapLng = $isStore ? $profile["store_lng"] : ($isDriver ? "" : $profile["user_lng"]);

// Fetch active categories for profile dropdown
$prof_categories = [];
if ($isStore) {
    $pcat = $mysqli->query("SELECT name, slug FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
    if ($pcat) {
        while ($pc = $pcat->fetch_assoc()) {
            $prof_categories[] = ["name" => $pc["name"], "slug" => $pc["slug"]];
        }
        $pcat->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Profile | Lokal</title>
    <link rel="stylesheet" href="assets/styles.css?v=primary-bw-icons-1">
    <link rel="stylesheet" href="assets/store-admin.css?v=mobile-responsive-profile-2">
    <?php if (!$isDriver): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <?php endif; ?>
    <style>
        .profile-photo-upload-row {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 16px;
            background: #F8FAFC;
            border: 1.5px solid #E2E8F0;
            border-radius: 14px;
            margin-bottom: 20px;
        }
        .profile-photo-preview-box {
            position: relative;
            width: 84px;
            height: 84px;
            border-radius: 50%;
            overflow: hidden;
            background: #FF5B2E;
            color: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            flex-shrink: 0;
            border: 3px solid #FFFFFF;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
        }
        .profile-photo-preview-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-photo-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
        }
        .profile-photo-title {
            font-size: 14px;
            font-weight: 700;
            color: #0F172A;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .photo-req-tag {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
        }
        .photo-req-tag.required {
            background: #FEE2E2;
            color: #DC2626;
        }
        .photo-req-tag.optional {
            background: #E2E8F0;
            color: #475569;
        }
        .driver-doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .driver-doc-card {
            background: #F8FAFC;
            border: 1.5px solid #E2E8F0;
            border-radius: 14px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .driver-doc-card label {
            font-weight: 700;
            font-size: 13.5px;
            color: #0F172A;
            margin: 0;
        }
        .driver-doc-thumb {
            width: 100%;
            height: 140px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #CBD5E1;
            background: #FFFFFF;
        }
        .driver-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 700;
            margin-bottom: 16px;
        }
        .driver-status-badge.approved {
            background: #D1FAE5;
            color: #047857;
            border: 1px solid #A7F3D0;
        }
        .driver-status-badge.pending {
            background: #FEF3C7;
            color: #B45309;
            border: 1px solid #FDE68A;
        }
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

        @media (max-width: 768px) {
            .top-bar {
                padding: 10px 14px;
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            .store-admin-nav {
                border-radius: 12px;
                padding: 3px;
                gap: 3px;
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                width: 100%;
                box-sizing: border-box;
            }
            .store-admin-nav::-webkit-scrollbar {
                display: none;
            }
            .store-admin-tab {
                white-space: nowrap;
                flex-shrink: 0;
                padding: 6px 12px;
                font-size: 12px;
            }
            .store-admin-shell {
                padding: 12px 10px;
                width: 100%;
                box-sizing: border-box;
            }
            .store-admin-card {
                padding: 16px 14px;
                border-radius: 16px;
                width: 100%;
                box-sizing: border-box;
                overflow: hidden;
            }
            .store-admin-card h1 {
                font-size: 22px;
            }
            .status-text {
                font-size: 13px;
                line-height: 1.4;
            }
            .profile-photo-upload-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
                padding: 12px;
            }
            .profile-photo-info {
                width: 100%;
                min-width: 0;
            }
            .profile-photo-info input[type="file"] {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
                font-size: 12px;
            }
            .split {
                grid-template-columns: 1fr !important;
                gap: 10px;
            }
            .field {
                width: 100%;
                min-width: 0;
                box-sizing: border-box;
            }
            .field input,
            .field select,
            .field textarea {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }
            .driver-doc-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .form-stack .btn {
                width: 100%;
            }
        }

        /* Danger Zone */
        .danger-zone-card {
            margin-top: 36px;
            border: 1.5px solid rgba(239, 68, 68, 0.25);
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(254, 242, 242, 0.5) 0%, rgba(254, 242, 242, 0.2) 100%);
            padding: 20px 22px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .danger-zone-header {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .danger-zone-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #FEE2E2;
            color: #DC2626;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .danger-zone-header h3 {
            margin: 0;
            color: #991B1B;
            font-size: 16px;
            font-weight: 700;
        }
        .danger-zone-header p {
            margin: 2px 0 0;
            color: #7F1D1D;
            font-size: 13px;
        }
        .danger-zone-body {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .danger-zone-body p {
            margin: 0;
            font-size: 13px;
            color: #64748B;
            line-height: 1.45;
            max-width: 500px;
        }
        .btn-danger-outline {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            border-radius: 12px;
            border: 1.5px solid rgba(239, 68, 68, 0.45);
            background: #FFFFFF;
            color: #DC2626;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .btn-danger-outline:hover {
            background: #DC2626;
            color: #FFFFFF;
            border-color: #DC2626;
            box-shadow: 0 4px 14px rgba(220, 38, 38, 0.25);
            transform: translateY(-1px);
        }

        /* Modal Styles */
        .pm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            backdrop-filter: blur(3px);
            z-index: 9000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            opacity: 0;
            pointer-events: none;
            transition: opacity .22s;
        }
        .pm-overlay.open { opacity: 1; pointer-events: all; }
        .pm-box {
            background: #fff;
            border-radius: 22px;
            box-shadow: 0 32px 80px rgba(0,0,0,.28);
            width: min(500px, 100%);
            max-height: 92vh;
            overflow-y: auto;
            transform: translateY(20px) scale(.97);
            transition: transform .25s cubic-bezier(.16,1,.3,1);
            padding: 28px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .pm-overlay.open .pm-box { transform: translateY(0) scale(1); }
        .pm-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .pm-close {
            width: 36px; height: 36px; border-radius: 10px; border: 1.5px solid #E2E8F0;
            background: #F8FAFC; cursor: pointer; display: flex; align-items: center;
            justify-content: center; font-size: 18px; color: #64748B; transition: all .15s;
        }
        .pm-close:hover { background: #FEE2E2; border-color: #FCA5A5; color: #DC2626; }
        .pm-actions { display: flex; gap: 10px; justify-content: flex-end; padding-top: 4px; }
        .pm-cancel {
            padding: 10px 20px; border-radius: 12px; border: 1.5px solid #E2E8F0;
            background: #F8FAFC; color: #475569; font: inherit; font-size: 13.5px;
            font-weight: 600; cursor: pointer; transition: all .15s;
        }
        .pm-cancel:hover { background: #F1F5F9; }
        .profile-map-wrapper {
            position: relative;
            width: 100%;
            margin-top: 14px;
            margin-bottom: 8px;
        }
        #profile-map {
            height: 300px;
            width: 100%;
            border-radius: 14px;
            overflow: hidden;
            border: 1.5px solid #E2E8F0;
            background: #e5e3df;
            z-index: 1;
        }
        .map-floating-gps-btn {
            position: absolute;
            bottom: 14px;
            right: 14px;
            z-index: 1000;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #FFFFFF;
            border: 1.5px solid #E2E8F0;
            color: #FF5B2E;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .map-floating-gps-btn:hover {
            transform: scale(1.08);
            background: #FFF5F2;
            border-color: #FF5B2E;
            box-shadow: 0 6px 18px rgba(255, 91, 46, 0.25);
        }
        .map-floating-gps-btn:active {
            transform: scale(0.95);
        }
    </style>
</head>
<body class="store-admin-body">
    <header class="top-bar">
        <a class="logo" href="<?php echo $isDriver ? 'driver_dashboard.php' : 'home.php'; ?>" style="text-decoration:none">
            <span style="color:var(--primary, #FF4D2E)">LOKAL</span>
        </a>
        <nav class="store-admin-nav" aria-label="Account pages">
            <?php if ($isDriver): ?>
                <a class="store-admin-tab" href="driver_dashboard.php">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6.5" cy="17.5" r="2.5"></circle><circle cx="17.5" cy="17.5" r="2.5"></circle><path d="M9 7H6"></path><path d="M8.5 10.5 11 17.5"></path><path d="M11 10.5h4l2.5 7"></path><path d="M10.5 10.5 14 7.5"></path></svg>
                    <span>Dashboard</span>
                </a>
                <a class="store-admin-tab active" href="account_profile.php">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>Profile</span>
                </a>
            <?php else: ?>
                <a class="store-admin-tab" href="home.php">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <span>Home</span>
                </a>
                <a class="store-admin-tab active" href="account_profile.php">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>Profile</span>
                </a>
                <a class="store-admin-tab" href="order_history.php">
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
            <?php endif; ?>
            <a class="store-admin-tab" href="logout.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Log out</span>
            </a>
        </nav>
    </header>

    <main class="store-admin-shell">
        <section class="store-admin-card">
            <h1><?php echo $isDriver ? "Driver Profile" : ($isStore ? "Store Profile" : "User Profile"); ?></h1>
            <p class="status-text"><?php echo $isDriver ? "Manage your delivery rider profile photo, vehicle info, identification documents, and security." : "Update your account details, profile photo, and saved map location."; ?></p>

            <?php if ($isDriver): ?>
                <?php if ((int)$profile["is_approved"] === 1): ?>
                    <div class="driver-status-badge approved">âœ“ Approved Delivery Rider</div>
                <?php else: ?>
                    <div class="driver-status-badge pending">â³ Account Pending Admin Approval</div>
                <?php endif; ?>
            <?php endif; ?>

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

            <form method="post" enctype="multipart/form-data" class="form-stack">
                <!-- Profile Photo Upload -->
                <div class="profile-photo-upload-row">
                    <div class="profile-photo-preview-box" id="avatar-preview-box">
                        <?php if (!empty($profile["profile_image"]) && file_exists(__DIR__ . "/uploads/profiles/" . $profile["profile_image"])): ?>
                            <img id="avatar-preview-img" src="uploads/profiles/<?php echo escape($profile["profile_image"]); ?>" alt="Profile Photo">
                        <?php else: ?>
                            <span id="avatar-preview-initial"><?php echo strtoupper(substr($profile["first_name"] ?: "U", 0, 1)); ?></span>
                            <img id="avatar-preview-img" src="" alt="Profile Photo" style="display:none;">
                        <?php endif; ?>
                    </div>
                    <div class="profile-photo-info">
                        <div class="profile-photo-title">
                            <span>Profile Photo</span>
                            <?php if ($isDriver): ?>
                                <span class="photo-req-tag required">*Required for Riders</span>
                            <?php else: ?>
                                <span class="photo-req-tag optional">(Optional)</span>
                            <?php endif; ?>
                        </div>
                        <input type="file" id="profile_image" name="profile_image" accept="image/*" onchange="previewProfilePhoto(event)">
                        <small style="color:#64748B;">Supported: JPG, PNG, WEBP. Max 5MB.</small>
                    </div>
                </div>

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
                    <label for="contact">Contact Number</label>
                    <input type="text" id="contact" name="contact" value="<?php echo escape($profile["contact"]); ?>" required>
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo escape($profile["email"]); ?>" required>
                </div>

                <?php if ($isDriver): ?>
                    <h2>Vehicle & Verification Documents</h2>
                    <div class="field">
                        <label for="vehicle_registration">Vehicle Registration / Plate Number</label>
                        <input type="text" id="vehicle_registration" name="vehicle_registration" value="<?php echo escape($profile["vehicle_registration"]); ?>" placeholder="e.g. ABC 1234 / Honda Click 125i" required>
                    </div>

                    <div class="driver-doc-grid">
                        <div class="driver-doc-card">
                            <label>Valid ID Document</label>
                            <?php if (!empty($profile["id_image"])): ?>
                                <img class="driver-doc-thumb" src="uploads/ids/<?php echo escape($profile["id_image"]); ?>" alt="Driver ID Image">
                                <small style="color:#64748B;">Current file: <?php echo escape($profile["id_image"]); ?></small>
                            <?php else: ?>
                                <div style="height:140px; background:#E2E8F0; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#64748B; font-size:13px;">No ID uploaded</div>
                            <?php endif; ?>
                            <input type="file" id="id_image" name="id_image" accept="image/*">
                        </div>

                        <div class="driver-doc-card">
                            <label>OR / CR Document</label>
                            <?php if (!empty($profile["orcr_image"])): ?>
                                <img class="driver-doc-thumb" src="uploads/orcr/<?php echo escape($profile["orcr_image"]); ?>" alt="Driver OR/CR Image">
                                <small style="color:#64748B;">Current file: <?php echo escape($profile["orcr_image"]); ?></small>
                            <?php else: ?>
                                <div style="height:140px; background:#E2E8F0; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#64748B; font-size:13px;">No OR/CR uploaded</div>
                            <?php endif; ?>
                            <input type="file" id="orcr_image" name="orcr_image" accept="image/*">
                        </div>
                    </div>

                <?php elseif ($isStore): ?>
                    <div class="field">
                        <label for="store_name">Store name</label>
                        <input type="text" id="store_name" name="store_name" value="<?php echo escape($profile["store_name"]); ?>" required>
                    </div>
                    <div class="field">
                        <label for="store_contact">Store contact</label>
                        <input type="text" id="store_contact" name="store_contact" value="<?php echo escape($profile["store_contact"]); ?>" required>
                    </div>
                    <div class="field">
                        <label for="store_category">Store category</label>
                        <select id="store_category" name="store_category" style="height:44px;padding:0 12px;border:1px solid rgba(255,91,46,.22);border-radius:10px;font-size:13.5px;outline:none;width:100%;background:#fff;box-sizing:border-box;">
                            <option value="">&mdash; Select a category &mdash;</option>
                            <?php foreach ($prof_categories as $pc): ?>
                                <option value="<?php echo escape($pc['slug']); ?>" <?php echo $profile['store_category'] === $pc['slug'] ? 'selected' : ''; ?>>
                                    <?php echo escape($pc['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label for="store_address" style="margin-bottom:0;">Store address</label>
                            <button class="profile-location-btn" id="use-current-location-field-btn" type="button">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
                                <span>Use current location</span>
                            </button>
                        </div>
                        <div class="address-autofill-wrap">
                            <input type="text" id="store_address" name="store_address" value="<?php echo escape($profile["store_address"]); ?>" placeholder="Start typing your store address…" autocomplete="off" required>
                            <div class="address-autofill-spinner" id="addr-spinner"></div>
                            <div class="address-suggestions" id="addr-suggestions" role="listbox" aria-label="Address suggestions"></div>
                        </div>
                    </div>
                    <input type="hidden" id="map_lat" name="store_lat" value="<?php echo escape($profile["store_lat"]); ?>">
                    <input type="hidden" id="map_lng" name="store_lng" value="<?php echo escape($profile["store_lng"]); ?>">
                <?php else: ?>
                    <div class="field">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label for="user_address" style="margin-bottom:0;">Delivery address</label>
                            <button class="profile-location-btn" id="use-current-location-field-btn" type="button">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
                                <span>Use current location</span>
                            </button>
                        </div>
                        <div class="address-autofill-wrap">
                            <input type="text" id="user_address" name="user_address" value="<?php echo escape($profile["user_address"]); ?>" placeholder="Start typing your delivery address…" autocomplete="off" required>
                            <div class="address-autofill-spinner" id="addr-spinner"></div>
                            <div class="address-suggestions" id="addr-suggestions" role="listbox" aria-label="Address suggestions"></div>
                        </div>
                    </div>
                    <input type="hidden" id="map_lat" name="user_lat" value="<?php echo escape($profile["user_lat"]); ?>">
                    <input type="hidden" id="map_lng" name="user_lng" value="<?php echo escape($profile["user_lng"]); ?>">
                <?php endif; ?>

                <?php if (!$isDriver): ?>
                    <div class="profile-map-wrapper">
                        <div id="profile-map"></div>
                        <button type="button" id="map-gps-btn" class="map-floating-gps-btn" title="Use current location (GPS)" aria-label="Center GPS location">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
                        </button>
                    </div>
                    <p class="store-pin-status" id="pin-status">
                        <?php if ($mapLat !== "" && $mapLng !== ""): ?>
                            Pinned at <?php echo escape($mapLat); ?>, <?php echo escape($mapLng); ?>.
                        <?php else: ?>
                            No location pinned yet. Tap on the map or use the GPS button to set it.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <h2>Account Security</h2>
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

            <!-- Danger Zone: Delete Account -->
            <div class="danger-zone-card">
                <div class="danger-zone-header">
                    <div class="danger-zone-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                    <div>
                        <h3>Danger Zone</h3>
                        <p>Permanently remove your account and all associated data.</p>
                    </div>
                </div>
                <div class="danger-zone-body">
                    <p>Once you delete your account, there is no going back. All your profile information, order history, and saved locations will be permanently removed.</p>
                    <button type="button" class="btn-danger-outline" id="open-delete-account-modal">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        Delete Account
                    </button>
                </div>
            </div>
        </section>
    </main>

    <!-- DELETE ACCOUNT CONFIRMATION MODAL -->
    <div class="pm-overlay" id="delete-account-overlay" role="dialog" aria-modal="true" aria-labelledby="delete-acc-title">
        <div class="pm-box" style="max-width:440px;">
            <div class="pm-head">
                <h2 id="delete-acc-title" style="font-size:19px; color:#991B1B;">Delete Account</h2>
                <button class="pm-close" id="close-delete-acc" type="button" aria-label="Close">&times;</button>
            </div>
            <div style="display:flex; flex-direction:column; gap:12px;">
                <div style="width:56px; height:56px; border-radius:16px; background:#FEE2E2; display:flex; align-items:center; justify-content:center; color:#DC2626;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
                <p style="margin:0; font-size:14px; color:#374151; line-height:1.5;">
                    This action is <strong>permanent</strong> and cannot be undone. All your profile information, order history, and account access will be completely deleted.
                </p>
            </div>
            <form method="post" id="delete-account-form" style="margin-top:8px;">
                <input type="hidden" name="delete_account_submit" value="1">
                <div class="field" style="margin-bottom:16px;">
                    <label for="delete_confirm_password" style="font-weight:700; color:#1F2937;">Enter your password to confirm</label>
                    <input type="password" id="delete_confirm_password" name="delete_confirm_password" placeholder="Current password" required autocomplete="current-password">
                </div>
                <div class="pm-actions">
                    <button class="pm-cancel" type="button" id="cancel-delete-acc">Cancel</button>
                    <button type="submit" style="padding:10px 22px; border-radius:12px; border:none; background:linear-gradient(135deg, #DC2626, #B91C1C); color:#fff; font:inherit; font-size:13.5px; font-weight:700; cursor:pointer; box-shadow:0 4px 12px rgba(220, 38, 38, 0.35); transition:all 0.2s; display:inline-flex; align-items:center; gap:6px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        Yes, Delete My Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function previewProfilePhoto(event) {
            const input = event.target;
            const file = input.files && input.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.getElementById("avatar-preview-img");
                    const initial = document.getElementById("avatar-preview-initial");
                    if (img) {
                        img.src = e.target.result;
                        img.style.display = "block";
                    }
                    if (initial) {
                        initial.style.display = "none";
                    }
                };
                reader.readAsDataURL(file);
            }
        }

        // Delete Account Modal handlers
        const deleteAccOverlay = document.getElementById("delete-account-overlay");
        const openDeleteAccBtn = document.getElementById("open-delete-account-modal");
        const closeDeleteAccBtn = document.getElementById("close-delete-acc");
        const cancelDeleteAccBtn = document.getElementById("cancel-delete-acc");

        function openDeleteAccModal() {
            if (!deleteAccOverlay) return;
            deleteAccOverlay.classList.add("open");
            document.body.style.overflow = "hidden";
            const pwdInput = document.getElementById("delete_confirm_password");
            if (pwdInput) {
                pwdInput.value = "";
                setTimeout(() => pwdInput.focus(), 150);
            }
        }

        function closeDeleteAccModal() {
            if (!deleteAccOverlay) return;
            deleteAccOverlay.classList.remove("open");
            document.body.style.overflow = "";
        }

        if (openDeleteAccBtn) openDeleteAccBtn.addEventListener("click", openDeleteAccModal);
        if (closeDeleteAccBtn) closeDeleteAccBtn.addEventListener("click", closeDeleteAccModal);
        if (cancelDeleteAccBtn) cancelDeleteAccBtn.addEventListener("click", closeDeleteAccModal);
        if (deleteAccOverlay) {
            deleteAccOverlay.addEventListener("click", function(e) {
                if (e.target === deleteAccOverlay) closeDeleteAccModal();
            });
        }

        document.addEventListener("keydown", function(e) {
            if (e.key === "Escape") closeDeleteAccModal();
        });
    </script>

    <?php if (!$isDriver): ?>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
        <script>
            const map = L.map("profile-map", { zoomControl: true }).setView([14.5995, 120.9842], 13);
            
            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                maxZoom: 19,
                subdomains: ["a", "b", "c"],
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);

            // Invalidate size repeatedly so tiles are always calculated and rendered cleanly
            setTimeout(() => { map.invalidateSize(); }, 60);
            setTimeout(() => { map.invalidateSize(); }, 200);
            setTimeout(() => { map.invalidateSize(); }, 600);
            setTimeout(() => { map.invalidateSize(); }, 1200);
            window.addEventListener("resize", () => map.invalidateSize());
            if (window.ResizeObserver) {
                const mapEl = document.getElementById("profile-map");
                if (mapEl) new ResizeObserver(() => map.invalidateSize()).observe(mapEl);
            }

            const latInput = document.getElementById("map_lat");
            const lngInput = document.getElementById("map_lng");
            const pinStatus = document.getElementById("pin-status");
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
                        color: "#FF4D2E",
                        weight: 3,
                        fillColor: "#FFFFFF",
                        fillOpacity: 1
                    }).addTo(map);
                }
                if (latInput) latInput.value = lat.toFixed(6);
                if (lngInput) lngInput.value = lng.toFixed(6);
                if (pinStatus) pinStatus.textContent = `Pinned at ${lat.toFixed(6)}, ${lng.toFixed(6)}.`;
                if (centerMap) {
                    map.setView([lat, lng], 16);
                    map.invalidateSize();
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
                    // Keep pinned coordinates
                }
            }

            function pinCurrentLocation() {
                if (!("geolocation" in navigator)) {
                    if (pinStatus) pinStatus.textContent = "Location access is not available in this browser.";
                    return;
                }

                const btnField = document.getElementById("use-current-location-field-btn");
                const btnFloating = document.getElementById("map-gps-btn");
                const iconHtml = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>';

                if (btnField) { btnField.disabled = true; btnField.innerHTML = `<span>Locating…</span>`; }
                if (btnFloating) { btnFloating.disabled = true; btnFloating.style.opacity = "0.5"; }
                if (pinStatus) pinStatus.textContent = "Getting your current GPS location...";

                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        setPin(lat, lng, true);
                        if (pinStatus) pinStatus.textContent = `Pinned from your GPS location at ${lat.toFixed(6)}, ${lng.toFixed(6)}.`;
                        suggestAddressFromPin(lat, lng);
                        if (btnField) { btnField.disabled = false; btnField.innerHTML = `${iconHtml} <span>Use current location</span>`; }
                        if (btnFloating) { btnFloating.disabled = false; btnFloating.style.opacity = "1"; }
                    },
                    () => {
                        if (pinStatus) pinStatus.textContent = "Unable to access your current location. Please allow location permissions in browser.";
                        if (btnField) { btnField.disabled = false; btnField.innerHTML = `${iconHtml} <span>Use current location</span>`; }
                        if (btnFloating) { btnFloating.disabled = false; btnFloating.style.opacity = "1"; }
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            }

            const existingLat = Number(latInput ? latInput.value : 0);
            const existingLng = Number(lngInput ? lngInput.value : 0);
            if (Number.isFinite(existingLat) && Number.isFinite(existingLng) && existingLat !== 0 && existingLng !== 0) {
                setPin(existingLat, existingLng, true);
            } else if ("geolocation" in navigator) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        map.setView([position.coords.latitude, position.coords.longitude], 15);
                        map.invalidateSize();
                        if (pinStatus) pinStatus.textContent = "Location centered. Tap on map to drop a pin.";
                    },
                    () => {
                        map.setView([14.5995, 120.9842], 12);
                        map.invalidateSize();
                    },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            } else {
                map.setView([14.5995, 120.9842], 12);
                map.invalidateSize();
            }

            map.on("click", (event) => {
                setPin(event.latlng.lat, event.latlng.lng, false);
                suggestAddressFromPin(event.latlng.lat, event.latlng.lng);
            });

            const fieldLocationButton = document.getElementById("use-current-location-field-btn");
            if (fieldLocationButton) {
                fieldLocationButton.addEventListener("click", pinCurrentLocation);
            }

            const floatingGpsButton = document.getElementById("map-gps-btn");
            if (floatingGpsButton) {
                floatingGpsButton.addEventListener("click", (e) => {
                    e.preventDefault();
                    pinCurrentLocation();
                });
            }

            /* Address Autofill for store/user address */
            (function () {
                const addrInput   = addressInput;
                const addrSugBox  = document.getElementById("addr-suggestions");
                const addrSpinner = document.getElementById("addr-spinner");
                const addrLatInp  = document.getElementById("map_lat");
                const addrLngInp  = document.getElementById("map_lng");

                if (!addrInput || !addrSugBox) return;

                document.body.appendChild(addrSugBox);

                let debounceTimer  = null;
                let activeIndex    = -1;
                let currentResults = [];

                function positionDropdown() {
                    const rect = addrInput.getBoundingClientRect();
                    addrSugBox.style.top   = (rect.bottom + window.scrollY + 4) + "px";
                    addrSugBox.style.left  = (rect.left + window.scrollX) + "px";
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
                        empty.innerHTML = `<span class="sug-icon">&#9888;</span><span class="sug-text"><strong>No results found</strong><span>Try a more specific address</span></span>`;
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
                            item.innerHTML  = `<span class="sug-icon">&#128205;</span><span class="sug-text"><strong>${primary}</strong><span>${secondary}</span></span>`;
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
                    if (typeof setPin === "function") {
                        setPin(lat, lng, true);
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

                window.addEventListener("scroll", () => {
                    if (addrSugBox.classList.contains("open")) positionDropdown();
                }, true);
                window.addEventListener("resize", () => {
                    if (addrSugBox.classList.contains("open")) positionDropdown();
                });
            })();
        </script>
    <?php endif; ?>
</body>
</html>
