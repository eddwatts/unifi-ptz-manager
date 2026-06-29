<?php
/**
 * session.php — centralised session configuration.
 *
 * Include BEFORE session_start() on every page that uses sessions.
 * Sets HttpOnly, Secure, SameSite=Strict cookie flags and a
 * conservative session lifetime.
 *
 * Usage (replaces bare session_start()):
 *   require_once __DIR__ . '/session.php';
 *   // session is already started
 */

if (session_status() !== PHP_SESSION_NONE) {
    return;   // already started elsewhere — don't double-configure
}

// ── Cookie security flags ─────────────────────────────────────────────────────
$secure   = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$lifetime = 0;      // session cookie — expires when browser closes
$path     = '/';
$domain   = '';     // current domain only

session_set_cookie_params([
    'lifetime' => $lifetime,
    'path'     => $path,
    'domain'   => $domain,
    'secure'   => $secure,      // HTTPS only (Cloudflare Tunnel always HTTPS)
    'httponly' => true,          // inaccessible to JavaScript — blocks XSS token theft
    'samesite' => 'Strict',     // no cross-site sending — blocks CSRF
]);

// ── Session hardening ─────────────────────────────────────────────────────────
ini_set('session.use_strict_mode',    '1');  // reject unrecognised session IDs
ini_set('session.use_only_cookies',   '1');  // no session ID in URL
ini_set('session.cookie_httponly',    '1');  // belt-and-braces for older PHP
ini_set('session.cookie_samesite',    'Strict');
ini_set('session.gc_maxlifetime',     (string)(8 * 3600));  // 8h server-side TTL
ini_set('session.sid_length',         '64');   // longer session ID
ini_set('session.sid_bits_per_character', '6'); // more entropy per char

session_name('PTZ_SESSION');   // don't leak that this is PHP (default PHPSESSID)

session_start();
