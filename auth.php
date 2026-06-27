<?php
// Cookie-session auth for the team panel — log in ONCE, stay in ~30 days, instead of
// Apache re-prompting HTTP Basic Auth on every visit (painful on mobile). Replaces the
// per-file Basic Auth in .htaccess for the PHP pages. Deny-by-default; when there is no
// valid cookie it falls back to a Basic Auth prompt — that prompt is also the login
// step (on success we set the cookie), so nobody can be locked out by a cookie hiccup.
//
// REQUIRES two values in .env (same folder, already web-blocked by .htaccess):
//   WORKER_PASS=<the team panel password — the SAME value as the Worker's INBOX_PASSWORD>
//   SESSION_SECRET=<a long random string, e.g. `openssl rand -hex 32`>
//
// After auth, it sets $user/$pass for the proxies that forward credentials to the Worker.

$__env = [];
$__f = __DIR__ . '/.env';
if (is_readable($__f)) foreach (file($__f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__l) {
  if ($__l === '' || $__l[0] === '#' || strpos($__l, '=') === false) continue;
  [$__k, $__v] = explode('=', $__l, 2);
  $__env[trim($__k)] = trim($__v);
}
$WORKER_PASS = getenv('WORKER_PASS') ?: ($__env['WORKER_PASS'] ?? '');
$SECRET      = getenv('SESSION_SECRET') ?: ($__env['SESSION_SECRET'] ?? '');
$COOKIE = 'cap_session';
$TTL = 60 * 60 * 24 * 30; // 30 days

$cap_prompt = function () {
  header('WWW-Authenticate: Basic realm="Central Apartments - team only", charset="UTF-8"');
  http_response_code(401);
  echo 'Authentication required';
  exit;
};

// Fail CLOSED: without the server-side password we cannot authenticate anyone — show a
// clear error rather than exposing the panel. (If you see this, add WORKER_PASS to .env.)
if ($WORKER_PASS === '') { http_response_code(500); exit('Auth not configured: set WORKER_PASS in .env'); }

$cap_token_ok = function ($v) use ($SECRET) {
  if ($SECRET === '' || !$v || strpos($v, '.') === false) return false;
  [$exp, $sig] = explode('.', $v, 2);
  return ctype_digit($exp) && (int) $exp > time()
    && hash_equals(hash_hmac('sha256', $exp, $SECRET), $sig);
};

$cap_authed = isset($_COOKIE[$COOKIE]) && $cap_token_ok($_COOKIE[$COOKIE]);

if (!$cap_authed) {
  // No valid cookie → Basic Auth is the login. Read creds (PHP-FPM exposes them via the
  // Authorization header the .htaccess forwards), validate, then drop a 30-day cookie.
  $u = $_SERVER['PHP_AUTH_USER'] ?? '';
  $p = $_SERVER['PHP_AUTH_PW'] ?? '';
  if ($u === '') {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($h, 'Basic ') === 0) {
      $d = base64_decode(substr($h, 6));
      if ($d !== false && strpos($d, ':') !== false) [$u, $p] = explode(':', $d, 2);
    }
  }
  if ($u !== 'team' || !hash_equals($WORKER_PASS, (string) $p)) $cap_prompt();
  if ($SECRET !== '') {
    $exp = time() + $TTL;
    setcookie($COOKIE, $exp . '.' . hash_hmac('sha256', (string) $exp, $SECRET),
      ['expires' => $exp, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
  }
}

// Credentials the forwarding proxies send to the Worker (kept server-side now).
$user = 'team';
$pass = $WORKER_PASS;
