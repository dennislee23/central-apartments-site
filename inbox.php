<?php
// Reverse proxy for the conversations inbox. The page itself is rendered by the
// Cloudflare Worker (it reads the live D1 database); we serve it under
// centralapartments.ee so the pilot lives on the client's own domain. Apache
// Basic Auth (see .htaccess) authenticates the team once; we forward the same
// credentials to the Worker, which also requires them.
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW'] ?? '';
// Fallback for PHP-FPM/CGI where PHP_AUTH_* isn't populated: parse the raw header.
if ($user === '') {
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (stripos($hdr, 'Basic ') === 0) {
    $dec = base64_decode(substr($hdr, 6));
    if ($dec !== false && strpos($dec, ':') !== false) [$user, $pass] = explode(':', $dec, 2);
  }
}
$WORKER = 'https://roland-bot.hello-071.workers.dev/inbox';

$body = false; $code = 0;
if (function_exists('curl_init')) {
  $ch = curl_init($WORKER);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$user:$pass")],
    CURLOPT_TIMEOUT => 30,
  ]);
  $body = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
} else {
  $ctx = stream_context_create(['http' => [
    'header' => 'Authorization: Basic ' . base64_encode("$user:$pass") . "\r\n",
    'timeout' => 30, 'ignore_errors' => true,
  ]]);
  $body = @file_get_contents($WORKER, false, $ctx);
  if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int) $m[1];
}

http_response_code($code ?: 502);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
echo $body !== false ? $body : 'Inbox temporarily unavailable — try again in a moment.';
