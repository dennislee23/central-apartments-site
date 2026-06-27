<?php
// Reverse proxy for the "Waiting for reply" page (rendered by the worker from D1),
// served under centralapartments.ee behind the site's Basic Auth.
require __DIR__ . '/auth.php';  // cookie-session auth (sets $user/$pass for the Worker call)
$WORKER = 'https://roland-bot.hello-071.workers.dev/waiting';
$body = false; $code = 0;
if (function_exists('curl_init')) {
  $ch = curl_init($WORKER);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$user:$pass")], CURLOPT_TIMEOUT => 30]);
  $body = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
} else {
  $ctx = stream_context_create(['http' => ['header' => 'Authorization: Basic ' . base64_encode("$user:$pass") . "\r\n", 'timeout' => 30, 'ignore_errors' => true]]);
  $body = @file_get_contents($WORKER, false, $ctx);
  if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int) $m[1];
}
http_response_code($code ?: 502);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
echo $body !== false ? $body : 'Waiting page temporarily unavailable.';
