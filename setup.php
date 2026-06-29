<?php
/**
 * setup.php — first-time setup wizard AND settings editor.
 *
 * Behaviour:
 *   - No config / incomplete config / missing DB tables → wizard mode (3-step)
 *   - Config exists and DB is healthy                  → settings mode (single form, pre-populated)
 *   - ?force=1 always shows settings mode
 *
 * Settings mode lets you change: NVR IP, API token, DB credentials,
 * timezone, and SYNC_INTERVAL_HOURS without touching files manually.
 *
 * Credentials are written to /etc/ptz/secrets.php (outside web root).
 * Non-sensitive settings go to /etc/ptz/config.php.
 */

$configPath = '/etc/ptz/config.php';
$secretsPath = '/etc/ptz/secrets.php';

// Protect settings page once configured — wizard is exempt (no Google config yet)
// auth_check is included after we know we're in settings mode
$isConfigured = false;

// Load existing config if present (suppress redeclaration errors on re-save)
if (file_exists($configPath)) {
    @include_once $configPath;
    if (defined('NVR_IP') && defined('API_TOKEN') && defined('DB_NAME')) {
        try {
            get_db();
            $tbls = get_db()->query("SHOW TABLES LIKE 'cameras'")->fetchAll();
            $isConfigured = count($tbls) > 0;
        } catch (Throwable) {}
    }
}

// Settings mode = already configured (or ?force=1 skips the auto-redirect check)
$settingsMode = $isConfigured;   // true → single-page settings, false → wizard

// Only protect settings mode — wizard must be accessible before Google is set up
if ($settingsMode && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') {
        require_once __DIR__ . '/auth_check.php';
    }
}

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $step   = $_POST['step'] ?? '';
    $result = ['ok' => false, 'error' => 'Unknown step'];

    try {
        switch ($step) {

            // ── Test NVR connection ───────────────────────────────────────
            case 'test_nvr':
                $ip    = filter_var(trim($_POST['nvr_ip'] ?? ''), FILTER_VALIDATE_IP);
                $token = trim($_POST['api_token'] ?? '');
                if (!$ip || !$token) throw new RuntimeException('IP address and API token are required.');

                $ch = curl_init("https://{$ip}/proxy/protect/integration/v1/cameras");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_HTTPHEADER     => ["X-API-Key: {$token}", 'Content-Type: application/json'],
                ]);
                $raw  = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);

                if ($err)         throw new RuntimeException("Connection failed: {$err}");
                if ($code === 401) throw new RuntimeException('API token rejected — check Protect → Settings → Control Plane → Integrations.');
                if ($code !== 200) throw new RuntimeException("Unexpected response from NVR (HTTP {$code}). Is the IP correct?");

                $data    = json_decode($raw, true);
                $cameras = $data['data'] ?? [];
                $ptz     = array_filter($cameras, fn($c) => $c['isPtz'] ?? false);

                $result = ['ok' => true, 'total_cameras' => count($cameras), 'ptz_count' => count($ptz)];
                break;

            // ── Test DB connection ────────────────────────────────────────
            case 'test_db':
                $host = trim($_POST['db_host'] ?? 'localhost');
                $name = trim($_POST['db_name'] ?? '');
                $user = trim($_POST['db_user'] ?? '');
                $pass = $_POST['db_pass'] ?? '';
                $port = (int)($_POST['db_port'] ?? 3306);

                if (!$name || !$user) throw new RuntimeException('Database name and username are required.');

                $pdo = new PDO(
                    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                    $user, $pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $result = ['ok' => true, 'message' => 'Database connection successful.'];
                break;

            // ── Save / update config.php ──────────────────────────────────
            case 'save_config':
                $nvrIp    = filter_var(trim($_POST['nvr_ip'] ?? ''), FILTER_VALIDATE_IP);
                $token    = trim($_POST['api_token'] ?? '');
                $dbHost   = trim($_POST['db_host'] ?? 'localhost');
                $dbName   = trim($_POST['db_name'] ?? '');
                $dbUser   = trim($_POST['db_user'] ?? '');
                $dbPass   = $_POST['db_pass'] ?? '';
                $dbPort   = (int)($_POST['db_port'] ?? 3306);
                $tz       = trim($_POST['timezone'] ?? 'Europe/London');
                $syncHrs  = max(1, min(24, (int)($_POST['sync_hours'] ?? 6)));
                $isUpdate = ($_POST['is_update'] ?? '0') === '1';

                // Generate APP_SECRET on first save; preserve on update
                if ($_POST['is_update'] === '1' && defined('APP_SECRET') && APP_SECRET !== 'generate_a_random_32_byte_hex_string_here') {
                    $appSecret = APP_SECRET;
                } else {
                    $appSecret = bin2hex(random_bytes(32));
                }

                // Dashboard / status settings
                $snapshotRefresh    = max(10, min(300, (int)($_POST['snapshot_refresh']    ?? 30)));
                $logRetentionDays   = max(0,  min(365, (int)($_POST['log_retention_days'] ?? 90)));
                $statusPublic    = ($_POST['status_public'] ?? 'off') === 'on' ? 'y' : 'n';

                // Auto-update settings
                $autoUpdate     = (($_POST['auto_update'] ?? 'off') === 'on' || ($_POST['auto_update'] ?? '') === 'y') ? 'y' : 'n';
                $autoUpdateTime = preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $_POST['auto_update_time'] ?? '')
                    ? $_POST['auto_update_time']
                    : '03:00';

                // Google OAuth fields
                $googleClientId     = trim($_POST['google_client_id']     ?? '');
                $googleClientSecret = trim($_POST['google_client_secret'] ?? '');
                $googleRedirectUri  = trim($_POST['google_redirect_uri']  ?? '');
                $adminEmail         = strtolower(trim($_POST['admin_email']  ?? ''));

                if (!$nvrIp || !$token || !$dbName || !$dbUser) {
                    throw new RuntimeException('NVR IP, API token, database name and username are all required.');
                }
                if (!$adminEmail || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('A valid admin email address is required.');
                }

                // Keep existing secrets if fields left blank on update
                if ($isUpdate) {
                    if ($dbPass === '' && defined('DB_PASS')) $dbPass = DB_PASS;
                    if ($googleClientSecret === '' && defined('GOOGLE_CLIENT_SECRET')) $googleClientSecret = GOOGLE_CLIENT_SECRET;
                }

                $timestamp = date('Y-m-d H:i:s');
                $mode      = $isUpdate ? 'updated' : 'created';

                // ── Write config.php (non-sensitive settings only) ─────────────────
                $configContent = <<<PHP
<?php
/**
 * config.php — non-sensitive settings ({$mode} {$timestamp})
 * Credentials are in /etc/ptz/secrets.php (outside web root).
 */

// ── HTTP access guard ─────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli'
    && realpath(\$_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404); exit;
}

define('APP_TIMEZONE',        '{$tz}');
define('APP_VERSION',         '1.0.0');
define('SYNC_INTERVAL_HOURS',       {$syncHrs});
define('SNAPSHOT_REFRESH_SECONDS',  {$snapshotRefresh});
define('LOG_RETENTION_DAYS',        {$logRetentionDays});
define('STATUS_PUBLIC',            '{$statusPublic}');
define('AUTO_UPDATE',          '{$autoUpdate}');
define('AUTO_UPDATE_TIME',     '{$autoUpdateTime}');
define('RATE_LIMIT_READS',     120);
define('RATE_LIMIT_WRITES',     30);
define('RATE_LIMIT_WINDOW',     60);
define('SECRETS_FILE',         '/etc/ptz/secrets.php');
define('INSTALL_DIR',          '/var/www/ptz');

