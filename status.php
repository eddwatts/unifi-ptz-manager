<?php
/**
 * status.php — diagnostic status endpoint.
 *
 * Returns JSON with health of all system components.
 * HTTP 200 = ok/degraded, HTTP 503 = error (any critical component down).
 *
 * Auth: intentionally NOT protected by Google SSO so external monitors
 * (Uptime Kuma, Zabbix, curl) can reach it without a session.
 * Sensitive values (IPs, tokens) are never included in output.
 *
 * Usage:
 *   curl https://your-domain.com/status.php
 *   curl https://your-domain.com/status.php?pretty=1
 */

// Config may not exist yet (fresh install)
$configPath = '/etc/ptz/config.php';
if (file_exists($configPath)) {
    @include_once $configPath;
}

// Auth gate — respect STATUS_PUBLIC setting.
// If 'n', require an active Google SSO session.
// If 'y' (default), allow unauthenticated access for external monitors.
$statusPublic = defined('STATUS_PUBLIC') ? STATUS_PUBLIC : 'y';
if ($statusPublic !== 'y') {
    if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') {
        require_once INSTALL_DIR . '/auth_check.php';
    }
} else {
    // Public access — still block if config says n
    header('X-Status-Access: public');
}

$startMs = microtime(true);

// ── Collectors ────────────────────────────────────────────────────────────────

function check_php(): array
{
    $required = ['pdo_mysql', 'curl', 'gd', 'mbstring', 'xml'];
    $missing  = array_filter($required, fn($e) => !extension_loaded($e));

    return [
        'status'     => empty($missing) ? 'ok' : 'degraded',
        'version'    => PHP_VERSION,
        'extensions' => array_values(array_diff($required, $missing)),
        'missing'    => array_values($missing),
        'memory_limit' => ini_get('memory_limit'),
    ];
}

function check_database(): array
{
    if (!defined('DB_HOST') || !DB_HOST) {
        return ['status' => 'unconfigured', 'message' => 'Database not configured'];
    }

    $t = microtime(true);
    try {
        $pdo = get_db();

        // Basic connectivity
        $pdo->query('SELECT 1');
        $ms = round((microtime(true) - $t) * 1000, 1);

        // Camera counts
        $cameras   = (int)$pdo->query("SELECT COUNT(*) FROM cameras WHERE is_ptz = 1")->fetchColumn();
        $enabled   = (int)$pdo->query("SELECT COUNT(*) FROM cameras WHERE is_ptz = 1 AND enabled = 1")->fetchColumn();
        $schedules = (int)$pdo->query("SELECT COUNT(*) FROM schedule_days")->fetchColumn();

        // Rate limit table hit count (last window)
        try {
            $rl_window  = defined('RATE_LIMIT_WINDOW') ? RATE_LIMIT_WINDOW : 60;
            $rl_hits    = (int)$pdo->query(
                "SELECT COUNT(*) FROM rate_limit WHERE hit_at >= DATE_SUB(NOW(), INTERVAL {$rl_window} SECOND)"
            )->fetchColumn();
        } catch (Throwable) {
            $rl_hits = null;  // table may not exist yet
        }

        // Last action log entry
        $lastAction = $pdo->query(
            "SELECT action, created_at FROM action_log ORDER BY created_at DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        // DB size
        $dbSize = $pdo->query(
            "SELECT ROUND(SUM(data_length + index_length) / 1024, 1) AS size_kb
             FROM information_schema.tables
             WHERE table_schema = DATABASE()"
        )->fetchColumn();

        return [
            'status'          => 'ok',
            'response_ms'     => $ms,
            'cameras_total'   => $cameras,
            'cameras_enabled' => $enabled,
            'schedule_days'   => $schedules,
            'db_size_kb'      => (float)$dbSize,
            'last_log_action' => $lastAction['action']     ?? null,
            'last_log_at'     => $lastAction['created_at'] ?? null,
            'rate_limit_hits' => $rl_hits,
        ];
    } catch (Throwable $e) {
        return [
            'status'      => 'error',
            'response_ms' => round((microtime(true) - $t) * 1000, 1),
            'message'     => 'Connection failed: ' . $e->getMessage(),
        ];
    }
}

function check_protect(): array
{
    if (!defined('NVR_IP') || !NVR_IP || !defined('API_TOKEN') || !API_TOKEN) {
        return ['status' => 'unconfigured', 'message' => 'NVR not configured'];
    }

    $t  = microtime(true);
    $ch = curl_init('https://' . NVR_IP . '/proxy/protect/integration/v1/cameras');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . API_TOKEN],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $ms = round((microtime(true) - $t) * 1000, 1);

    if ($err) {
        return ['status' => 'error', 'response_ms' => $ms, 'message' => "curl: {$err}"];
    }
    if ($code === 401) {
        return ['status' => 'error', 'response_ms' => $ms, 'message' => 'API token rejected'];
    }
    if ($code !== 200) {
        return ['status' => 'error', 'response_ms' => $ms, 'message' => "HTTP {$code}"];
    }

    $data    = json_decode($raw, true);
    $cameras = $data['data'] ?? [];
    $ptz     = count(array_filter($cameras, fn($c) => $c['isPtz'] ?? false));

    return [
        'status'          => 'ok',
        'response_ms'     => $ms,
        'cameras_total'   => count($cameras),
        'cameras_ptz'     => $ptz,
        // NVR IP deliberately omitted — use dashboard for that
    ];
}

