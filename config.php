<?php
/**
 * config.php — non-sensitive application settings.
 * Location: /etc/ptz/config.php  (outside web root)
 *
 * Credentials live alongside this file in /etc/ptz/secrets.php
 * Both files are owned root:www-data 640 — readable by the web server,
 * writable only by root, invisible to Nginx entirely.
 *
 * Python daemon reads both files via regex (see daemon/patrol.py).
 * Keep values as plain strings/ints only.
 */

// ── Application settings ──────────────────────────────────────────────────────
define('APP_TIMEZONE',        'Europe/London');
define('APP_VERSION',         '1.0.0');
define('SYNC_INTERVAL_HOURS',  6);

// ── Automatic updates ─────────────────────────────────────────────────────────
define('AUTO_UPDATE',      'y');     // 'y' or 'n'
define('AUTO_UPDATE_TIME', '03:00'); // HH:MM server local time

// ── Log retention ────────────────────────────────────────────────────────────
// How many days to keep action_log entries. Older entries are purged by the
// nightly cron job. Set to 0 to disable purging (keep forever).
define('LOG_RETENTION_DAYS', 90);

// ── PTZ command retry behaviour ──────────────────────────────────────────────
// How many times the daemon retries a failed API command before giving up.
define('PTZ_MAX_RETRIES',         3);
// Seconds to wait after issuing a command before polling camera online status.
// The API returns 204 (accepted) immediately — this pause lets the camera act.
define('PTZ_VERIFY_DELAY_SECS',   3);
// Seconds between retry attempts (grows with each attempt: 5s, 15s, 30s).
// Not configurable here — see RETRY_BACKOFF_SECS in daemon/patrol.py.

// ── Dashboard behaviour ──────────────────────────────────────────────────────
// How often the dashboard auto-refreshes camera snapshots (seconds)
define('SNAPSHOT_REFRESH_SECONDS', 30);

// Whether /status.php is publicly accessible without a login session.
// Set 'y' to allow external monitors (Uptime Kuma, Zabbix etc) to poll it.
// Set 'n' to require an authenticated Google session even for status checks.
define('STATUS_PUBLIC', 'y');

// ── API rate limiting ─────────────────────────────────────────────────────────
define('RATE_LIMIT_READS',    120);  // read actions per window
define('RATE_LIMIT_WRITES',    30);  // write/mutating actions per window
define('RATE_LIMIT_WINDOW',    60);  // window size in seconds

// ── Paths ─────────────────────────────────────────────────────────────────────
define('SECRETS_FILE', '/etc/ptz/secrets.php');
define('INSTALL_DIR',  '/var/www/ptz');

// ── Timezone ──────────────────────────────────────────────────────────────────
date_default_timezone_set(APP_TIMEZONE);

// ── Load credentials ──────────────────────────────────────────────────────────
if (file_exists(SECRETS_FILE)) {
    require_once SECRETS_FILE;
} else {
    // Secrets not written yet — define empty stubs so pages can check defined()
    // The setup wizard will write secrets.php on first save
    foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS',
              'NVR_IP','API_TOKEN','API_BASE',
              'GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET','GOOGLE_REDIRECT_URI',
              'ADMIN_EMAIL','APP_SECRET'] as $k) {
        if (!defined($k)) define($k, '');
    }
    if (!defined('DB_PORT')) define('DB_PORT', 3306);
}

// ── DB connection ─────────────────────────────────────────────────────────────
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }
    return $pdo;
}