date_default_timezone_set(APP_TIMEZONE);

if (file_exists(SECRETS_FILE)) {
    require_once SECRETS_FILE;
} else {
    foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS','DB_PORT',
              'NVR_IP','API_TOKEN','API_BASE',
              'GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET','GOOGLE_REDIRECT_URI',
              'ADMIN_EMAIL','APP_SECRET'] as \$k) {
        if (!defined(\$k)) define(\$k, \$k === 'DB_PORT' ? 3306 : '');
    }
}

function get_db(): PDO {
    static \$pdo = null;
    if (\$pdo === null) {
        \$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME);
        \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }
    return \$pdo;
}
PHP;

                // ── Write /etc/ptz/secrets.php (credentials, outside web root) ──────
                $secretsDir = '/etc/ptz';
                $secretsPath = $secretsDir . '/secrets.php';
                $secretsContent = <<<PHP
<?php
/**
 * secrets.php — credential store for PTZ Patrol Manager.
 * Location: /etc/ptz/secrets.php  (outside web root)
 * Owner:    root:www-data   Mode: 640
 * {$mode} {$timestamp}
 */

define('DB_HOST', '{$dbHost}');
define('DB_NAME', '{$dbName}');
define('DB_USER', '{$dbUser}');
define('DB_PASS', '{$dbPass}');
define('DB_PORT', {$dbPort});

define('NVR_IP',    '{$nvrIp}');
define('API_TOKEN', '{$token}');
define('API_BASE',  'https://' . NVR_IP . '/proxy/protect/integration/v1');

define('GOOGLE_CLIENT_ID',     '{$googleClientId}');
define('GOOGLE_CLIENT_SECRET', '{$googleClientSecret}');
define('GOOGLE_REDIRECT_URI',  '{$googleRedirectUri}');
define('ADMIN_EMAIL',          '{$adminEmail}');

define('APP_SECRET', '{$appSecret}');
PHP;

                // Create /etc/ptz if it doesn't exist
                if (!is_dir($secretsDir)) {
                    if (!mkdir($secretsDir, 0750, true)) {
                        throw new RuntimeException("Could not create {$secretsDir} — run: sudo mkdir -p /etc/ptz && sudo chown root:www-data /etc/ptz && sudo chmod 750 /etc/ptz");
                    }
                }

                if (file_put_contents($configPath, $configContent) === false) {
                    throw new RuntimeException('Could not write /etc/ptz/config.php — check: sudo chown root:www-data /etc/ptz && sudo chmod 750 /etc/ptz');
                }

                if (file_put_contents($secretsPath, $secretsContent) === false) {
                    throw new RuntimeException("Could not write {$secretsPath} — run: sudo touch /etc/ptz/secrets.php && sudo chown root:www-data /etc/ptz/secrets.php && sudo chmod 640 /etc/ptz/secrets.php");
                }

                // Lock down secrets file
                @chmod($secretsPath, 0640);
                @chown($secretsPath, 'root');

                // (Re-)run schema — CREATE TABLE IF NOT EXISTS is safe to re-run
                // Re-include to pick up new constants (opcache may serve stale on update)
                if (function_exists('opcache_invalidate')) { opcache_invalidate($configPath, true); opcache_invalidate($secretsPath, true); }

                // Load fresh constants into this request's scope
                $freshCfg = [];
                foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS','DB_PORT'] as $k) {
                    $freshCfg[$k] = constant($k) ?? $${'db'.lcfirst(substr($k,3))};
                }

                $pdo = new PDO(
                    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                    $dbUser, $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                // ── Schema executor ───────────────────────────────────────────────
                // Simple split-on-semicolon breaks multi-line ALTER TABLE blocks and
                // chokes on -- comments containing semicolons. This parser:
                //   1. Strips -- line comments
                //   2. Strips /* */ block comments
                //   3. Splits on semicolons that are not inside string literals
                //   4. Skips empty statements
                $schema = file_get_contents(__DIR__ . '/sql/schema.sql');
                $executed  = 0;
                $skipped   = 0;
                $errors    = [];

                foreach (parse_sql_statements($schema) as $stmt) {
                    try {
                        $pdo->exec($stmt);
                        $executed++;
                    } catch (PDOException $e) {
                        // Duplicate index errors are harmless on re-run
                        $code = (int)$e->getCode();
                        $msg  = $e->getMessage();
                        if (str_contains($msg, 'Duplicate key name') ||
                            str_contains($msg, 'already exists') ||
                            str_contains($msg, 'Duplicate column name')) {
                            $skipped++;
                        } else {
                            $errors[] = substr($stmt, 0, 80) . '… — ' . $msg;
                        }
                    }
                }

                if ($errors) {
                    throw new RuntimeException(
                        'Schema error(s): ' . implode(' | ', array_slice($errors, 0, 3))
                    );
                }

                $tableMsg = "Schema: {$executed} statement(s) executed"
                    . ($skipped ? ", {$skipped} already exist (skipped)" : '') . '.';

                // Seed admin user into access_users on first install
                // (so they appear in the user list and login is tracked)
                if (!$isUpdate && !empty($adminEmail)) {
                    try {
                        $pdo->prepare(
                            "INSERT INTO access_users (email, role, enabled, notes, added_by)
                             VALUES (?, 'admin', 1, 'Initial admin — added by setup wizard', ?)
                             ON DUPLICATE KEY UPDATE role = 'admin', enabled = 1"
                        )->execute([strtolower($adminEmail), strtolower($adminEmail)]);
                    } catch (Throwable) {}
                }

                // Audit: log config changes (settings updates only, not first install)
                if ($isUpdate) {
                    try {
                        $pdo->exec("INSERT IGNORE INTO cameras (id, name, is_ptz, enabled)
                                    VALUES ('system', 'System', 0, 0)");
                        $actor = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'setup';
                        // Try to get logged-in user from session
                        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['auth']['email'])) {
                            $actor = $_SESSION['auth']['email'];
                        }
                        $ip = !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
                            ? filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)
                            : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                        $pdo->prepare(
                            "INSERT INTO action_log
                                (camera_id, camera_name, action, detail, triggered_by, actor, ip_address)
                             VALUES ('system', 'System', 'config_change', ?, 'manual', ?, ?)"
                        )->execute(["System settings updated via setup wizard", $actor, $ip ?: 'unknown']);
                    } catch (Throwable) {}
                }

                $result = [
                    'ok'      => true,
                    'updated' => $isUpdate,
                    'message' => ($isUpdate ? 'Settings saved. ' : 'Configuration saved. ') . $tableMsg,
                    'schema'  => ['executed' => $executed, 'skipped' => $skipped],
                ];
                break;
        }
    } catch (Throwable $e) {
        $result = ['ok' => false, 'error' => $e->getMessage()];
    }

    echo json_encode($result);
    exit;
}

// ── SQL parser ────────────────────────────────────────────────────────────────
/**
 * Parse a SQL file into individual executable statements.
 *
 * Handles:
 *   - Single-line comments  (-- comment)
 *   - Block comments        (/* comment *\/)
 *   - String literals       ('value with ; inside')
 *   - Multi-line statements (ALTER TABLE ... ADD COLUMN ...,
    ADD COLUMN ...)
 *   - Unicode in comments   (box-drawing characters etc.)
 *
 * Returns array of trimmed non-empty SQL statements, without trailing semicolons.
 */
