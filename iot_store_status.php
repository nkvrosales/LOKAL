<?php
require_once "db.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");

function iot_response(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

$headers = function_exists("getallheaders") ? getallheaders() : [];
$deviceToken = (string) ($_SERVER["HTTP_X_DEVICE_TOKEN"] ?? "");
foreach ($headers as $name => $value) {
    if (strcasecmp((string) $name, "X-Device-Token") === 0) {
        $deviceToken = (string) $value;
        break;
    }
}
if (strlen($deviceToken) !== 64 || !ctype_xdigit($deviceToken)) {
    iot_response(401, ["ok" => false, "error" => "invalid_device_token"]);
}

$tokenHash = hash("sha256", $deviceToken);
$lookup = $mysqli->prepare("SELECT id, store_is_open FROM users WHERE account_type = 'store' AND store_device_token_hash = ? LIMIT 1");
if (!$lookup) {
    iot_response(500, ["ok" => false, "error" => "database_error"]);
}
$lookup->bind_param("s", $tokenHash);
$lookup->execute();
$lookup->bind_result($storeId, $isOpen);
$found = $lookup->fetch();
$lookup->close();
if (!$found) {
    iot_response(401, ["ok" => false, "error" => "invalid_device_token"]);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    iot_response(200, ["ok" => true, "is_open" => (int) $isOpen === 1]);
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: GET, POST");
    iot_response(405, ["ok" => false, "error" => "method_not_allowed"]);
}

$payload = json_decode((string) file_get_contents("php://input"), true);
if (!is_array($payload) || !array_key_exists("is_open", $payload) || !in_array($payload["is_open"], [true, false, 0, 1, "0", "1"], true)) {
    iot_response(400, ["ok" => false, "error" => "is_open_must_be_boolean"]);
}
$nextStatus = in_array($payload["is_open"], [true, 1, "1"], true) ? 1 : 0;
$update = $mysqli->prepare("UPDATE users SET store_is_open = ? WHERE id = ? AND account_type = 'store' LIMIT 1");
if (!$update) {
    iot_response(500, ["ok" => false, "error" => "database_error"]);
}
$update->bind_param("ii", $nextStatus, $storeId);
$success = $update->execute();
$update->close();
if (!$success) {
    iot_response(500, ["ok" => false, "error" => "update_failed"]);
}
iot_response(200, ["ok" => true, "is_open" => $nextStatus === 1]);
