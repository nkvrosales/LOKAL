<?php
require_once __DIR__ . '/db_connect.php';

$latest = [
    'device' => '',
    'status' => '',
    'device_time' => '',
    'valid' => '',
    'updated' => '',
    'sat' => '',
    'lat' => '',
    'lng' => '',
    'chars_count' => '',
    'hdop' => '',
    'created_at' => ''
];

$result = $conn->query(
    "SELECT device, status, device_time, valid, updated, sat, lat, lng, chars_count, hdop, created_at
     FROM gps_logs
     ORDER BY id DESC
     LIMIT 1"
);

if ($result && ($row = $result->fetch_assoc())) {
    foreach ($latest as $key => $value) {
        $currentValue = $row[$key] ?? '';
        $latest[$key] = $currentValue === null ? '' : (string) $currentValue;
    }
}

if ($result) {
    $result->close();
}

$hasLocation = ($latest['lat'] !== '' && $latest['lng'] !== '');
$mapUrl = $hasLocation
    ? "https://www.google.com/maps?q={$latest['lat']},{$latest['lng']}&z=15&output=embed"
    : "";
$openMapUrl = $hasLocation
    ? "https://www.google.com/maps?q={$latest['lat']},{$latest['lng']}"
    : "";
$rawRow = $latest['device'] !== ''
    ? json_encode($latest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    : '';

function escape_value(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>GPS Map Viewer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      font-family: Arial, sans-serif;
      background: #f5f5f5;
      margin: 0;
      padding: 20px;
    }
    .wrap {
      max-width: 1000px;
      margin: auto;
    }
    .card {
      background: #fff;
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 20px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    }
    h1 {
      margin-top: 0;
    }
    .map-box {
      width: 100%;
      height: 500px;
      border-radius: 12px;
      overflow: hidden;
    }
    iframe {
      width: 100%;
      height: 100%;
      border: 0;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
    }
    .item {
      background: #fafafa;
      padding: 12px;
      border-radius: 10px;
    }
    .label {
      font-size: 12px;
      color: #666;
      margin-bottom: 4px;
    }
    .value {
      font-size: 15px;
      font-weight: bold;
      word-break: break-word;
    }
    .btn {
      display: inline-block;
      padding: 10px 14px;
      background: #111;
      color: #fff;
      text-decoration: none;
      border-radius: 8px;
      margin-top: 15px;
    }
    pre {
      white-space: pre-wrap;
      word-wrap: break-word;
      background: #111;
      color: #0f0;
      padding: 12px;
      border-radius: 10px;
      font-size: 12px;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>GPS Map Viewer</h1>
      <?php if ($hasLocation): ?>
        <div class="map-box">
          <iframe src="<?php echo escape_value($mapUrl); ?>"></iframe>
        </div>
        <a class="btn" href="<?php echo escape_value($openMapUrl); ?>" target="_blank">Open in Google Maps</a>
      <?php else: ?>
        <p>No latitude/longitude found yet in the <strong>gps_logs</strong> table.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Latest GPS Data</h2>
      <div class="grid">
        <div class="item"><div class="label">Device</div><div class="value"><?php echo escape_value($latest['device']); ?></div></div>
        <div class="item"><div class="label">Status</div><div class="value"><?php echo escape_value($latest['status']); ?></div></div>
        <div class="item"><div class="label">Device Time</div><div class="value"><?php echo escape_value($latest['device_time']); ?></div></div>
        <div class="item"><div class="label">Server Time</div><div class="value"><?php echo escape_value($latest['created_at']); ?></div></div>
        <div class="item"><div class="label">Valid</div><div class="value"><?php echo escape_value($latest['valid']); ?></div></div>
        <div class="item"><div class="label">Updated</div><div class="value"><?php echo escape_value($latest['updated']); ?></div></div>
        <div class="item"><div class="label">Satellites</div><div class="value"><?php echo escape_value($latest['sat']); ?></div></div>
        <div class="item"><div class="label">Latitude</div><div class="value"><?php echo escape_value($latest['lat']); ?></div></div>
        <div class="item"><div class="label">Longitude</div><div class="value"><?php echo escape_value($latest['lng']); ?></div></div>
        <div class="item"><div class="label">Chars Count</div><div class="value"><?php echo escape_value($latest['chars_count']); ?></div></div>
        <div class="item"><div class="label">HDOP</div><div class="value"><?php echo escape_value($latest['hdop']); ?></div></div>
      </div>
    </div>

    <div class="card">
      <h2>Latest GPS Row</h2>
      <pre><?php echo escape_value($rawRow); ?></pre>
    </div>
  </div>
</body>
</html>
