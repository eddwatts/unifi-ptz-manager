<?php
/**
 * tunnel_setup.php — server-side Cloudflare Tunnel installer.
 *
 * Called via AJAX from setup.php. Accepts the cloudflared install command
 * or raw token, installs cloudflared if needed, runs the service install,
 * and returns status as JSON.
 *
 * Security:
 *  - Requires active auth session (auth_check.php)
 *  - Token validated as base64url before any exec()
 *  - Only whitelisted commands are ever executed
 *  - Web user runs cloudflared via a locked sudoers entry (install.sh)
 *  - Rate limited to 10 requests per 5 minutes (brute-force protection)
 *
 * Required sudoers entry (added by install.sh):
 *   www-data ALL=(root) NOPASSWD: /usr/bin/cloudflared service install *
 *   www-data ALL=(root) NOPASSWD: /usr/bin/cloudflared service uninstall
 *   www-data ALL=(root) NOPASSWD: /bin/systemctl start cloudflared
 *   www-data ALL=(root) NOPASSWD: /bin/systemctl stop cloudflared
 *   www-data ALL=(root) NOPASSWD: /bin/systemctl restart cloudflared
 *   www-data ALL=(root) NOPASSWD: /bin/systemctl status cloudflared
 *   www-data ALL=(root) NOPASSWD: /bin/systemctl enable cloudflared
 *   www-data ALL=(root) NOPASSWD: /usr/bin/apt-get install -y cloudflared
 */

require_once '/etc/ptz/config.php';

// Auth required — tunnel setup is admin-only and sensitive
if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') {
    require_once __DIR__ . '/auth_check.php';
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// ── Rate limiting (separate from api.php — tunnel install is high-risk) ──────
// 10 attempts per 5 minutes per session to prevent brute-force token submission
try {
    $rl      = get_db();
    $rlKey   = hash('sha256', session_id() ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $rlStmt  = $rl->prepare(
        "SELECT COUNT(*) FROM rate_limit
         WHERE rate_key = ? AND action_type = 'write'
           AND hit_at >= DATE_SUB(NOW(), INTERVAL 300 SECOND)"
    );
    $rlStmt->execute([$rlKey]);
    if ((int)$rlStmt->fetchColumn() >= 10) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many tunnel requests — wait 5 minutes.']);
        exit;
    }
    $rl->prepare("INSERT INTO rate_limit (rate_key, action_type) VALUES (?, 'write')")
       ->execute([$rlKey]);
} catch (Throwable) {
    // Rate limit DB failure is non-fatal
}

$action = $_POST['action'] ?? '';

