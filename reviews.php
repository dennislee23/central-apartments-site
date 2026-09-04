<?php
// Reverse proxy for the Booking.com reviews page (rendered by the Worker from D1),
// under the site's cookie session. Paths: /reviews (page), POST /reviews/act
// (mark posted/skipped) and POST /reviews/redraft (ask for another draft) — the
// .htaccess rewrites set ?op= for the two POSTs.
require __DIR__ . '/auth.php';  // sets $user/$pass for the Worker call

$base = 'https://roland-bot.hello-071.workers.dev/reviews';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
// Allow-list: $op comes from the URL, and pasting it in unchecked would let anyone
// past the login aim this proxy at any Worker route.
$op = $_GET['op'] ?? '';
if (!in_array($op, ['act', 'redraft'], true)) $op = '';
$target = ($isPost && $op !== '') ? $base . '/' . $op : $base;

$postBody = $isPost ? file_get_contents('php://input') : '';
// A redraft is a model call: allow it the time one takes.
$timeout = ($op === 'redraft') ? 60 : 30;

$hdrs = ['Authorization: Basic ' . base64_encode("$user:$pass")];
if ($isPost) $hdrs[] = 'Content-Type: application/json';
$ch = curl_init($target);
$opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdrs, CURLOPT_TIMEOUT => $timeout];
if ($isPost) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $postBody; }
curl_setopt_array($ch, $opts);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'text/html; charset=utf-8';
curl_close($ch);

http_response_code($code ?: 502);
header('Content-Type: ' . $ctype);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
echo $body !== false ? $body : 'Reviews page temporarily unavailable.';
