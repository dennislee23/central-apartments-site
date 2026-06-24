<?php
// Reverse proxy for the bot-learning review page (rendered by the Worker from
// D1). Serves both /learn (the page) and /learn/act (approve/reject), under the
// site's Basic Auth. We forward the team's credentials to the Worker.
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW'] ?? '';
if ($user === '') {
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (stripos($hdr, 'Basic ') === 0) {
    $dec = base64_decode(substr($hdr, 6));
    if ($dec !== false && strpos($dec, ':') !== false) [$user, $pass] = explode(':', $dec, 2);
  }
}

// POST = the "add info" form (-> /learn/add); /learn/act carries a 'do' param;
// everything else is the page.
$base = 'https://roland-bot.hello-071.workers.dev/learn';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$target = $isPost ? $base . '/add' : (isset($_GET['do']) ? $base . '/act' : $base);
$qs = $_SERVER['QUERY_STRING'] ?? '';
if (!$isPost && $qs !== '') $target .= '?' . $qs;
$postBody = $isPost ? file_get_contents('php://input') : '';

$body = false; $code = 0; $ctype = 'text/html; charset=utf-8';
if (function_exists('curl_init')) {
  $ch = curl_init($target);
  $hdrs = ['Authorization: Basic ' . base64_encode("$user:$pass")];
  if ($isPost) $hdrs[] = 'Content-Type: application/json';
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $hdrs,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_POST => $isPost,
    CURLOPT_POSTFIELDS => $isPost ? $postBody : null,
  ]);
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
    'timeout' => 30, 'ignore_errors' => true,
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
echo $body !== false ? $body : 'Learning page temporarily unavailable.';
