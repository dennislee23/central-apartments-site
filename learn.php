<?php
// Reverse proxy for the bot-learning review page (rendered by the Worker from
// D1). Serves both /learn (the page) and /learn/act (approve/reject), under the
// site's Basic Auth. We forward the team's credentials to the Worker.
require __DIR__ . '/auth.php';  // cookie-session auth (sets $user/$pass for the Worker call)

// Which Worker route this request is for. POSTs carry either ?run=1 (distillation),
// ?op=edit / ?op=reject-note (set by the .htaccess rewrites for those two paths), or
// nothing at all, which is the "add info" form. GETs are the page itself, or /learn/act
// when a 'do' param is present.
//
// The op allow-list is deliberate: $op is attacker-controlled, and pasting it into the
// URL unchecked would let anyone behind the login reach any Worker route.
$base = 'https://roland-bot.hello-071.workers.dev/learn';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$op = $_GET['op'] ?? '';
if (!in_array($op, ['edit', 'reject-note'], true)) $op = '';
if ($isPost) {
  $target = ($_GET['run'] ?? '') === '1' ? $base . '/run' : ($op !== '' ? $base . '/' . $op : $base . '/add');
} else {
  $target = isset($_GET['do']) ? $base . '/act' : $base;
}
$qs = $_SERVER['QUERY_STRING'] ?? '';
if (!$isPost && $qs !== '') $target .= '?' . $qs;
$postBody = $isPost ? file_get_contents('php://input') : '';
// Distillation (run) calls Claude per message-pair and can take ~40s — give it room.
$timeout = (($_GET['run'] ?? '') === '1') ? 120 : 30;

$body = false; $code = 0; $ctype = 'text/html; charset=utf-8';
if (function_exists('curl_init')) {
  $ch = curl_init($target);
  $hdrs = ['Authorization: Basic ' . base64_encode("$user:$pass")];
  if ($isPost) $hdrs[] = 'Content-Type: application/json';
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $hdrs,
    CURLOPT_TIMEOUT => $timeout,
  ];
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
echo $body !== false ? $body : 'Learning page temporarily unavailable.';
