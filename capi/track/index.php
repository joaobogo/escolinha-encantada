<?php
// capi/track/index.php  (Option 3: hardcode secrets here)
// SECURITY: This endpoint only accepts POST and returns JSON.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Method Not Allowed']);
  exit;
}

header('Content-Type: application/json');

// ====== YOUR SECRETS (hardcoded) ======
$FB_PIXEL_ID   = '1200423845416419';
$FB_CAPI_TOKEN = 'EAAH2T5l7sGEBO4ay3keTOKqU0KZCTVG74YD8dNY90dFKdZBmXZAf5CIZB4DnFJLe5yrDIt1zYT0HuMzcPOJVfHUmERR61WuiQGb80PFjRZB8Wb4IjJ5A5g6IUjKhqmuFO16EGNyb5TXnXt4ZCa5uommqbj7E46PqzdgUGDsyuRqtysjn1t8RizIAEKZAOZA0nMvFugZDZD';

// ====== Helper: hash PII per Meta spec ======
function sha256LowerTrim($v) {
  if (!$v) return null;
  return hash('sha256', strtolower(trim((string)$v)));
}

// ====== Parse JSON body ======
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid JSON']);
  exit;
}

$event_name       = $data['event_name']       ?? null;
$event_time       = $data['event_time']       ?? time();
$event_source_url = $data['event_source_url'] ?? null;
$action_source    = $data['action_source']    ?? 'website';
$event_id         = $data['event_id']         ?? null;
$fbp              = $data['fbp']              ?? null;
$fbc              = $data['fbc']              ?? null;
$user_data_in     = $data['user_data']        ?? [];
$custom_data      = $data['custom_data']      ?? new stdClass();

if (!$event_name) {
  http_response_code(400);
  echo json_encode(['error' => 'event_name required']);
  exit;
}

// ====== Build user_data (hash any PII you send) ======
$user_data = [];
if (!empty($user_data_in['em']))      $user_data['em']      = [ sha256LowerTrim($user_data_in['em']) ];
if (!empty($user_data_in['ph']))      $user_data['ph']      = [ sha256LowerTrim($user_data_in['ph']) ];
if (!empty($user_data_in['fn']))      $user_data['fn']      = [ sha256LowerTrim($user_data_in['fn']) ];
if (!empty($user_data_in['ln']))      $user_data['ln']      = [ sha256LowerTrim($user_data_in['ln']) ];
if (!empty($user_data_in['ct']))      $user_data['ct']      = [ sha256LowerTrim($user_data_in['ct']) ];
if (!empty($user_data_in['st']))      $user_data['st']      = [ sha256LowerTrim($user_data_in['st']) ];
if (!empty($user_data_in['zp']))      $user_data['zp']      = [ sha256LowerTrim($user_data_in['zp']) ];
if (!empty($user_data_in['country'])) $user_data['country'] = [ sha256LowerTrim($user_data_in['country']) ];

if ($fbp) $user_data['fbp'] = $fbp;
if ($fbc) $user_data['fbc'] = $fbc;

// Advanced matching from request headers
$user_data['client_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($clientIp, ',') !== false) { $clientIp = explode(',', $clientIp)[0]; }
$user_data['client_ip_address'] = trim($clientIp);

// ====== Build Conversions API payload ======
$payload = [
  'data' => [[
    'event_name'       => $event_name,
    'event_time'       => (int)$event_time,
    'event_id'         => $event_id,         // helps deduplicate with the browser pixel
    'action_source'    => $action_source,
    'event_source_url' => $event_source_url,
    'user_data'        => $user_data,
    'custom_data'      => $custom_data
  ]]
];

$endpoint = "https://graph.facebook.com/v17.0/{$FB_PIXEL_ID}/events?access_token=" . rawurlencode($FB_CAPI_TOKEN);

// ====== POST to Meta via cURL ======
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS     => json_encode($payload),
  CURLOPT_TIMEOUT        => 10,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($response === false) {
  http_response_code(500);
  echo json_encode(['error' => 'cURL error', 'detail' => $err]);
  exit;
}

http_response_code(($httpCode >= 200 && $httpCode < 300) ? 200 : 400);
echo $response;