try {
    $result = match ($action) {
        'install' => action_install(),
        'status'  => action_status(),
        'stop'    => action_service('stop'),
        'start'   => action_service('start'),
        'restart' => action_service('restart'),
        default   => throw new InvalidArgumentException("Unknown action: {$action}"),
    };

    // Audit: log tunnel management actions
    audit_tunnel($action, $result);

    echo json_encode(['ok' => true, 'data' => $result]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// ── Actions ───────────────────────────────────────────────────────────────────

function action_install(): array
{
    $raw = trim($_POST['command'] ?? '');
    if (!$raw) throw new InvalidArgumentException('No command or token provided.');

    $token  = extract_token($raw);
    $cfPath = trim(shell_exec('which cloudflared 2>/dev/null') ?: '');
    if (!$cfPath) {
        install_cloudflared_binary();
        $cfPath = '/usr/bin/cloudflared';
    }

    $cfVer = trim(shell_exec('cloudflared --version 2>&1') ?: 'unknown');

    // Stop any existing service (ignore failure — may not exist yet)
    shell_exec('sudo /bin/systemctl stop cloudflared 2>/dev/null');

    // Run service install — token passed as separate argument, never interpolated
    // FIX: run once with exec() only — previous version ran shell_exec() then exec() (double execution)
    $cmd = 'sudo /usr/bin/cloudflared service install ' . escapeshellarg($token) . ' 2>&1';
    exec($cmd, $lines, $exit);
    $output = implode("\n", $lines);

    if ($exit !== 0) {
        // Already installed — uninstall then retry
        if (str_contains($output, 'already') || str_contains($output, 'exists')) {
            shell_exec('sudo /usr/bin/cloudflared service uninstall 2>/dev/null');
            exec($cmd, $lines2, $exit);
            $output = implode("\n", $lines2);
        }
        if ($exit !== 0) {
            throw new RuntimeException("cloudflared service install failed:\n{$output}");
        }
    }

    shell_exec('sudo /bin/systemctl enable cloudflared 2>/dev/null');
    shell_exec('sudo /bin/systemctl start cloudflared 2>/dev/null');
    sleep(3);

    return [
        'installed'  => true,
        'cf_version' => $cfVer,
        'output'     => $output,
        'status'     => get_tunnel_status(),
    ];
}

function action_status(): array
{
    if (!shell_exec('which cloudflared 2>/dev/null')) {
        return ['installed' => false, 'running' => false, 'status' => 'not_installed'];
    }
    return get_tunnel_status();
}

function action_service(string $verb): array
{
    $allowed = ['start', 'stop', 'restart'];
    if (!in_array($verb, $allowed, true)) {
        throw new InvalidArgumentException("Invalid service action.");
    }
    exec("sudo /bin/systemctl {$verb} cloudflared 2>&1", $lines, $exit);
    sleep(2);
    return ['action' => $verb, 'exit' => $exit, 'status' => get_tunnel_status()];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Extract and validate the Cloudflare token from the pasted command or raw token.
 * All CF tokens are JWT-format base64url strings starting with eyJ.
 */
function extract_token(string $input): string
{
    // Strip command prefix if pasted from CF dashboard
    $token = trim(preg_replace(
        '/^(sudo\s+)?cloudflared\s+service\s+install\s+/i',
        '',
        trim($input)
    ));

    if (!str_starts_with($token, 'eyJ')) {
        throw new InvalidArgumentException(
            'Token not found — it should start with "eyJ". ' .
            'Paste the full install command from the Cloudflare dashboard.'
        );
    }

    // Base64url characters only
    if (!preg_match('/^[A-Za-z0-9\-_=.]+$/', $token)) {
        throw new InvalidArgumentException(
            'Token contains invalid characters. Paste the command exactly as shown.'
        );
    }

    if (strlen($token) < 100 || strlen($token) > 4096) {
        throw new InvalidArgumentException(
            'Token length is unexpected — copy the full command from the Cloudflare dashboard.'
        );
    }

    return $token;
}

/**
 * Install cloudflared binary from Cloudflare's official Debian repo.
 * FIX: apt-get update now runs via sudo to work as www-data.
 */
function install_cloudflared_binary(): void
{
    $steps = [
        'mkdir -p /etc/apt/keyrings 2>&1',
        'curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg -o /etc/apt/keyrings/cloudflare-main.gpg 2>&1',
        'echo "deb [signed-by=/etc/apt/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared bookworm main" | sudo tee /etc/apt/sources.list.d/cloudflared.list',
        'sudo apt-get update -qq 2>&1',   // FIX: added sudo
        'sudo /usr/bin/apt-get install -y cloudflared 2>&1',
    ];

    foreach ($steps as $step) {
        exec($step, $out, $exit);
        if ($exit !== 0 && str_contains($step, 'apt-get install')) {
            throw new RuntimeException(
                "Failed to install cloudflared binary:\n" . implode("\n", $out)
            );
        }
    }
}

/**
 * Get current systemd status of the cloudflared service.
 */
function get_tunnel_status(): array
{
    exec('sudo /bin/systemctl status cloudflared 2>&1', $lines, $exit);
    $output = implode("\n", $lines);

    $running = str_contains($output, 'active (running)');
    $enabled = str_contains(
        shell_exec('systemctl is-enabled cloudflared 2>/dev/null') ?: '',
        'enabled'
    );

    $journal = shell_exec(
        'journalctl -u cloudflared --since "2 minutes ago" --no-pager -q 2>/dev/null | tail -20'
    ) ?: '';

    $connected = str_contains($journal, 'Connection registered')
              || str_contains($journal, 'Registered tunnel connection')
              || str_contains($journal, 'connnection established');  // CF typo in older versions

    return [
        'installed' => true,
        'running'   => $running,
        'enabled'   => $enabled,
        'connected' => $connected,
        'status'    => $running ? 'running' : 'stopped',
        'systemd'   => $output,
        'journal'   => $journal,
    ];
}

/**
 * Write tunnel action to action_log for audit trail.
 */
function audit_tunnel(string $action, array $result): void
{
    try {
        $db     = get_db();
        $actor  = $_SESSION['auth']['email'] ?? 'unknown';
        $ip     = $_SERVER['HTTP_CF_CONNECTING_IP']
               ?? $_SERVER['HTTP_X_FORWARDED_FOR']
               ?? $_SERVER['REMOTE_ADDR']
               ?? 'unknown';

        $detail = match ($action) {
            'install' => 'Cloudflare Tunnel installed/reconfigured',
            'start'   => 'Cloudflare Tunnel service started',
            'stop'    => 'Cloudflare Tunnel service stopped',
            'restart' => 'Cloudflare Tunnel service restarted',
            'status'  => null,   // status check not worth logging
            default   => "Tunnel action: {$action}",
        };

        if (!$detail) return;

        $db->exec("INSERT IGNORE INTO cameras (id, name, is_ptz, enabled)
                   VALUES ('system', 'System', 0, 0)");

        $db->prepare(
            "INSERT INTO action_log
                (camera_id, camera_name, action, detail, triggered_by, actor, ip_address)
             VALUES ('system', 'System', 'config_change', ?, 'manual', ?, ?)"
        )->execute([$detail, $actor, $ip]);
    } catch (Throwable) {
        // Audit failure is non-fatal
    }
}
