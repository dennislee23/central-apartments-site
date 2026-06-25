<?php
// Reverse proxy for the "Objects" page (rendered by the worker from D1).
// GET  -> the page (/apartments). POST -> a photo upload (/apartments/photo).
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
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$op = $_GET['op'] ?? '';
$postPath = $op === 'remove' ? '/apartments/photo-remove'
  : ($op === 'wifi' ? '/apartments/wifi'
  : ($op === 'templates' ? '/apartments/templates'
  : ($op === 'photos' ? '/apartments/photos' : '/apartments/photo')));
$target = $isPost ? $base . $postPath : $base . '/apartments';
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