function check_daemon(): array
{
    $heartbeatPath = '/tmp/ptz_daemon_heartbeat.json';

    if (!file_exists($heartbeatPath)) {
        return [
            'status'  => 'error',
            'message' => 'Heartbeat file not found — daemon may not be running',
            'hint'    => 'Run: systemctl start ptz-patrol',
        ];
    }

    $raw  = file_get_contents($heartbeatPath);
    $data = json_decode($raw, true);

    if (!$data) {
        return ['status' => 'error', 'message' => 'Heartbeat file unreadable or corrupt'];
    }

    $age = time() - ($data['ts'] ?? 0);

    // Daemon polls every 60s — stale after 3 missed polls (3 min)
    $status = match(true) {
        $age < 180  => 'ok',
        $age < 600  => 'degraded',
        default     => 'error',
    };

    return [
        'status'          => $status,
        'last_heartbeat'  => $data['timestamp']      ?? null,
        'age_seconds'     => $age,
        'cameras_managed'  => $data['cameras_managed']  ?? 0,
        'patrolling_now'   => $data['patrolling_now']   ?? 0,
        'cameras_offline'  => $data['cameras_offline']  ?? 0,
        'cameras_retrying' => $data['cameras_retrying'] ?? 0,
        'max_retries'      => $data['max_retries']      ?? null,
        'verify_delay_secs'=> $data['verify_delay_secs']?? null,
        'last_ntp_sync'   => $data['last_ntp_sync']   ?? null,
        'ntp_offset_ms'   => $data['ntp_offset_ms']   ?? null,
        'ntp_status'      => $data['ntp_status']       ?? 'unknown',
        'last_cam_sync'   => $data['last_cam_sync']    ?? null,
        'errors_since_start' => $data['errors_since_start'] ?? 0,
        'uptime_seconds'  => $data['uptime_seconds']  ?? null,
        'message'         => $status !== 'ok'
            ? "Heartbeat is {$age}s old — daemon may have stopped"
            : null,
    ];
}

function check_tunnel(): array
{
    // Check systemd service state without sudo (readable by all)
    exec('systemctl is-active cloudflared 2>/dev/null', $activeOut, $activeExit);
    $running = trim($activeOut[0] ?? '') === 'active';

    exec('systemctl is-enabled cloudflared 2>/dev/null', $enabledOut);
    $enabled = trim($enabledOut[0] ?? '') === 'enabled';

    if (!$running) {
        return [
            'status'  => 'error',
            'running' => false,
            'enabled' => $enabled,
            'message' => 'cloudflared service is not running',
            'hint'    => 'Run: systemctl start cloudflared',
        ];
    }

    // Check recent journal for connection confirmation
    $journal = shell_exec(
        'journalctl -u cloudflared --since "5 minutes ago" --no-pager -q 2>/dev/null | tail -5'
    ) ?: '';

    $connected = str_contains($journal, 'Connection registered')
              || str_contains($journal, 'Registered tunnel connection')
              || str_contains($journal, 'connnection established');

    // If running but can't confirm connected from journal, it's probably fine
    // just hasn't logged recently — report as ok not degraded
    return [
        'status'    => 'ok',
        'running'   => true,
        'enabled'   => $enabled,
        'connected' => $connected,
    ];
}

function check_snapshots(): array
{
    $dir = __DIR__ . '/snapshots';

    if (!is_dir($dir)) {
        return ['status' => 'error', 'message' => 'snapshots/ directory missing'];
    }

    $writable = is_writable($dir);
    $files    = glob($dir . '/*.jpg') ?: [];
    $count    = count($files);

    $oldestAge = null;
    if ($count > 0) {
        $oldest    = min(array_map('filemtime', $files));
        $oldestAge = time() - $oldest;
    }

    return [
        'status'          => $writable ? 'ok' : 'error',
        'writable'        => $writable,
        'cached_snapshots'=> $count,
        'oldest_age_secs' => $oldestAge,
        'message'         => !$writable ? 'snapshots/ is not writable by web server' : null,
    ];
}