function parse_sql_statements(string $sql): array
{
    $statements = [];
    $current    = '';
    $inString   = false;
    $stringChar = '';
    $inBlock    = false;
    $len        = strlen($sql);

    $i = 0;
    while ($i < $len) {
        $ch   = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        // Inside a block comment /* ... */
        if ($inBlock) {
            if ($ch === '*' && $next === '/') {
                $inBlock = false;
                $i += 2;
            } else {
                $i++;
            }
            continue;
        }

        // Inside a string literal '...' or "..."
        if ($inString) {
            if ($ch === '\\') {
                // Escaped character — skip both chars
                $current .= $ch . $next;
                $i += 2;
                continue;
            }
            if ($ch === $stringChar) {
                $inString = false;
            }
            $current .= $ch;
            $i++;
            continue;
        }

        // Start of block comment
        if ($ch === '/' && $next === '*') {
            $inBlock = true;
            $i += 2;
            continue;
        }

        // Single-line comment -- skip to end of line
        if ($ch === '-' && $next === '-') {
            while ($i < $len && $sql[$i] !== "
") {
                $i++;
            }
            // Keep the newline for whitespace normalisation
            $current .= ' ';
            continue;
        }

        // Start of string literal
        if ($ch === "'" || $ch === '"') {
            $inString   = true;
            $stringChar = $ch;
            $current   .= $ch;
            $i++;
            continue;
        }

        // Statement terminator
        if ($ch === ';') {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $ch;
        $i++;
    }

    // Catch final statement without trailing semicolon
    $stmt = trim($current);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return array_values(array_filter($statements, fn($s) => trim($s) !== ''));
}

// ── Pre-populate values for settings mode ─────────────────────────────────────
$pre = [
    'nvr_ip'          => defined('NVR_IP')              ? NVR_IP              : '',
    'api_token'       => defined('API_TOKEN')            ? API_TOKEN           : '',
    'db_host'         => defined('DB_HOST')              ? DB_HOST             : 'localhost',
    'db_name'         => defined('DB_NAME')              ? DB_NAME             : '',
    'db_user'         => defined('DB_USER')              ? DB_USER             : '',
    'db_port'         => defined('DB_PORT')              ? DB_PORT             : 3306,
    'timezone'        => defined('APP_TIMEZONE')         ? APP_TIMEZONE        : 'Europe/London',
    'sync_hours'         => defined('SYNC_INTERVAL_HOURS')       ? SYNC_INTERVAL_HOURS       : 6,
    'snapshot_refresh'   => defined('SNAPSHOT_REFRESH_SECONDS')  ? SNAPSHOT_REFRESH_SECONDS  : 30,
    'log_retention_days' => defined('LOG_RETENTION_DAYS')         ? LOG_RETENTION_DAYS         : 90,
    'status_public'      => defined('STATUS_PUBLIC')              ? STATUS_PUBLIC              : 'y',
    'google_client_id'     => defined('GOOGLE_CLIENT_ID')     ? GOOGLE_CLIENT_ID     : '',
    'google_client_secret' => defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '',
    'google_redirect_uri'  => defined('GOOGLE_REDIRECT_URI')  ? GOOGLE_REDIRECT_URI  : '',
    'admin_email'          => defined('ADMIN_EMAIL')          ? ADMIN_EMAIL          : '',
    'auto_update'      => defined('AUTO_UPDATE')      ? AUTO_UPDATE      : 'y',
    'auto_update_time' => defined('AUTO_UPDATE_TIME') ? AUTO_UPDATE_TIME : '03:00',
    // Passwords intentionally blank in settings mode
];

$timezones = [
    'Europe/London','Europe/Paris','Europe/Berlin','Europe/Amsterdam',
    'America/New_York','America/Chicago','America/Denver','America/Los_Angeles',
    'Australia/Sydney','Asia/Singapore','UTC',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PTZ Patrol Manager — <?= $settingsMode ? 'Settings' : 'Setup' ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #0d1117;
    --surface:   #161b22;
    --border:    #30363d;
    --accent:    #00b4d8;
    --accent-dk: #0096b7;
    --success:   #3fb950;
    --warning:   #d29922;
    --error:     #f85149;
    --text:      #e6edf3;
    --muted:     #7d8590;
    --radius:    8px;
  }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 2rem 1rem;
  }

  .wrap { width: 100%; max-width: 560px; }

  /* ── Header ── */
  .header { text-align: center; margin-bottom: 2rem; }
  .header svg { color: var(--accent); margin-bottom: .75rem; }
  .header h1  { font-size: 1.5rem; font-weight: 700; letter-spacing: -.02em; }
  .header p   { color: var(--muted); margin-top: .35rem; font-size: .9rem; }

  .back-link {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .8rem; color: var(--muted); text-decoration: none;
    margin-bottom: 1.5rem;
    transition: color .15s;
  }
  .back-link:hover { color: var(--text); }

  /* ── Wizard steps ── */
  .steps {
    display: flex;
    justify-content: center;
    margin-bottom: 1.75rem;
  }
  .step-dot {
    display: flex; align-items: center; gap: .4rem;
    font-size: .8rem; color: var(--muted);
  }
  .step-dot .num {
    width: 26px; height: 26px; border-radius: 50%;
    border: 2px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-weight: 600; font-size: .78rem;
    transition: all .25s;
  }
  .step-dot.active .num { border-color: var(--accent); color: var(--accent); }
  .step-dot.done .num   { border-color: var(--success); background: var(--success); color: #000; }
  .step-dot .label      { display: none; }
  @media (min-width: 380px) { .step-dot .label { display: inline; } }
  .step-connector { width: 36px; height: 2px; background: var(--border); align-self: center; margin: 0 .2rem; }

  /* ── Card ── */
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.75rem;
    display: none;
  }
  .card.active { display: block; }

  .card h2    { font-size: 1.05rem; font-weight: 600; margin-bottom: .25rem; }
  .card .desc { font-size: .85rem; color: var(--muted); margin-bottom: 1.4rem; line-height: 1.5; }

  /* ── Settings sections ── */
  .settings-section {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 1.1rem;
    overflow: hidden;
  }
  .settings-section-header {
    background: rgba(255,255,255,.03);
    border-bottom: 1px solid var(--border);
    padding: .65rem 1rem;
    display: flex; align-items: center; gap: .5rem;
    font-size: .78rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--muted);
  }
  .settings-section-header svg { color: var(--accent); }
  .settings-section-body { padding: 1.1rem 1rem; display: flex; flex-direction: column; gap: .9rem; }

  /* ── Form fields ── */
  .field label {
    display: block; font-size: .78rem; font-weight: 600;
    color: var(--muted); text-transform: uppercase;
    letter-spacing: .05em; margin-bottom: .35rem;
  }
  .field input, .field select {
    width: 100%;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 6px; color: var(--text);
    padding: .55rem .75rem; font-size: .9rem;
    outline: none; transition: border-color .2s;
  }
  .field input:focus, .field select:focus { border-color: var(--accent); }
  .field .hint { font-size: .75rem; color: var(--muted); margin-top: .3rem; line-height: 1.4; }

  .field-row   { display: grid; grid-template-columns: 1fr auto; gap: .6rem; align-items: end; }
  .field-small input { width: 90px; }

  /* Token reveal */
  .token-wrap { position: relative; }
  .token-wrap input { padding-right: 2.5rem; font-family: monospace; font-size: .82rem; }
  .token-toggle {
    position: absolute; right: .5rem; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: var(--muted); cursor: pointer;
    padding: .2rem; border-radius: 3px;
  }
  .token-toggle:hover { color: var(--text); }

  /* Sync hours widget */
  .sync-row {
    display: flex; align-items: center; gap: .75rem;
  }
  .sync-row input[type="range"] {
    flex: 1; accent-color: var(--accent);
    background: none; border: none; padding: 0;
  }
  .sync-val {
    min-width: 44px; text-align: center;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 5px; padding: .3rem .5rem;
    font-size: .85rem; font-weight: 600; color: var(--accent);
  }

  /* ── Alert ── */
  .alert { display: none; padding: .7rem .9rem; border-radius: 6px; font-size: .85rem; margin-bottom: .9rem; border: 1px solid transparent; line-height: 1.4; }
  .alert.show    { display: block; }
  .alert.success { background: #0d2818; border-color: #1a4731; color: var(--success); }
  .alert.error   { background: #200c0c; border-color: #491010; color: var(--error); }
  .alert.warning { background: #1c1505; border-color: #4a3500; color: var(--warning); }

  /* ── Buttons ── */
  .btn-row { display: flex; justify-content: space-between; align-items: center; margin-top: 1.4rem; gap: .6rem; }
  button { padding: .55rem 1.1rem; border: none; border-radius: 6px; font-size: .875rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: .4rem; transition: opacity .15s, background .15s; }
  button:disabled { opacity: .45; cursor: not-allowed; }
  .btn-primary { background: var(--accent); color: #000; margin-left: auto; }
  .btn-primary:hover:not(:disabled) { background: var(--accent-dk); }
  .btn-ghost   { background: transparent; color: var(--muted); border: 1px solid var(--border); }
  .btn-ghost:hover { color: var(--text); }
  .btn-test    { background: transparent; color: var(--accent); border: 1px solid var(--accent); font-size: .82rem; padding: .4rem .85rem; }
  .btn-test:hover:not(:disabled) { background: rgba(0,180,216,.1); }
  .btn-danger  { background: transparent; color: var(--error); border: 1px solid rgba(248,81,73,.4); font-size: .82rem; padding: .4rem .85rem; }
  .btn-danger:hover { background: rgba(248,81,73,.08); }

  /* ── Finish / success ── */
  .finish-box { text-align: center; padding: 1rem 0; }
  .finish-box .tick { width: 52px; height: 52px; border-radius: 50%; background: rgba(63,185,80,.12); border: 2px solid var(--success); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.1rem; color: var(--success); }
  .finish-box h2 { margin-bottom: .4rem; }
  .finish-box p  { color: var(--muted); font-size: .875rem; }
  .finish-box a  { display: inline-flex; align-items: center; gap: .4rem; margin-top: 1.4rem; background: var(--accent); color: #000; padding: .65rem 1.4rem; border-radius: 6px; text-decoration: none; font-weight: 600; }

  /* ── Divider ── */
  .divider { border: none; border-top: 1px solid var(--border); margin: 1.1rem 0; }

  @keyframes spin { to { transform: rotate(360deg); } }
  .spin { animation: spin .7s linear infinite; display: inline-block; }

  /* ── Cloudflare tunnel UI ── */
  .tunnel-steps { display: flex; flex-direction: column; gap: .5rem; margin-bottom: 1.1rem; }
  .tunnel-step {
    display: flex; gap: .85rem; align-items: flex-start;
    background: rgba(255,255,255,.02);
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: .75rem .9rem;
  }
  .tunnel-step .step-num {
    width: 22px; height: 22px; border-radius: 50%;
    background: rgba(0,180,216,.15); border: 1px solid rgba(0,180,216,.35);
    color: var(--accent); font-size: .72rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    margin-top: .05rem;
  }
  .tunnel-step .step-body { font-size: .84rem; line-height: 1.55; color: var(--muted); }
  .tunnel-step .step-body strong { color: var(--text); }
  .tunnel-step .step-body a { color: var(--accent); }
  .tunnel-step .step-body code {
    background: rgba(255,255,255,.07); border: 1px solid var(--border);
    border-radius: 4px; padding: .1rem .4rem; font-size: .8rem; color: var(--text);
  }

  .cf-paste-wrap { position: relative; margin-top: .5rem; }
  .cf-paste-wrap textarea {
    width: 100%; background: var(--bg); border: 1px solid var(--border);
    border-radius: 6px; color: var(--text); padding: .65rem .8rem;
    font-family: monospace; font-size: .82rem; resize: vertical; min-height: 80px;
    outline: none; line-height: 1.5; transition: border-color .2s;
  }
  .cf-paste-wrap textarea:focus { border-color: var(--accent); }
  .cf-paste-wrap textarea::placeholder { color: var(--muted); font-family: monospace; }

  .tunnel-status-badge {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .25rem .65rem; border-radius: 20px; font-size: .75rem; font-weight: 600;
  }
  .tsb-running   { background: rgba(63,185,80,.12);  border: 1px solid rgba(63,185,80,.3);  color: var(--success); }
  .tsb-stopped   { background: rgba(248,81,73,.1);   border: 1px solid rgba(248,81,73,.3);  color: var(--error); }
  .tsb-unknown   { background: rgba(125,133,144,.1); border: 1px solid var(--border);       color: var(--muted); }
  .tsb-connected { background: rgba(0,180,216,.1);   border: 1px solid rgba(0,180,216,.3);  color: var(--accent); }

  .status-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
  .dot-green  { background: var(--success); box-shadow: 0 0 6px var(--success); }
  .dot-red    { background: var(--error); }
  .dot-grey   { background: var(--muted); }

  .cf-log {
    background: #0a0d11; border: 1px solid var(--border); border-radius: 6px;
    padding: .65rem .8rem; font-family: monospace; font-size: .75rem;
    color: #8b949e; max-height: 160px; overflow-y: auto; white-space: pre-wrap;
    line-height: 1.5; margin-top: .75rem; display: none;
  }
  .cf-log.show { display: block; }

  .btn-row-cf { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .75rem; }

</style>
</head>
<body>
<div class="wrap">

  <div class="header">
    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
      <path d="M15.5 6.5a4 4 0 0 1 0 11M8.5 6.5a4 4 0 0 0 0 11M12 3v1M12 20v1M3 12h1M20 12h1"/>
      <circle cx="12" cy="12" r="3"/>
    </svg>
    <h1>PTZ Patrol Manager</h1>
    <?php if ($settingsMode): ?>
      <p>Update your connection and daemon settings below.</p>
    <?php else: ?>
      <p>Let's get connected. This takes about 2 minutes.</p>
    <?php endif; ?>
  </div>

  <?php if ($settingsMode): ?>
    <!-- ══════════════════════════════════════════════════════════════════════
         SETTINGS MODE — single-page form, all fields pre-populated
         ══════════════════════════════════════════════════════════════════════ -->

    <a href="index.php" class="back-link">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      Back to dashboard
    </a>

    <div class="alert" id="settings-alert"></div>

    <!-- NVR section -->
    <div class="settings-section">
      <div class="settings-section-header">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"/><path d="M15.5 6.5a4 4 0 0 1 0 11M8.5 6.5a4 4 0 0 0 0 11"/></svg>
        UniFi Protect
      </div>
      <div class="settings-section-body">
        <div class="field">
          <label>NVR / UDM IP Address</label>
          <input type="text" id="s_nvr_ip" value="<?= htmlspecialchars($pre['nvr_ip']) ?>" autocomplete="off" spellcheck="false">
        </div>
        <div class="field">
          <label>API Token</label>
          <div class="token-wrap">
            <input type="password" id="s_api_token" value="<?= htmlspecialchars($pre['api_token']) ?>" autocomplete="off" spellcheck="false">
            <button type="button" class="token-toggle" onclick="toggleToken()" title="Show/hide token">
              <svg id="token-eye" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <p class="hint">Protect → Settings → Control Plane → Integrations</p>
        </div>
        <div>
          <button class="btn-test" onclick="testNvrSettings()">Test connection</button>
        </div>
      </div>
    </div>

    <!-- Database section -->
    <div class="settings-section">
      <div class="settings-section-header">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
        Database
      </div>
      <div class="settings-section-body">
        <div class="field field-row">
          <div class="field">
            <label>Host</label>
            <input type="text" id="s_db_host" value="<?= htmlspecialchars($pre['db_host']) ?>">
          </div>
          <div class="field field-small">
            <label>Port</label>
            <input type="number" id="s_db_port" value="<?= $pre['db_port'] ?>">
          </div>
        </div>
        <div class="field">
          <label>Database Name</label>
          <input type="text" id="s_db_name" value="<?= htmlspecialchars($pre['db_name']) ?>">
        </div>
        <div class="field">
          <label>Username</label>
          <input type="text" id="s_db_user" value="<?= htmlspecialchars($pre['db_user']) ?>">
        </div>
        <div class="field">
          <label>Password</label>
          <input type="password" id="s_db_pass" placeholder="Leave blank to keep current password">
          <p class="hint">Leave blank to keep the existing password unchanged.</p>
        </div>
        <div>
          <button class="btn-test" onclick="testDbSettings()">Test connection</button>
        </div>
      </div>
    </div>

    <!-- App settings section -->
    <div class="settings-section">
      <div class="settings-section-header">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Application
      </div>
      <div class="settings-section-body">
        <div class="field">
          <label>Timezone</label>
          <select id="s_timezone">
            <?php foreach ($timezones as $tz): ?>
              <option value="<?= $tz ?>" <?= $tz === $pre['timezone'] ? 'selected' : '' ?>><?= $tz ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Camera sync interval</label>
          <div class="sync-row">
            <input type="range" id="s_sync_range" min="1" max="24" value="<?= $pre['sync_hours'] ?>"
                   oninput="document.getElementById('s_sync_val').textContent = this.value + 'h'">
            <span class="sync-val" id="s_sync_val"><?= $pre['sync_hours'] ?>h</span>
          </div>
          <p class="hint">How often the daemon checks Protect for new cameras. Changes take effect on the next daemon poll (up to 60s).</p>
        </div>
        <div class="field">
          <label>Snapshot refresh interval</label>
          <div class="sync-row">
            <input type="range" id="s_snapshot_refresh" min="10" max="300" step="5"
                   value="<?= $pre['snapshot_refresh'] ?>"
                   oninput="document.getElementById('s_snap_val').textContent = this.value + 's'">
            <span class="sync-val" id="s_snap_val"><?= $pre['snapshot_refresh'] ?>s</span>
          </div>
          <p class="hint">How often the dashboard refreshes camera snapshot images. Lower = fresher images but more requests to the NVR. Default 30s.</p>
        </div>
        <div class="field">
          <label>Activity log retention</label>
          <div class="sync-row">
            <input type="range" id="s_log_retention_days" min="0" max="365" step="5"
                   value="<?= $pre['log_retention_days'] ?>"
                   oninput="document.getElementById('s_log_ret_val').textContent =
                     this.value == 0 ? 'Keep forever' : this.value + ' days'">
            <span class="sync-val" id="s_log_ret_val">
              <?= $pre['log_retention_days'] == 0 ? 'Keep forever' : $pre['log_retention_days'] . ' days' ?>
            </span>
          </div>
          <p class="hint">Audit log entries older than this are purged nightly. Set to 0 to keep forever.
            Default 90 days — enough for most compliance requirements.</p>
        </div>

        <div class="toggle-row" style="border-top:1px solid var(--border);padding-top:.65rem">
          <div>
            <span style="font-size:.875rem;font-weight:600">Public status endpoint</span>
            <p style="font-size:.78rem;color:var(--muted);margin-top:.2rem">
              Allow <code>/status.php</code> to be accessed without logging in.
              Enable this to let external monitors (Uptime Kuma, Zabbix) poll system health.
              The status page never exposes credentials or camera footage — only component health.
            </p>
          </div>
          <label class="toggle" style="flex-shrink:0">
            <input type="checkbox" id="s_status_public"
              <?= ($pre['status_public'] === 'y') ? 'checked' : '' ?>>
            <span class="toggle-track"></span>
            <span class="toggle-thumb"></span>
          </label>
        </div>
      </div>
    </div>

    <!-- Google OAuth section -->
    <div class="settings-section">
      <div class="settings-section-header">
        <svg width="13" height="13" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg"><path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/><path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z" fill="#34A853"/><path d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/><path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z" fill="#EA4335"/></svg>
        Google SSO
      </div>
      <div class="settings-section-body">
        <div class="field">
          <label>Client ID</label>
          <input type="text" id="s_google_client_id" value="<?= htmlspecialchars($pre['google_client_id']) ?>" autocomplete="off" spellcheck="false">
          <p class="hint">Google Cloud Console → APIs &amp; Services → Credentials → OAuth 2.0 Client IDs</p>
        </div>
        <div class="field">
          <label>Client Secret</label>
          <input type="password" id="s_google_client_secret" placeholder="Leave blank to keep current secret" autocomplete="off">
          <p class="hint">Leave blank to keep the existing client secret.</p>
        </div>
        <div class="field">
          <label>Authorised Redirect URI</label>
          <input type="text" id="s_google_redirect_uri" value="<?= htmlspecialchars($pre['google_redirect_uri']) ?>" spellcheck="false">
          <p class="hint">Must match exactly what's registered in Google Cloud Console. e.g. <code>https://your-server/ptz/auth.php?action=callback</code></p>
        </div>
        <div class="field">
          <label>Admin Email</label>
          <input type="email" id="s_admin_email" value="<?= htmlspecialchars($pre['admin_email']) ?>">
          <p class="hint">This account always has access. Must be a Google account.</p>
        </div>
        </div>
    </div>

    <!-- Auto-update section -->
    <div class="settings-section">
      <div class="settings-section-header">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
        </svg>
        Automatic Updates
      </div>
      <div class="settings-section-body">
        <div class="toggle-row" style="border-top:none;padding-top:0">
          <div>
            <span style="font-size:.875rem;font-weight:600">Enable automatic updates</span>
            <p style="font-size:.78rem;color:var(--muted);margin-top:.2rem">
              Pulls the latest version from GitHub daily. config.php and snapshots are always preserved.
            </p>
          </div>
          <label class="toggle" style="flex-shrink:0">
            <input type="checkbox" id="s_auto_update"
              <?= ($pre['auto_update'] === 'y') ? 'checked' : '' ?>
              onchange="document.getElementById('s_update_time_row').style.display=this.checked?'flex':'none'">
            <span class="toggle-track"></span>
            <span class="toggle-thumb"></span>
          </label>
        </div>
        <div class="toggle-row" id="s_update_time_row"
             style="border-top:1px solid var(--border);padding-top:.6rem;
                    display:<?= ($pre['auto_update'] === 'y') ? 'flex' : 'none' ?>">
          <div>
            <span style="font-size:.875rem">Daily update time</span>
            <p style="font-size:.78rem;color:var(--muted);margin-top:.2rem">
              Server local time. Uses a systemd timer — fires even if the system was off at this time.
            </p>
          </div>
          <input type="time" id="s_auto_update_time"
                 value="<?= htmlspecialchars($pre['auto_update_time']) ?>"
                 style="background:var(--bg);border:1px solid var(--border);border-radius:6px;
                        color:var(--text);padding:.4rem .6rem;font-size:.9rem;
                        outline:none;width:110px;flex-shrink:0">
        </div>
        <p class="hint">
          To update manually at any time:
          <code style="background:rgba(255,255,255,.06);padding:.1rem .4rem;border-radius:3px">
            sudo bash /var/www/ptz/update.sh
          </code>
        </p>
      </div>
    </div>

    <!-- Cloudflare Tunnel section -->
    <div class="settings-section" id="cf-section">
      <div class="settings-section-header">
        <svg width="13" height="13" viewBox="0 0 32 20" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M21.5 14.5c2.5 0 4-1.5 4-3.5 0-1.8-1.3-3.3-3-3.5-.1-3-2.6-5.5-5.7-5.5-2.3 0-4.3 1.4-5.2 3.4C10.7 5 9 6.6 9 8.6c0 .3 0 .6.1.9C7.4 10 6 11.4 6 13c0 .8.3 1.6.8 2.2" stroke="#F6821F" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        Cloudflare Tunnel
      </div>
      <div class="settings-section-body" id="cf-body">
        <!-- Dynamic content rendered by JS -->
        <div id="cf-status-wrap">
          <p style="color:var(--muted);font-size:.85rem">Checking tunnel status…</p>
        </div>
      </div>
    </div>

    <div class="btn-row" style="margin-top:1.25rem">
      <a href="index.php" style="text-decoration:none">
        <button type="button" class="btn-ghost">Cancel</button>
      </a>
      <button class="btn-primary" id="btn-save-settings" onclick="saveSettings()">Save settings</button>
    </div>

  <?php else: ?>
    <!-- ══════════════════════════════════════════════════════════════════════
         WIZARD MODE — 3-step first-run flow
         ══════════════════════════════════════════════════════════════════════ -->

    <div class="steps">
      <div class="step-dot active" id="sdot-1"><span class="num">1</span><span class="label">NVR</span></div>
      <div class="step-connector"></div>
      <div class="step-dot" id="sdot-2"><span class="num">2</span><span class="label">Database</span></div>
      <div class="step-connector"></div>
      <div class="step-dot" id="sdot-3"><span class="num">3</span><span class="label">Done</span></div>
    </div>

    <!-- Step 1: NVR -->
    <div class="card active" id="step-1">
      <h2>UniFi Protect Connection</h2>
      <p class="desc">Enter your NVR/UDM IP address and the API token from Protect.<br>
        Go to <strong>Protect → Settings → Control Plane → Integrations</strong> to create a token.</p>

      <div class="alert" id="nvr-alert"></div>

      <div class="field" style="margin-bottom:.9rem">
        <label>NVR / UDM IP Address</label>
        <input type="text" id="nvr_ip" placeholder="192.168.1.1" autocomplete="off" spellcheck="false">
      </div>
      <div class="field" style="margin-bottom:.9rem">
        <label>API Token</label>
        <div class="token-wrap">
          <input type="password" id="api_token" placeholder="Paste token here" autocomplete="off" spellcheck="false">
          <button type="button" class="token-toggle" onclick="toggleToken()" title="Show/hide token">
            <svg id="token-eye" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <p class="hint">Token is stored in /etc/ptz/secrets.php (outside web root).</p>
      </div>

      <div class="field" style="margin-bottom:.9rem;margin-top:.75rem;
               padding-top:.75rem;border-top:1px solid var(--border)">
        <label>Your admin email address</label>
        <input type="email" id="wizard_admin_email"
               placeholder="you@yourdomain.com" autocomplete="off">
        <p class="hint" style="margin-top:.3rem">
          This Google account will <strong>always</strong> have access and can manage other users.
          It must match your Google login exactly.
          A unique security key is generated for this installation when you save.
        </p>
      </div>

      <div class="btn-row">
        <button class="btn-test" id="btn-test-nvr" onclick="testNvr()">Test connection</button>
        <button class="btn-primary" id="btn-next-1" onclick="goStep(2)" disabled>Next →</button>
      </div>
    </div>

    <!-- Step 2: Database + App settings -->
    <div class="card" id="step-2">
      <h2>Database & Settings</h2>
      <p class="desc">MySQL connection details. The database must already exist — tables are created automatically.</p>

      <div class="alert" id="db-alert"></div>

      <div class="field" style="margin-bottom:.9rem">
        <label>Database Host</label>
        <input type="text" id="db_host" value="localhost">
      </div>
      <div class="field" style="margin-bottom:.9rem">
        <label>Database Name</label>
        <input type="text" id="db_name" placeholder="unifi_ptz">
      </div>
      <div class="field-row" style="gap:.6rem;margin-bottom:.9rem">
        <div class="field">
          <label>Username</label>
          <input type="text" id="db_user" placeholder="ptz_user">
        </div>
        <div class="field field-small">
          <label>Port</label>
          <input type="number" id="db_port" value="3306">
        </div>
      </div>
      <div class="field" style="margin-bottom:.9rem">
        <label>Password</label>
        <input type="password" id="db_pass" placeholder="••••••••">
      </div>

      <hr class="divider">

      <div class="field" style="margin-bottom:.9rem">
        <label>Timezone</label>
        <select id="timezone">
          <?php foreach ($timezones as $tz): ?>
            <option value="<?= $tz ?>" <?= $tz === 'Europe/London' ? 'selected' : '' ?>><?= $tz ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="margin-bottom:.9rem">
        <label>Camera sync interval</label>
        <div class="sync-row">
          <input type="range" id="sync_range" min="1" max="24" value="6"
                 oninput="document.getElementById('sync_val').textContent = this.value + 'h'">
          <span class="sync-val" id="sync_val">6h</span>
        </div>
        <p class="hint">How often the daemon checks Protect for new cameras.</p>
      </div>

      <hr class="divider">

      <div class="field" style="margin-bottom:.9rem">
        <label>Admin Email</label>
        <input type="email" id="admin_email" placeholder="you@yourdomain.com">
        <p class="hint" style="margin-top:.3rem">Your Google account — always has access. Must match your Google login.</p>
      </div>
      <div class="field" style="margin-bottom:.9rem">
        <label>Google Client ID</label>
        <input type="text" id="google_client_id" placeholder="xxxx.apps.googleusercontent.com" autocomplete="off" spellcheck="false">
        <p class="hint" style="margin-top:.3rem">Cloud Console → APIs &amp; Services → Credentials. Leave blank to skip SSO for now.</p>
      </div>
      <div class="field" style="margin-bottom:.9rem">
        <label>Google Client Secret</label>
        <input type="password" id="google_client_secret" autocomplete="off">
      </div>
      <div class="field" style="margin-bottom:.9rem">
        <label>Redirect URI</label>
        <input type="text" id="google_redirect_uri" placeholder="https://your-server/ptz/auth.php?action=callback" spellcheck="false">
        <p class="hint" style="margin-top:.3rem">Must match exactly what's set in Google Cloud Console.</p>
      </div>

      <div class="btn-row">
        <button class="btn-ghost" onclick="goStep(1)">← Back</button>
        <button class="btn-test" onclick="testDb()">Test DB</button>
        <button class="btn-primary" id="btn-next-2" onclick="saveConfig()" disabled>Save &amp; Finish →</button>
      </div>
    </div>

    <!-- Step 3: Done -->
    <div class="card" id="step-3">
      <div class="finish-box">
        <div class="tick">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
        </div>
        <h2>All set!</h2>
        <p>/etc/ptz/config.php and secrets.php written, database tables created.<br>Head to the dashboard to sync your cameras.</p>
        <a href="index.php">Open dashboard →</a>
      </div>
    </div>

  <?php endif; ?>

</div><!-- /wrap -->

<script>
// ── Shared helpers ────────────────────────────────────────────────────────────
function setAlert(id, type, msg) {
  const el = document.getElementById(id);
  if (!el) return;
  el.className = 'alert show ' + type;
  el.textContent = msg;
}
function clearAlert(id) {
  const el = document.getElementById(id); if (el) el.className = 'alert';
}

async function post(data) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(data)) fd.append(k, v);
  const r = await fetch('setup.php', { method: 'POST', body: fd });
  return r.json();
}

function toggleToken() {
  const inp = document.getElementById('s_api_token') || document.getElementById('api_token');
  if (inp) inp.type = inp.type === 'password' ? 'text' : 'password';
}

// ── Wizard logic ──────────────────────────────────────────────────────────────
let nvrOk = false, dbOk = false;

function goStep(n) {
  // Validate admin email before moving past step 1
  if (n === 2) {
    const adminEl = document.getElementById('wizard_admin_email');
    const val     = adminEl ? adminEl.value.trim() : '';
    if (!val || !val.includes('@')) {
      adminEl?.focus();
      setAlert('nvr-alert', 'error', 'Enter your admin email address before continuing.');
      return;
    }
  }
  document.querySelectorAll('.card').forEach(c => c.classList.remove('active'));
  const s = document.getElementById('step-' + n);
  if (s) s.classList.add('active');
  document.querySelectorAll('.step-dot').forEach((d, i) => {
    d.classList.toggle('active', i + 1 === n);
    d.classList.toggle('done',   i + 1 < n);
  });
}

async function testNvr() {
  clearAlert('nvr-alert');
  const btn = document.getElementById('btn-test-nvr');
  btn.disabled = true; btn.innerHTML = '<span class="spin">⟳</span> Testing…';

  const res = await post({
    step: 'test_nvr',
    nvr_ip:    document.getElementById('nvr_ip').value.trim(),
    api_token: document.getElementById('api_token').value.trim(),
  });

  btn.disabled = false; btn.textContent = 'Test connection';

  if (res.ok) {
    setAlert('nvr-alert', 'success',
      `Connected ✓  Found ${res.data.total_cameras} cameras (${res.data.ptz_count} PTZ)`);
    nvrOk = true;
    document.getElementById('btn-next-1').disabled = false;
  } else {
    setAlert('nvr-alert', 'error', res.error);
    nvrOk = false;
    document.getElementById('btn-next-1').disabled = true;
  }
}

async function testDb() {
  clearAlert('db-alert');
  const btn = event.target;
  btn.disabled = true; btn.innerHTML = '<span class="spin">⟳</span> Testing…';

  const res = await post({
    step:    'test_db',
    db_host: document.getElementById('db_host').value,
    db_name: document.getElementById('db_name').value,
    db_user: document.getElementById('db_user').value,
    db_pass: document.getElementById('db_pass').value,
    db_port: document.getElementById('db_port').value,
  });

  btn.disabled = false; btn.textContent = 'Test DB';

  if (res.ok) {
    setAlert('db-alert', 'success', 'Database connected ✓');
    dbOk = true;
    document.getElementById('btn-next-2').disabled = false;
  } else {
    setAlert('db-alert', 'error', res.error);
    dbOk = false;
  }
}

async function saveConfig() {
  const btn = document.getElementById('btn-next-2');
  btn.disabled = true; btn.innerHTML = '<span class="spin">⟳</span> Saving…';

  const res = await post({
    step:                  'save_config',
    is_update:             '0',
    nvr_ip:                document.getElementById('nvr_ip').value.trim(),
    api_token:             document.getElementById('api_token').value.trim(),
    db_host:               document.getElementById('db_host').value,
    db_name:               document.getElementById('db_name').value,
    db_user:               document.getElementById('db_user').value,
    db_pass:               document.getElementById('db_pass').value,
    db_port:               document.getElementById('db_port').value,
    timezone:              document.getElementById('timezone').value,
    sync_hours:            document.getElementById('sync_range').value,
    admin_email:           (document.getElementById('wizard_admin_email') || document.getElementById('admin_email'))?.value.trim() || '',
    google_client_id:      document.getElementById('google_client_id').value.trim(),
    google_client_secret:  document.getElementById('google_client_secret').value.trim(),
    google_redirect_uri:   document.getElementById('google_redirect_uri').value.trim(),
    auto_update:           'y',
    auto_update_time:      '03:00',
  });

  if (res.ok) {
    goStep(3);
  } else {
    btn.disabled = false; btn.textContent = 'Save & Finish →';
    setAlert('db-alert', 'error', res.error);
  }
}

// ── Settings mode logic ───────────────────────────────────────────────────────
async function testNvrSettings() {
  clearAlert('settings-alert');
  const btn = event.target;
  btn.disabled = true; btn.innerHTML = '<span class="spin">⟳</span> Testing…';

  const res = await post({
    step:      'test_nvr',
    nvr_ip:    document.getElementById('s_nvr_ip').value.trim(),
    api_token: document.getElementById('s_api_token').value.trim(),
  });

  btn.disabled = false; btn.textContent = 'Test connection';

  if (res.ok) {
    setAlert('settings-alert', 'success',
      `NVR reachable ✓  ${res.data.total_cameras} cameras found (${res.data.ptz_count} PTZ)`);
  } else {
    setAlert('settings-alert', 'error', res.error);
  }
}

async function testDbSettings() {
  clearAlert('settings-alert');
  const btn = event.target;
  btn.disabled = true; btn.innerHTML = '<span class="spin">⟳</span> Testing…';

  const res = await post({
    step:    'test_db',
    db_host: document.getElementById('s_db_host').value,
    db_name: document.getElementById('s_db_name').value,
    db_user: document.getElementById('s_db_user').value,
    db_pass: document.getElementById('s_db_pass').value,
    db_port: document.getElementById('s_db_port').value,
  });

  btn.disabled = false; btn.textContent = 'Test connection';

  if (res.ok) {
    setAlert('settings-alert', 'success', 'Database connected ✓');
  } else {
    setAlert('settings-alert', 'error', res.error);
  }
}

async function saveSettings() {
  clearAlert('settings-alert');
  const btn = document.getElementById('btn-save-settings');
  btn.disabled = true; btn.innerHTML = '<span class="spin">⟳</span> Saving…';

  const res = await post({
    step:                  'save_config',
    is_update:             '1',
    nvr_ip:                document.getElementById('s_nvr_ip').value.trim(),
    api_token:             document.getElementById('s_api_token').value.trim(),
    db_host:               document.getElementById('s_db_host').value,
    db_name:               document.getElementById('s_db_name').value,
    db_user:               document.getElementById('s_db_user').value,
    db_pass:               document.getElementById('s_db_pass').value,
    db_port:               document.getElementById('s_db_port').value,
    timezone:              document.getElementById('s_timezone').value,
    sync_hours:            document.getElementById('s_sync_range').value,
    admin_email:           document.getElementById('s_admin_email').value.trim(),
    google_client_id:      document.getElementById('s_google_client_id').value.trim(),
    google_client_secret:  document.getElementById('s_google_client_secret').value.trim(),
    google_redirect_uri:   document.getElementById('s_google_redirect_uri').value.trim(),
    auto_update:           document.getElementById('s_auto_update')?.checked ? 'on' : 'off',
    auto_update_time:      document.getElementById('s_auto_update_time')?.value || '03:00',
    snapshot_refresh:      document.getElementById('s_snapshot_refresh')?.value || '30',
    log_retention_days:    document.getElementById('s_log_retention_days')?.value || '90',
    status_public:         document.getElementById('s_status_public')?.checked ? 'on' : 'off',
  });

  btn.disabled = false; btn.textContent = 'Save settings';

  if (res.ok) {
    setAlert('settings-alert', 'success',
      'Settings saved ✓  The daemon will pick up the new sync interval within 60 seconds.');
  } else {
    setAlert('settings-alert', 'error', res.error);
  }
}

// ── Cloudflare Tunnel ─────────────────────────────────────────────────────────

async function cfPost(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  for (const [k, v] of Object.entries(extra)) fd.append(k, v);
  const r = await fetch('tunnel_setup.php', { method: 'POST', body: fd });
  const j = await r.json();
  if (!j.ok) throw new Error(j.error || 'Tunnel API error');
  return j.data;
}

function cfStatusBadge(s) {
  if (!s || !s.installed) {
    return `<span class="tunnel-status-badge tsb-unknown">
      <span class="status-dot dot-grey"></span>Not installed</span>`;
  }
  if (s.running && s.connected) {
    return `<span class="tunnel-status-badge tsb-connected">
      <span class="status-dot dot-green"></span>Connected</span>
      <span class="tunnel-status-badge tsb-running" style="margin-left:.35rem">
      <span class="status-dot dot-green"></span>Running</span>`;
  }
  if (s.running) {
    return `<span class="tunnel-status-badge tsb-running">
      <span class="status-dot dot-green"></span>Running</span>`;
  }
  return `<span class="tunnel-status-badge tsb-stopped">
    <span class="status-dot dot-red"></span>Stopped</span>`;
}

function renderCfInstalled(s) {
  return `
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
      <span style="font-size:.85rem;font-weight:600">Tunnel status:</span>
      ${cfStatusBadge(s)}
    </div>
    <div class="btn-row-cf">
      <button class="btn-test" onclick="cfAction('restart')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Restart
      </button>
      <button class="btn-test" onclick="cfAction('stop')" style="color:var(--error);border-color:rgba(248,81,73,.4)">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
        Stop
      </button>
      <button class="btn-test" onclick="cfAction('start')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        Start
      </button>
      <button class="btn-ghost" style="font-size:.78rem;padding:.35rem .8rem"
              onclick="document.getElementById('cf-log').classList.toggle('show')">
        Toggle log
      </button>
    </div>
    <pre class="cf-log" id="cf-log">${(s.journal || s.systemd || '').trim()}</pre>
    <div style="margin-top:1rem;padding-top:.9rem;border-top:1px solid var(--border)">
      <p style="font-size:.78rem;color:var(--muted);margin-bottom:.6rem">
        Paste a new install command to replace the existing tunnel token:
      </p>
      ${renderPasteForm()}
    </div>`;
}

function renderCfNotInstalled() {
  return `
    <div class="tunnel-steps">
      <div class="tunnel-step">
        <div class="step-num">1</div>
        <div class="step-body">
          Go to <a href="https://one.dash.cloudflare.com" target="_blank" rel="noopener">one.dash.cloudflare.com</a>
          → <strong>Networks → Tunnels → Create a tunnel</strong>
        </div>
      </div>
      <div class="tunnel-step">
        <div class="step-num">2</div>
        <div class="step-body">
          Choose <strong>Cloudflared</strong> as the connector type, give your tunnel a name
          (e.g. <code>ptz-patrol</code>), and click <strong>Save tunnel</strong>
        </div>
      </div>
      <div class="tunnel-step">
        <div class="step-num">3</div>
        <div class="step-body">
          Under <strong>Install and Run</strong>, select <strong>Debian</strong> as your OS.
          Copy the <em>full install command</em> shown — it starts with
          <code>sudo cloudflared service install eyJ…</code>
        </div>
      </div>
      <div class="tunnel-step">
        <div class="step-num">4</div>
        <div class="step-body">
          Add a <strong>Public Hostname</strong> route: subdomain of your choice,
          service type <strong>HTTP</strong>, URL <code>localhost:80</code>
        </div>
      </div>
      <div class="tunnel-step">
        <div class="step-num">5</div>
        <div class="step-body">
          Paste the copied command into the box below and click
          <strong>Install &amp; Connect</strong>
        </div>
      </div>
    </div>
    ${renderPasteForm()}`;
}

function renderPasteForm() {
  return `
    <div class="cf-paste-wrap">
      <textarea id="cf-command"
        placeholder="sudo cloudflared service install eyJhIjoiN...&#10;&#10;(or paste just the eyJ... token)"></textarea>
    </div>
    <div style="margin-top:.6rem;display:flex;align-items:center;gap:.65rem;flex-wrap:wrap">
      <button class="btn-primary" id="btn-cf-install" onclick="installTunnel()"
              style="font-size:.85rem;padding:.5rem 1rem">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
        Install &amp; Connect
      </button>
      <span id="cf-install-msg" style="font-size:.82rem;color:var(--muted)"></span>
    </div>
    <pre class="cf-log" id="cf-log"></pre>`;
}

async function loadCfStatus() {
  const wrap = document.getElementById('cf-status-wrap');
  if (!wrap) return;
  try {
    const s = await cfPost('status');
    wrap.innerHTML = s.installed ? renderCfInstalled(s) : renderCfNotInstalled();
  } catch(e) {
    wrap.innerHTML = renderCfNotInstalled();
  }
}

async function installTunnel() {
  const cmd = (document.getElementById('cf-command')?.value || '').trim();
  if (!cmd) { alert('Paste the cloudflared install command first.'); return; }

  const btn = document.getElementById('btn-cf-install');
  const msg = document.getElementById('cf-install-msg');
  const log = document.getElementById('cf-log');

  btn.disabled = true;
  btn.innerHTML = '<span class="spin">⟳</span> Installing…';
  if (msg) msg.textContent = 'This takes about 10 seconds…';
  if (log) { log.textContent = ''; log.classList.add('show'); }

  try {
    const d = await cfPost('install', { command: cmd });
    if (log) log.textContent = (d.output || '') + '

' + (d.status?.journal || '');
    // Re-render with live status
    document.getElementById('cf-status-wrap').innerHTML = renderCfInstalled(d.status || {});
    setAlert('settings-alert', 'success',
      d.status?.connected
        ? 'Tunnel installed and connected ✓  Your server is now reachable through Cloudflare.'
        : 'Tunnel service installed and started. Check the log if it's not showing as connected within 30 seconds.');
  } catch(e) {
    btn.disabled = false;
    btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg> Install &amp; Connect';
    if (msg) msg.textContent = '';
    setAlert('settings-alert', 'error', 'Install failed: ' + e.message);
    if (log && e.message) log.textContent = e.message;
  }
}

async function cfAction(verb) {
  try {
    const d = await cfPost(verb);
    document.getElementById('cf-status-wrap').innerHTML = renderCfInstalled(d.status || {});
    const log = document.getElementById('cf-log');
    if (log && d.status?.journal) log.textContent = d.status.journal;
  } catch(e) {
    setAlert('settings-alert', 'error', e.message);
  }
}

// Auto-load tunnel status when settings page renders
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('cf-status-wrap')) {
    loadCfStatus();
  }
});

</script>
</body>
</html>
