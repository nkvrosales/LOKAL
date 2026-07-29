<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/db_connect.php';

$device  = trim($_GET['device'] ?? '');
$status  = trim($_GET['status'] ?? '');
$time    = date('h:i A');

$valid   = isset($_GET['valid'])   ? (int)$_GET['valid']   : null;
$updated = isset($_GET['updated']) ? (int)$_GET['updated'] : null;
$sat     = isset($_GET['sat'])     ? (int)$_GET['sat']     : null;
$lat     = isset($_GET['lat'])     ? (float)$_GET['lat']   : null;
$lng     = isset($_GET['lng'])     ? (float)$_GET['lng']   : null;
$chars   = isset($_GET['chars'])   ? (int)$_GET['chars']   : null;
$hdop    = isset($_GET['hdop'])    ? (float)$_GET['hdop']  : null;

if (empty($device)) {
    http_response_code(400);
    die("Missing device");
}

$stmt = $conn->prepare("
    INSERT INTO gps_logs
    (device, status, device_time, valid, updated, sat, lat, lng, chars_count, hdop)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "sssiiiddid",
    $device,
    $status,
    $time,
    $valid,
    $updated,
    $sat,
    $lat,
    $lng,
    $chars,
    $hdop
);

if ($stmt->execute()) {
    echo "OK";
} else {
    http_response_code(500);
    echo "DB Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