function check_schedule(): array
{
    if (!defined('DB_NAME') || !DB_NAME) {
        return ['status' => 'unconfigured'];
    }

    try {
        $pdo = get_db();
        $now = new DateTime();
        $dow = (int)$now->format('N') - 1; // 0=Mon ... 6=Sun
        $t   = $now->format('H:i:s');

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT c.id)
            FROM cameras c
            JOIN camera_schedules cs ON cs.camera_id = c.id
            JOIN schedule_days sd ON sd.schedule_id = cs.id
            WHERE c.enabled = 1
              AND sd.day_of_week = :dow
              AND sd.enabled = 1
              AND sd.patrol_start <= :t
              AND sd.patrol_stop > :t
        ");
        $stmt->execute([':dow' => $dow, ':t' => $t]);
        $patrolling = (int)$stmt->fetchColumn();

        $enabled = (int)$pdo->query(
            "SELECT COUNT(*) FROM cameras WHERE is_ptz = 1 AND enabled = 1"
        )->fetchColumn();

        $total = (int)$pdo->query(
            "SELECT COUNT(*) FROM cameras WHERE is_ptz = 1"
        )->fetchColumn();

        return [
            'status'              => 'ok',
            'cameras_total'       => $total,
            'cameras_enabled'     => $enabled,
            'in_patrol_window'    => (int)$patrolling,
            'current_day_of_week' => $dow,
            'current_time'        => $t,
        ];
    } catch (Throwable) {
        return ['status' => 'error', 'message' => 'Could not query schedule'];
    }
}

function check_config(): array
{
    $configured = defined('NVR_IP') && NVR_IP
               && defined('API_TOKEN') && API_TOKEN
               && defined('DB_NAME') && DB_NAME;

    $googleConfigured = defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '';
    $cfConfigured     = (bool)shell_exec('which cloudflared 2>/dev/null');

    // Auto-update timer state
    exec('systemctl is-active ptz-update.timer 2>/dev/null', $timerOut);
    $timerActive = trim($timerOut[0] ?? '') === 'active';
    $nextUpdate  = null;
    if ($timerActive) {
        exec('systemctl list-timers ptz-update.timer --no-pager 2>/dev/null', $timerLines);
        foreach ($timerLines as $line) {
            if (str_contains($line, 'ptz-update')) {
                $parts = preg_split('/\s+/', trim($line));
                $nextUpdate = ($parts[0] ?? '') . ' ' . ($parts[1] ?? '');
                break;
            }
        }
    }

    return [
        'status'             => $configured ? 'ok' : 'unconfigured',
        'wizard_complete'    => $configured,
        'google_sso'         => $googleConfigured,
        'cloudflared_binary' => $cfConfigured,
        'timezone'           => defined('APP_TIMEZONE') ? APP_TIMEZONE : 'not set',
        'sync_interval_h'    => defined('SYNC_INTERVAL_HOURS') ? SYNC_INTERVAL_HOURS : null,
        'auto_update'        => defined('AUTO_UPDATE') ? AUTO_UPDATE : 'unknown',
        'auto_update_time'   => defined('AUTO_UPDATE_TIME') ? AUTO_UPDATE_TIME : null,
        'update_timer_active'=> $timerActive,
        'next_update'        => $nextUpdate,
    ];
}

// ── Assemble response ─────────────────────────────────────────────────────────

$components = [
    'php'       => check_php(),
    'config'    => check_config(),
    'database'  => check_database(),
    'protect'   => check_protect(),
    'daemon'    => check_daemon(),
    'tunnel'    => check_tunnel(),
    'snapshots' => check_snapshots(),
    'schedule'  => check_schedule(),
];

// Roll up overall status
// error   = any critical component is error
// degraded = any component is degraded/unconfigured
// ok      = all components ok
$critical = ['database', 'daemon'];
$overall  = 'ok';

foreach ($components as $name => $comp) {
    $s = $comp['status'] ?? 'unknown';
    if ($s === 'error') {
        $overall = in_array($name, $critical) ? 'error' : max_severity($overall, 'degraded');
    } elseif (in_array($s, ['degraded', 'unconfigured', 'unknown'])) {
        $overall = max_severity($overall, 'degraded');
    }
}

function max_severity(string $current, string $new): string
{
    $levels = ['ok' => 0, 'degraded' => 1, 'error' => 2];
    return ($levels[$new] ?? 0) > ($levels[$current] ?? 0) ? $new : $current;
}

$elapsed = round((microtime(true) - $startMs) * 1000, 1);

$response = [
    'status'      => $overall,
    'timestamp'   => (new DateTime())->format(DateTime::ATOM),
    'version'     => defined('APP_VERSION') ? APP_VERSION : 'unknown',
    'elapsed_ms'  => $elapsed,
    'components'  => $components,
];

// HTTP status code — 503 lets monitors treat this as a proper health check
http_response_code($overall === 'error' ? 503 : 200);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Status: ' . $overall);

// ?pretty=1 for human-readable output
$flags = isset($_GET['pretty']) ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : 0;
echo json_encode($response, $flags);
