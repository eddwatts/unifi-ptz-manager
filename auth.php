<?php
/**
 * auth.php — Google OAuth 2.0 callback and session management.
 *
 * Flow:
 *   login.php → ?action=login → Google consent → ?action=callback → index.php
 *
 * Endpoints (via ?action=):
 *   login     — build Google auth URL and redirect
 *   callback  — exchange code, verify email, create session
 *   logout    — destroy session and return to login
 */

require_once __DIR__ . '/session.php';
require_once '/etc/ptz/config.php';

$action = $_GET['action'] ?? 'login';

match ($action) {
    'login'    => do_login(),
    'callback' => do_callback(),
    'logout'   => do_logout(),
    default    => redirect('login.php'),
};

// ── Actions ───────────────────────────────────────────────────────────────────

function do_login(): void
{
    // CSRF state token
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    // Store where to send the user after login (default: index.php)
    $_SESSION['oauth_return'] = $_GET['return'] ?? 'index.php';

    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account',   // always show account picker
        'state'         => $state,
    ]);

    redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
}

function do_callback(): void
{
    // ── Validate state (CSRF) ─────────────────────────────────────────────────
    $state = $_GET['state'] ?? '';
    if (!$state || !isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
        auth_error('Invalid state parameter — possible CSRF attempt. Please try again.');
    }
    unset($_SESSION['oauth_state']);

    // ── User denied or error from Google ──────────────────────────────────────
    if (isset($_GET['error'])) {
        auth_error('Google sign-in was cancelled or failed: ' . htmlspecialchars($_GET['error']));
    }

    $code = $_GET['code'] ?? '';
    if (!$code) {
        auth_error('No authorisation code returned from Google.');
    }

    // ── Exchange code for tokens ──────────────────────────────────────────────
    $tokenResponse = google_post('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);

    if (empty($tokenResponse['access_token'])) {
        auth_error('Failed to obtain access token from Google. Check your Client Secret.');
    }

    // ── Fetch user info ───────────────────────────────────────────────────────
    $user = google_get(
        'https://www.googleapis.com/oauth2/v2/userinfo',
        $tokenResponse['access_token']
    );

    if (empty($user['email'])) {
        auth_error('Could not retrieve email address from Google.');
    }

    // Google marks unverified accounts — reject them
    if (!($user['email_verified'] ?? false)) {
        auth_error('Your Google account email is not verified. Please verify it first.');
    }

    $email = strtolower(trim($user['email']));

    // ── Allowlist check ───────────────────────────────────────────────────────
    $reason = '';
    if (!is_email_allowed($email, $reason)) {
        log_login($email, $user['name'] ?? '', false, $reason);
        auth_error(
            "denied:{$email}:{$reason}"   // decoded by login.php for a clear message
        );
    }

    // ── Create authenticated session ──────────────────────────────────────────
    session_regenerate_id(true);   // prevent session fixation

    $authName = $user['name'] ?? $email;

    $_SESSION['auth'] = [
        'email'   => $email,
        'name'    => $authName,
        'picture' => $user['picture'] ?? '',
        'sub'     => $user['id']      ?? '',
        'at'      => time(),
        'reason'  => $reason,   // how they were authorised (admin/db_user)
    ];

    // Record successful login
    log_login($email, $authName, true, $reason);

    $return = $_SESSION['oauth_return'] ?? 'index.php';
    unset($_SESSION['oauth_return']);

    redirect($return);
}

function do_logout(): void
{
    // Log the logout before destroying the session
    $email = $_SESSION['auth']['email'] ?? 'unknown';
    try {
        $pdo = get_db();
        $pdo->exec(
            "INSERT IGNORE INTO cameras (id, name, is_ptz, enabled)
             VALUES ('system', 'System', 0, 0)"
        );
        $pdo->prepare(
            "INSERT INTO action_log
                (camera_id, camera_name, action, detail, triggered_by, actor, ip_address)
             VALUES ('system', 'System', 'logout', ?, 'manual', ?, ?)"
        )->execute(["User logged out [{$email}]", $email, get_client_ip()]);
    } catch (Throwable) {}

    session_unset();
    session_destroy();
    redirect('login.php');
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Also always allows ADMIN_EMAIL if defined.
 */
/**
 * Check whether an email is authorised to access the system.
 *
 * Check order:
 *  1. ADMIN_EMAIL constant — always has access, cannot be revoked via UI
 *  2. access_users DB table — individually granted accounts only
 *
 * Access is explicit and per-person. There is no domain wildcard —
 * CCTV access must be granted to each user individually.
 *
 * Returns true if allowed, false if denied.
 * Sets $reason output param: 'admin' | 'db_user' | 'revoked' | 'not_found'
 */
function is_email_allowed(string $email, string &$reason = ''): bool
{
    $admin = defined('ADMIN_EMAIL') ? strtolower(trim(ADMIN_EMAIL)) : '';

    // 1. Admin email — unconditional, cannot be revoked
    if ($admin && $email === $admin) {
        $reason = 'admin';
        return true;
    }

    // 2. DB access_users table — explicit per-user grants
    try {
        $stmt = get_db()->prepare(
            "SELECT enabled FROM access_users WHERE email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            if ((int)$user['enabled'] === 1) {
                $reason = 'db_user';
                return true;
            }
            $reason = 'revoked';
            return false;
        }
    } catch (Throwable) {
        // DB unavailable — fail closed
    }

    $reason = 'not_found';
    return false;
}

/**
 * Record a successful login or denial in action_log and update access_users.
 * Uses camera_id = 'system' as a placeholder (no camera involved).
 */
function log_login(string $email, string $name, bool $success, string $reason): void
{
    try {
        $pdo    = get_db();
        $action = $success ? 'login' : 'login_denied';
        $ip     = get_client_ip();

        $detailMap = [
            'admin'     => "Admin account login",
            'db_user'   => "Authorised user login",
            'revoked'   => "Login denied — account revoked",
            'not_found' => "Login denied — account not on access list",
        ];
        $detail = ($detailMap[$reason] ?? ucfirst($reason)) . " [{$email}] from {$ip}";

        // Ensure 'system' placeholder row exists
        $pdo->exec(
            "INSERT IGNORE INTO cameras (id, name, is_ptz, enabled)
             VALUES ('system', 'System', 0, 0)"
        );

        $pdo->prepare(
            "INSERT INTO action_log
                (camera_id, camera_name, action, detail, triggered_by, actor, ip_address)
             VALUES ('system', 'System', ?, ?, 'manual', ?, ?)"
        )->execute([$action, $detail, $email, $ip]);

        // Update last_login and login_count on successful login
        if ($success) {
            $pdo->prepare(
                "INSERT INTO access_users (email, name, enabled, last_login, login_count)
                 VALUES (?, ?, 1, NOW(), 1)
                 ON DUPLICATE KEY UPDATE
                     name        = COALESCE(VALUES(name), name),
                     last_login  = NOW(),
                     login_count = login_count + 1"
            )->execute([$email, $name ?: $email]);
        }
    } catch (Throwable $e) {
        error_log('PTZ log_login error: ' . $e->getMessage());
    }
}

/**
 * Get the real client IP, accounting for Cloudflare headers.
 * Duplicate of api.php helper — auth.php doesn't include api.php.
 */
function get_client_ip(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP) ?: 'unknown';
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        return filter_var($ip, FILTER_VALIDATE_IP) ?: 'unknown';
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function google_post(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode($raw ?: '{}', true) ?? [];
}

function google_get(string $url, string $accessToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode($raw ?: '{}', true) ?? [];
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function auth_error(string $message): never
{
    // Pass message to login page via session flash
    $_SESSION['auth_error'] = $message;
    redirect('login.php');
}
