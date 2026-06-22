<?php
// Admin actions proxy. The team is authenticated here via Basic Auth (.htaccess);
// we call the worker's token-gated /admin/* endpoints on their behalf, with the
// token kept server-side (ADMIN_TOKEN in .env) so it's never exposed to the browser.
$tok = getenv('ADMIN_TOKEN');
if (!$tok && file_exists(__DIR__ . '/.env')) {
  foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    if (str_starts_with($l, 'ADMIN_TOKEN=')) { $tok = trim(substr($l, 12)); break; }
  }
}
$do = $_GET['do'] ?? '';
$allow = ['sync', 'stats', 'learn-run', 'booking-poll'];   // safe, idempotent actions only
if (!in_array($do, $allow, true)) { http_response_code(400); header('Content-Type: application/json'); die('{"error":"bad action"}'); }
if (!$tok) { http_response_code(500); header('Content-Type: application/json'); die('{"error":"ADMIN_TOKEN not configured"}'); }

$url = 'https://roland-bot.hello-071.workers.dev/admin/' . $do . '?token=' . urlencode($tok);
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($code ?: 502);
header('Content-Type: application/json');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
echo $body !== false ? $body : '{"error":"unavailable"}';
