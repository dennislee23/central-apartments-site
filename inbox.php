<?php
// Reverse proxy for the conversations inbox. The page itself is rendered by the
// Cloudflare Worker (it reads the live D1 database); we serve it under
// centralapartments.ee so the pilot lives on the client's own domain. Apache
// Basic Auth (see .htaccess) authenticates the team once; we forward the same
// credentials to the Worker, which also requires them.
require __DIR__ . '/auth.php';  // cookie-session auth (sets $user/$pass for the Worker call)
$base = 'https://roland-bot.hello-071.workers.dev';
// /inbox -> the page; /inbox/tr?key=... -> RU translations; /inbox?archive=<key>&off=1|0 -> archive toggle (POST).
$isArchive = isset($_GET['archive']) && $_GET['archive'] !== '';
if ($isArchive) {
  $WORKER = $base . '/inbox/archive?key=' . rawurlencode($_GET['archive']) . '&off=' . rawurlencode($_GET['off'] ?? '1');
} elseif (isset($_GET['tr'])) {
  $WORKER = $base . '/inbox/tr?key=' . urlencode($_GET['key'] ?? '');
} else {
  $WORKER = $base . '/inbox';
}

$body = false; $code = 0;
if (function_exists('curl_init')) {
  $ch = curl_init($WORKER);
  $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$user:$pass")], CURLOPT_TIMEOUT => 30];
  if ($isArchive) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = ''; }
  curl_setopt_array($ch, $opt);
  $body = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
} else {
  $http = ['header' => 'Authorization: Basic ' . base64_encode("$user:$pass") . "\r\n", 'timeout' => 30, 'ignore_errors' => true];
  if ($isArchive) { $http['method'] = 'POST'; $http['content'] = ''; }
  $ctx = stream_context_create(['http' => $http]);
  $body = @file_get_contents($WORKER, false, $ctx);
  if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int) $m[1];
}

http_response_code($code ?: 502);
header('Content-Type: ' . ($isArchive ? 'application/json' : 'text/html') . '; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
echo $body !== false ? $body : ($isArchive ? '{"error":"unavailable"}' : 'Inbox temporarily unavailable — try again in a moment.');
