<?php
// Reverse proxy for the "Objects" page (rendered by the worker from D1).
// GET  -> the page (/apartments). POST -> a photo upload (/apartments/photo).
require __DIR__ . '/auth.php';  // cookie-session auth (sets $user/$pass for the Worker call)
$base = 'https://roland-bot.hello-071.workers.dev';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$op = $_GET['op'] ?? '';

// Parking-walkthrough video: the FILE is written directly to THIS host (not proxied
// through the Worker — videos are too large for a clean JSON round trip through D1).
// We decode + save it here, then tell the Worker the resulting public url so
// parkingBundleFor() can find it (self-serve — see the room_video table).
if ($isPost && $op === 'video') {
  header('Content-Type: application/json');
  header('X-Robots-Tag: noindex, nofollow');
  header('Cache-Control: no-store');
  $req = json_decode(file_get_contents('php://input'), true) ?: [];
  $roomId = preg_replace('/\D/', '', (string) ($req['roomId'] ?? ''));
  $mime = (string) ($req['mime'] ?? 'video/mp4');
  $caption = substr((string) ($req['caption'] ?? ''), 0, 200);
  $data = base64_decode((string) ($req['videoData'] ?? ''), true);
  if (!$roomId || $data === false || strlen($data) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'roomId + videoData required']);
    exit;
  }
  if (strlen($data) > 20 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'file too large (>20MB)']);
    exit;
  }
  $ext = strpos($mime, 'quicktime') !== false ? 'mov' : (strpos($mime, 'webm') !== false ? 'webm' : 'mp4');
  $dir = __DIR__ . "/ci-7f3a9/parking-videos/$roomId";
  if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'could not create directory']);
    exit;
  }
  $name = bin2hex(random_bytes(8)) . ".$ext";
  if (file_put_contents("$dir/$name", $data) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'write failed']);
    exit;
  }
  $videoUrl = "https://centralapartments.ee/ci-7f3a9/parking-videos/$roomId/$name";

  // Register the pointer with the Worker (D1 room_video), same auth as everything else.
  $ch = curl_init("$base/apartments/video-register");
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$user:$pass"), 'Content-Type: application/json'],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['roomId' => $roomId, 'videoUrl' => $videoUrl, 'mime' => $mime, 'caption' => $caption]),
  ]);
  $regBody = curl_exec($ch);
  $regCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($regCode < 200 || $regCode >= 300) {
    @unlink("$dir/$name"); // registration failed — don't leave an orphaned, unlisted file
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'registration failed: ' . $regBody]);
    exit;
  }
  echo json_encode(['ok' => true, 'videoUrl' => $videoUrl]);
  exit;
}

// "Preview" — asks the bot for real (the same pipeline a guest hits, via the
// existing /ask/run sandbox endpoint), so the team sees an authentic answer
// instead of a raw-template guess. GET-shaped call, but the panel POSTs it.
if ($op === 'preview') {
  header('Content-Type: application/json');
  header('X-Robots-Tag: noindex, nofollow');
  header('Cache-Control: no-store');
  $req = $isPost ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
  $roomId = (string) ($req['roomId'] ?? '');
  $q = 'Здравствуйте! Я скоро приезжаю — расскажите, пожалуйста, как заселиться: адрес, во сколько заезд, какой ключ/код, Wi-Fi и правила проживания.';
  if (!$roomId) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'roomId required']); exit; }
  $url = "$base/ask/run?mode=apt&room=" . rawurlencode($roomId) . "&q=" . rawurlencode($q);
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$user:$pass")], CURLOPT_TIMEOUT => 30]);
  $body = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  http_response_code($code ?: 502);
  echo $body !== false ? $body : '{"ok":false,"error":"unavailable"}';
  exit;
}

$postPath = $op === 'remove' ? '/apartments/photo-remove'
  : ($op === 'wifi' ? '/apartments/wifi'
  : ($op === 'templates' ? '/apartments/templates'
  : ($op === 'video-remove' ? '/apartments/video-remove'
  : ($op === 'photo-edit' ? '/apartments/photo-edit'
  : ($op === 'photo-scenario' ? '/apartments/photo-scenario'
  : ($op === 'scenario-add' ? '/apartments/scenario-add'
  : ($op === 'scenario-delete' ? '/apartments/scenario-delete'
  : ($op === 'photo-reorder' ? '/apartments/photo-reorder'
  : ($op === 'bot-toggle' ? '/apartments/bot-toggle'
  : ($op === 'booking-meta' ? '/apartments/booking-meta'
  : ($op === 'photos' ? '/apartments/photos' : '/apartments/photo')))))))))));
$allParam = (isset($_GET['all']) && $_GET['all'] === '1') ? '?all=1' : '';
$target = $isPost ? $base . $postPath : $base . '/apartments' . $allParam;
$timeout = $op === 'photos' ? 120 : 30; // batch upload can be large
$postBody = $isPost ? file_get_contents('php://input') : '';

$body = false; $code = 0; $ctype = $isPost ? 'application/json' : 'text/html; charset=utf-8';
if (function_exists('curl_init')) {
  $ch = curl_init($target);
  $hdrs = ['Authorization: Basic ' . base64_encode("$user:$pass")];
  if ($isPost) $hdrs[] = 'Content-Type: application/json';
  $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdrs, CURLOPT_TIMEOUT => $timeout];
  if ($isPost) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $postBody; }
  curl_setopt_array($ch, $opts);
  $body = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  if ($ct) $ctype = $ct;
  curl_close($ch);
} else {
  $ctx = stream_context_create(['http' => [
    'header' => 'Authorization: Basic ' . base64_encode("$user:$pass") . "\r\n" . ($isPost ? "Content-Type: application/json\r\n" : ''),
    'method' => $isPost ? 'POST' : 'GET',
    'content' => $isPost ? $postBody : '',
    'timeout' => $timeout, 'ignore_errors' => true,
  ]]);
  $body = @file_get_contents($target, false, $ctx);
  if (isset($http_response_header)) {
    foreach ($http_response_header as $h) {
      if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $h, $m)) $code = (int) $m[1];
      if (stripos($h, 'content-type:') === 0) $ctype = trim(substr($h, 13));
    }
  }
}
http_response_code($code ?: 502);
header('Content-Type: ' . $ctype);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
echo $body !== false ? $body : ($isPost ? '{"ok":false,"error":"unavailable"}' : 'Objects page temporarily unavailable.');
