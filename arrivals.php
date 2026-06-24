<?php
// Reverse proxy for the "Arrivals" page (rendered by the worker from D1).
// Also proxies the manual "checked in" toggle: /arrivals/checkin?id=<bookingId> (POST).
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW'] ?? '';
if ($user === '') {
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (stripos($hdr, 'Basic ') === 0) {
    $dec = base64_decode(substr($hdr, 6));
    if ($dec !== false && strpos($dec, ':') !== false) [$user, $pass] = explode(':', $dec, 2);
  }
}
$base = 'https://roland-bot.hello-071.workers.dev';
$isCheckin = isset($_GET['id']) && $_GET['id'] !== '';
$WORKER = $isCheckin ? $base . '/arrivals/checkin?id=' . rawurlencode($_GET['id']) : $base . '/arrivals';
$body = false; $code = 0;
if (function_exists('curl_init')) {
  $ch = curl_init($WORKER);
  $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$user:$pass")], CURLOPT_TIMEOUT => 30];
  if ($isCheckin) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = ''; }
  curl_setopt_array($ch, $opt);
  $body = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
} else {
  $http = ['header' => 'Authorization: Basic ' . base64_encode("$user:$pass") . "\r\n", 'timeout' => 30, 'ignore_errors' => true];
  if ($isCheckin) { $http['method'] = 'POST'; $http['content'] = ''; }
  $ctx = stream_context_create(['http' => $http]);
  $body = @file_get_contents($WORKER, false, $ctx);
  if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int) $m[1];
}
http_response_code($code ?: 502);
header('Content-Type: ' . ($isCheckin ? 'application/json' : 'text/html') . '; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
echo $body !== false ? $body : ($isCheckin ? '{"error":"unavailable"}' : 'Arrivals page temporarily unavailable.');
