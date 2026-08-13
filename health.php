<?php
// Reverse proxy for the "Health" page — does the bot work RIGHT NOW. The page itself
// is rendered by the Cloudflare Worker (it reads the live D1 database); we serve it
// under centralapartments.ee so the pilot lives on the client's own domain. Apache
// Basic Auth (see .htaccess) authenticates the team once; we forward the same
// credentials to the Worker, which also requires them.
//
// Modelled on inbox.php. The only page-specific part is the ?lang= pass-through:
// the panel reads it to render in another language without a redeploy, which is how
// an operator is shown their own panel in their own language.
require __DIR__ . '/auth.php';  // cookie-session auth (sets $user/$pass for the Worker call)

$base = 'https://roland-bot.hello-071.workers.dev';
$WORKER = $base . '/health';
if (isset($_GET['lang']) && preg_match('/^[a-z]{2}$/', $_GET['lang'])) {
  $WORKER .= '?lang=' . rawurlencode($_GET['lang']);
}

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
  $http = [
    'header' => 'Authorization: Basic ' . base64_encode("$user:$pass") . "\r\n",
    'timeout' => 30,
    'ignore_errors' => true,
  ];
  $body = @file_get_contents($WORKER, false, stream_context_create(['http' => $http]));
  if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
    $code = (int) $m[1];
  }
}

header('Content-Type: text/html; charset=utf-8');
if ($body === false || $code >= 400) {
  // This page exists to answer "is it working"; failing silently would be the one
  // outcome it must never produce.
  http_response_code(502);
  echo '<!doctype html><meta charset="utf-8"><title>Health</title>'
     . '<p style="font:16px/1.5 system-ui;padding:24px">The health page could not be loaded'
     . ($code ? ' (HTTP ' . $code . ')' : '') . '. The bot itself may still be running — '
     . 'this only means the panel could not reach it.</p>';
  exit;
}
echo $body;
