<?php
/**
 * api.php — JSON endpoint for all AJAX calls from the frontend.
 * Actions: sync, save_schedule, manual_start, manual_stop, get_log
 */

require_once '/etc/ptz/config.php';
require_once __DIR__ . '/ProtectAPI.php';

// Protect API endpoint — check session before any output
if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') {
    require_once __DIR__ . '/auth_check.php';
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ── Rate limiting ─────────────────────────────────────────────────────────────
// Sliding window counter stored in DB. Per session for authenticated users,
// per IP as fallback. Separate limits for read vs write actions.

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Classify action
$WRITE_ACTIONS = ['sync','save_schedule','manual_start','manual_stop',
                  'test_patrol','copy_schedule','toggle_enabled'];
$isWrite = in_array($action, $WRITE_ACTIONS, true);

// Rate limit key: prefer session ID (tied to authed user), fall back to IP
$rateLimitKey = session_id() ?: ($_SERVER['HTTP_CF_CONNECTING_IP']
             ?? $_SERVER['HTTP_X_FORWARDED_FOR']
             ?? $_SERVER['REMOTE_ADDR']
             ?? 'unknown');
$rateLimitKey = hash('sha256', $rateLimitKey);  // don't store raw IDs/IPs

$limitMax    = $isWrite ? RATE_LIMIT_WRITES : RATE_LIMIT_READS;
$windowSecs  = RATE_LIMIT_WINDOW;

try {
    $rl = get_db();

    // Create rate_limit table on first use (lightweight, no schema migration needed)
    $rl->exec("CREATE TABLE IF NOT EXISTS rate_limit (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rate_key    VARCHAR(64) NOT NULL,
        action_type ENUM('read','write') NOT NULL,
        hit_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_key_type_time (rate_key, action_type, hit_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $type = $isWrite ? 'write' : 'read';

    // Count hits in current window
    $countStmt = $rl->prepare("
        SELECT COUNT(*) FROM rate_limit
        WHERE rate_key = ? AND action_type = ?
          AND hit_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $countStmt->execute([$rateLimitKey, $type, $windowSecs]);
    $hits = (int)$countStmt->fetchColumn();

    if ($hits >= $limitMax) {
        http_response_code(429);
        header("Retry-After: {$windowSecs}");
        echo json_encode([
            'ok'    => false,
            'error' => "Rate limit exceeded. Max {$limitMax} {$type} requests per {$windowSecs}s.",
            'retry_after' => $windowSecs,
        ]);
        exit;
    }

    // Record this hit
    $rl->prepare("INSERT INTO rate_limit (rate_key, action_type) VALUES (?, ?)")
       ->execute([$rateLimitKey, $type]);

    // Prune old entries every ~5% of requests to avoid table bloat
    if (random_int(1, 20) === 1) {
        $rl->exec("DELETE FROM rate_limit WHERE hit_at < DATE_SUB(NOW(), INTERVAL {$windowSecs} SECOND)");
    }

} catch (Throwable $rlEx) {
    // Rate limit DB failure is non-fatal — log and continue
    error_log('PTZ rate_limit error: ' . $rlEx->getMessage());
}

try {
    $db  = get_db();
    $api = new ProtectAPI();

    $result = match ($action) {
        'sync'         => action_sync($db, $api),
        'save_schedule'=> action_save_schedule($db),
        'manual_start' => action_manual($db, $api, 'start'),
        'manual_stop'  => action_manual($db, $api, 'stop'),
        'get_cameras'  => action_get_cameras($db),
        'get_schedule' => action_get_schedule($db),
        'get_log'       => action_get_log($db),
        'test_patrol'   => action_test_patrol($db, $api),
        'copy_schedule' => action_copy_schedule($db),
        'test_snapshot' => action_test_snapshot($api),
        default        => throw new InvalidArgumentException("Unknown action: {$action}"),
    };

    echo json_encode(['ok' => true, 'data' => $result]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// ── Actions ───────────────────────────────────────────────────────────────────

/**
 * Pull cameras, patrols, presets from Protect and upsert into DB.
 * Only stores PTZ cameras.
 */
function action_sync(PDO $db, ProtectAPI $api): array
{
    $cameras    = $api->getCameras();
    $synced     = 0;
    $ptz_count  = 0;

    foreach ($cameras as $cam) {
        if (!($cam['isPtz'] ?? false)) continue;
        $ptz_count++;

        // Detect if this camera has real built-in patrols
        $patrols    = $api->getPatrols($cam['id']);
        $presets    = $api->getPresets($cam['id']);
        $hasPatrol  = count($patrols) > 0;

        // Upsert camera
        $stmt = $db->prepare("
            INSERT INTO cameras (id, name, model, state, is_ptz, has_patrol, last_synced)
            VALUES (:id, :name, :model, :state, 1, :has_patrol, NOW())
            ON DUPLICATE KEY UPDATE
                name        = VALUES(name),
                model       = VALUES(model),
                state       = VALUES(state),
                has_patrol  = VALUES(has_patrol),
                last_synced = VALUES(last_synced)
        ");
        $stmt->execute([
            ':id'         => $cam['id'],
            ':name'       => $cam['name'] ?? 'Unknown Camera',
            ':model'      => $cam['type'] ?? null,
            ':state'      => $cam['state'] ?? 'UNKNOWN',
            ':has_patrol' => $hasPatrol ? 1 : 0,
        ]);

        // Replace patrols
        $db->prepare("DELETE FROM camera_patrols WHERE camera_id = ?")->execute([$cam['id']]);
        $pStmt = $db->prepare("
            INSERT INTO camera_patrols (camera_id, patrol_id, patrol_name)
            VALUES (?, ?, ?)
        ");
        foreach ($patrols as $p) {
            $pStmt->execute([$cam['id'], $p['id'], $p['name'] ?? 'Patrol']);
        }

        // Replace presets
        $db->prepare("DELETE FROM camera_presets WHERE camera_id = ?")->execute([$cam['id']]);
        $prStmt = $db->prepare("
            INSERT INTO camera_presets (camera_id, slot, preset_name)
            VALUES (?, ?, ?)
        ");
        foreach ($presets as $p) {
            $prStmt->execute([$cam['id'], $p['slot'], $p['name'] ?? "Preset {$p['slot']}"]);
        }

        // Ensure a camera_schedules row exists (defaults only, don't overwrite)
        $db->prepare("
            INSERT IGNORE INTO camera_schedules (camera_id, mode)
            VALUES (?, ?)
        ")->execute([$cam['id'], $hasPatrol ? 'patrol' : 'cycle']);

        log_action($db, $cam['id'], 'sync', "Synced: {$cam['name']}");
        $synced++;
    }

    return [
        'total_cameras' => count($cameras),
        'ptz_cameras'   => $ptz_count,
        'synced'        => $synced,
    ];
}

/** Return all PTZ cameras with their schedule config. */
function action_get_cameras(PDO $db): array
{
    $cameras = $db->query("
        SELECT c.*, cs.id AS schedule_id, cs.mode, cs.patrol_id AS active_patrol_id,
               cs.cycle_slots, cs.dwell_seconds, cs.return_home
        FROM cameras c
        LEFT JOIN camera_schedules cs ON cs.camera_id = c.id
        WHERE c.is_ptz = 1
        ORDER BY c.name
    ")->fetchAll();

    foreach ($cameras as &$cam) {
        $cam['patrols'] = $db->prepare("SELECT * FROM camera_patrols WHERE camera_id = ?")
            ->execute([$cam['id']]) ? [] : [];

        $stmt = $db->prepare("SELECT * FROM camera_patrols WHERE camera_id = ?");
        $stmt->execute([$cam['id']]);
        $cam['patrols'] = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT * FROM camera_presets WHERE camera_id = ? ORDER BY slot");
        $stmt->execute([$cam['id']]);
        $cam['presets'] = $stmt->fetchAll();

        // Day schedules
        if ($cam['schedule_id']) {
            $stmt = $db->prepare("SELECT * FROM schedule_days WHERE schedule_id = ? ORDER BY day_of_week");
            $stmt->execute([$cam['schedule_id']]);
            $cam['schedule_days'] = $stmt->fetchAll();
        } else {
            $cam['schedule_days'] = [];
        }
    }

    return $cameras;
}

/** Save schedule config for one camera. Expects POST JSON body. */
function action_save_schedule(PDO $db): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new InvalidArgumentException('Invalid JSON body');

    $cameraId = $input['camera_id'] ?? throw new InvalidArgumentException('camera_id required');

    // Verify camera exists
    $cam = $db->prepare("SELECT id FROM cameras WHERE id = ? AND is_ptz = 1");
    $cam->execute([$cameraId]);
    if (!$cam->fetch()) throw new RuntimeException("Camera not found: {$cameraId}");

    // ── Capture previous state before any changes (for audit diff) ──────────────
    $prevStmt = $db->prepare("
        SELECT c.name, c.enabled AS prev_enabled,
               cs.mode AS prev_mode
        FROM cameras c
        LEFT JOIN camera_schedules cs ON cs.camera_id = c.id
        WHERE c.id = ?
    ");
    $prevStmt->execute([$cameraId]);
    $prev     = $prevStmt->fetch() ?: [];
    $camName  = $prev['name'] ?? $cameraId;
    $prevEnabled = isset($prev['prev_enabled']) ? (int)$prev['prev_enabled'] : null;
    $prevMode    = $prev['prev_mode'] ?? null;

    $newEnabled = (int)($input['enabled'] ?? 0);
    $newMode    = $input['mode'] ?? 'patrol';

    $db->beginTransaction();

    // Toggle enabled on cameras table
    $db->prepare("UPDATE cameras SET enabled = ? WHERE id = ?")
       ->execute([$newEnabled, $cameraId]);

    // Upsert camera_schedules
    $db->prepare("
        INSERT INTO camera_schedules (camera_id, mode, patrol_id, cycle_slots, dwell_seconds, return_home)
        VALUES (:cid, :mode, :patrol_id, :cycle_slots, :dwell, :home)
        ON DUPLICATE KEY UPDATE
            mode          = VALUES(mode),
            patrol_id     = VALUES(patrol_id),
            cycle_slots   = VALUES(cycle_slots),
            dwell_seconds = VALUES(dwell_seconds),
            return_home   = VALUES(return_home)
    ")->execute([
        ':cid'        => $cameraId,
        ':mode'       => $newMode,
        ':patrol_id'  => $input['patrol_id'] ?? null,
        ':cycle_slots'=> $input['cycle_slots'] ?? null,
        ':dwell'      => max(5, (int)($input['dwell_seconds'] ?? 30)),
        ':home'       => (int)($input['return_home'] ?? 1),
    ]);

    // Get schedule row id
    $scheduleId = $db->prepare("SELECT id FROM camera_schedules WHERE camera_id = ?");
    $scheduleId->execute([$cameraId]);
    $sid = $scheduleId->fetchColumn();

    // Replace day schedules
    $db->prepare("DELETE FROM schedule_days WHERE schedule_id = ?")->execute([$sid]);

    $dayStmt = $db->prepare("
        INSERT INTO schedule_days (schedule_id, day_of_week, patrol_start, patrol_stop, enabled)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($input['days'] ?? [] as $day) {
        $dayStmt->execute([
            $sid,
            (int)$day['day_of_week'],
            $day['patrol_start'],
            $day['patrol_stop'],
            (int)($day['enabled'] ?? 1),
        ]);
    }

    $db->commit();

    // ── Generate specific audit entries based on what actually changed ────────

    // 1. Enable/disable state change
    if ($prevEnabled !== null && $prevEnabled !== $newEnabled) {
        $action = $newEnabled ? 'patrol_start' : 'patrol_stop';
        $detail = $newEnabled
            ? "Patrol scheduling ENABLED for {$camName} — camera will follow schedule"
            : "Patrol scheduling DISABLED for {$camName} — camera will not patrol";
        log_action($db, $cameraId, $action, $detail, 'manual', $newMode);
    }

    // 2. Mode switch (patrol ↔ cycle)
    if ($prevMode !== null && $prevMode !== $newMode) {
        log_action($db, $cameraId, 'schedule_change',
            "Patrol mode changed for {$camName}: {$prevMode} → {$newMode}",
            'manual', $newMode);
    }

    // 3. General schedule update (times, days etc) — always log
    $dayCount = count(array_filter($input['days'] ?? [], fn($d) => $d['enabled'] ?? false));
    log_action($db, $cameraId, 'schedule_change',
        "Schedule saved for {$camName} — mode: {$newMode}, {$dayCount} active day(s)",
        'manual', $newMode);

    return ['saved' => true, 'camera_id' => $cameraId];
}

/** Get schedule for a specific camera (for the schedule editor). */
function action_get_schedule(PDO $db): array
{
    $cameraId = $_GET['camera_id'] ?? throw new InvalidArgumentException('camera_id required');

    $stmt = $db->prepare("
        SELECT c.id, c.name, c.model, c.has_patrol, c.enabled,
               cs.id AS schedule_id, cs.mode, cs.patrol_id AS active_patrol_id,
               cs.cycle_slots, cs.dwell_seconds, cs.return_home
        FROM cameras c
        LEFT JOIN camera_schedules cs ON cs.camera_id = c.id
        WHERE c.id = ?
    ");
    $stmt->execute([$cameraId]);
    $camera = $stmt->fetch();
    if (!$camera) throw new RuntimeException("Camera not found");

    $stmt = $db->prepare("SELECT * FROM camera_patrols WHERE camera_id = ?");
    $stmt->execute([$cameraId]);
    $camera['patrols'] = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT * FROM camera_presets WHERE camera_id = ? ORDER BY slot");
    $stmt->execute([$cameraId]);
    $camera['presets'] = $stmt->fetchAll();

    $camera['schedule_days'] = [];
    if ($camera['schedule_id']) {
        $stmt = $db->prepare("SELECT * FROM schedule_days WHERE schedule_id = ? ORDER BY day_of_week");
        $stmt->execute([$camera['schedule_id']]);
        $camera['schedule_days'] = $stmt->fetchAll();
    }

    return $camera;
}

/** Manually trigger start or stop for a camera. */
function action_manual(PDO $db, ProtectAPI $api, string $direction): array
{
    $input    = json_decode(file_get_contents('php://input'), true);
    $cameraId = $input['camera_id'] ?? $_POST['camera_id'] ?? throw new InvalidArgumentException('camera_id required');

    $stmt = $db->prepare("
        SELECT c.*, cs.mode, cs.patrol_id AS active_patrol_id, cs.cycle_slots, cs.return_home
        FROM cameras c
        LEFT JOIN camera_schedules cs ON cs.camera_id = c.id
        WHERE c.id = ?
    ");
    $stmt->execute([$cameraId]);
    $cam = $stmt->fetch();
    if (!$cam) throw new RuntimeException("Camera not found");

    $mode = $cam['mode'] ?? 'unknown';

    if ($direction === 'start') {
        if ($mode === 'patrol' && $cam['active_patrol_id']) {
            $api->startPatrol($cameraId, $cam['active_patrol_id']);
            log_action($db, $cameraId, 'patrol_start', 'Manual start via dashboard',
                'manual', $mode, $api->lastStatus);
        } else {
            $slots = array_map('intval', explode(',', $cam['cycle_slots'] ?? '1'));
            $api->gotoPreset($cameraId, $slots[0]);
            log_action($db, $cameraId, 'preset_move', "Manual cycle start — moved to slot {$slots[0]}",
                'manual', $mode, $api->lastStatus);
        }
    } else {
        if ($mode === 'patrol') {
            $api->stopPatrol($cameraId);
            log_action($db, $cameraId, 'patrol_stop', 'Manual stop via dashboard',
                'manual', $mode, $api->lastStatus);
        }
        if ($cam['return_home']) {
            $api->gotoPreset($cameraId, 0);
            log_action($db, $cameraId, 'preset_move', 'Returned to home preset (slot 0)',
                'manual', $mode, $api->lastStatus);
        }
    }

    return ['action' => $direction, 'camera_id' => $cameraId];
}

/** Fetch recent action log entries. */
function action_get_log(PDO $db): array
{
    $cameraId  = $_GET['camera_id']   ?? null;
    $action    = $_GET['action_type'] ?? null;
    $triggeredBy = $_GET['triggered_by'] ?? null;
    $dateFrom  = $_GET['date_from']   ?? null;
    $dateTo    = $_GET['date_to']     ?? null;
    $search    = $_GET['search']      ?? null;
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $perPage   = min(500, max(10, (int)($_GET['per_page'] ?? 100)));
    $offset    = ($page - 1) * $perPage;

    // Build WHERE clause dynamically
    $where  = [];
    $params = [];

    if ($cameraId) {
        $where[]  = 'al.camera_id = ?';
        $params[] = $cameraId;
    }
    if ($action && in_array($action, ['patrol_start','patrol_stop','preset_move','sync','error'], true)) {
        $where[]  = 'al.action = ?';
        $params[] = $action;
    }
    if ($triggeredBy && in_array($triggeredBy, ['daemon','manual','sync'], true)) {
        $where[]  = 'al.triggered_by = ?';
        $params[] = $triggeredBy;
    }
    if ($dateFrom) {
        $where[]  = 'al.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo) {
        $where[]  = 'al.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }
    if ($search) {
        $where[]  = '(al.camera_name LIKE ? OR al.detail LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Total count for pagination
    $countStmt = $db->prepare("SELECT COUNT(*) FROM action_log al {$whereSQL}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Fetch page
    $params[] = $perPage;
    $params[] = $offset;

    $stmt = $db->prepare("
        SELECT al.id, al.camera_id,
               COALESCE(al.camera_name, c.name, al.camera_id) AS camera_name,
               al.action, al.camera_mode, al.detail,
               al.api_status, al.api_response,
               al.triggered_by, al.created_at
        FROM action_log al
        LEFT JOIN cameras c ON c.id = al.camera_id
        {$whereSQL}
        ORDER BY al.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    return [
        'logs'      => $stmt->fetchAll(),
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'pages'     => (int)ceil($total / $perPage),
    ];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function log_action(
    PDO    $db,
    string $cameraId,
    string $action,
    string $detail      = '',
    string $by          = 'sync',
    string $mode        = 'unknown',
    ?int   $apiStatus   = null,
    string $apiResponse = '',
    ?string $actor      = null,
    ?string $ipAddress  = null
): void {
    // Fetch camera name for denormalised log (survives camera deletion)
    static $nameCache = [];
    if (!isset($nameCache[$cameraId])) {
        $s = $db->prepare("SELECT name FROM cameras WHERE id = ?");
        $s->execute([$cameraId]);
        $nameCache[$cameraId] = $s->fetchColumn() ?: $cameraId;
    }

    // Auto-populate actor from session if not explicitly provided
    if ($actor === null && isset($_SESSION['auth']['email'])) {
        $actor = $_SESSION['auth']['email'];
    }
    // Auto-populate IP if not explicitly provided
    if ($ipAddress === null && $by !== 'daemon') {
        $ipAddress = get_client_ip();
    }

    $db->prepare("
        INSERT INTO action_log
            (camera_id, camera_name, action, camera_mode, detail,
             api_status, api_response, triggered_by, actor, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $cameraId,
        $nameCache[$cameraId],
        $action,
        $mode,
        $detail,
        $apiStatus,
        $apiResponse ? substr($apiResponse, 0, 500) : null,
        $by,
        $actor,
        $ipAddress,
    ]);
}

/**
 * Get the real client IP, accounting for Cloudflare headers.
 * Cloudflare sets CF-Connecting-IP to the original client IP.
 */
function get_client_ip(): string
{
    // Cloudflare passes the real IP here
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP) ?: 'unknown';
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        return filter_var($ip, FILTER_VALIDATE_IP) ?: 'unknown';
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Test connection + snapshot (used by setup wizard)
function action_test_snapshot(ProtectAPI $api): array
{
    $cameras = $api->getCameras();
    $ptz = array_filter($cameras, fn($c) => $c['isPtz'] ?? false);
    return [
        'connected'    => true,
        'total_cameras'=> count($cameras),
        'ptz_cameras'  => count($ptz),
    ];
}

// ── Test patrol ───────────────────────────────────────────────────────────────
/**
 * Start a short test patrol immediately, regardless of schedule.
 * Sets test_until = NOW() + duration on camera_schedules.
 * The daemon reads this, fires the patrol, and stops it when the time passes.
 * Falls back to direct API call if the daemon cycle timing would add latency.
 */
function action_test_patrol(PDO $db, ProtectAPI $api): array
{
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $cameraId = $input['camera_id'] ?? throw new InvalidArgumentException('camera_id required');
    $duration = max(10, min(300, (int)($input['duration_seconds'] ?? 30)));

    // Verify camera exists
    $stmt = $db->prepare("
        SELECT c.*, cs.mode, cs.patrol_id AS active_patrol_id,
               cs.cycle_slots, cs.return_home, cs.id AS schedule_id
        FROM cameras c
        LEFT JOIN camera_schedules cs ON cs.camera_id = c.id
        WHERE c.id = ? AND c.is_ptz = 1
    ");
    $stmt->execute([$cameraId]);
    $cam = $stmt->fetch();
    if (!$cam) throw new RuntimeException("Camera not found: {$cameraId}");

    // Set test window in DB — daemon will pick this up within 60s
    // Also fire immediately via API for instant feedback
    $until = date('Y-m-d H:i:s', time() + $duration);
    $db->prepare("
        UPDATE camera_schedules
        SET test_until = ?, test_duration = ?
        WHERE camera_id = ?
    ")->execute([$until, $duration, $cameraId]);

    // Fire immediately via API (don't wait for daemon poll)
    if ($cam['mode'] === 'patrol' && $cam['active_patrol_id']) {
        $api->startPatrol($cameraId, $cam['active_patrol_id']);
    } elseif ($cam['mode'] === 'cycle' && $cam['cycle_slots']) {
        $slots = array_map('intval', explode(',', $cam['cycle_slots']));
        $api->gotoPreset($cameraId, $slots[0]);
    } else {
        throw new RuntimeException('No patrol or preset configured — save a schedule first');
    }

    log_action($db, $cameraId, 'patrol_start', "Test patrol started — duration {$duration}s", 'manual', $cam['mode'] ?? 'unknown', $api->lastStatus);

    return [
        'started'    => true,
        'camera_id'  => $cameraId,
        'duration'   => $duration,
        'stops_at'   => $until,
    ];
}

// ── Copy schedule ─────────────────────────────────────────────────────────────
/**
 * Copy one camera's schedule (mode, patrol_id, cycle_slots, dwell, days)
 * to one or more target cameras. Preserves each target's enabled state.
 */
function action_copy_schedule(PDO $db): array
{
    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $sourceId  = $input['source_camera_id'] ?? throw new InvalidArgumentException('source_camera_id required');
    $targetIds = $input['target_camera_ids'] ?? throw new InvalidArgumentException('target_camera_ids required');

    if (!is_array($targetIds) || empty($targetIds)) {
        throw new InvalidArgumentException('target_camera_ids must be a non-empty array');
    }

    // Fetch source schedule
    $stmt = $db->prepare("SELECT * FROM camera_schedules WHERE camera_id = ?");
    $stmt->execute([$sourceId]);
    $src = $stmt->fetch();
    if (!$src) throw new RuntimeException("No schedule found for source camera {$sourceId}");

    // Fetch source days
    $stmt = $db->prepare("SELECT * FROM schedule_days WHERE schedule_id = ?");
    $stmt->execute([$src['id']]);
    $srcDays = $stmt->fetchAll();

    $copied = 0;
    $db->beginTransaction();

    foreach ($targetIds as $targetId) {
        if ($targetId === $sourceId) continue;

        // Verify target exists
        $chk = $db->prepare("SELECT id FROM cameras WHERE id = ? AND is_ptz = 1");
        $chk->execute([$targetId]);
        if (!$chk->fetch()) continue;

        // Upsert schedule (keep target's enabled state)
        $db->prepare("
            INSERT INTO camera_schedules
                (camera_id, mode, patrol_id, cycle_slots, dwell_seconds, return_home)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                mode          = VALUES(mode),
                patrol_id     = VALUES(patrol_id),
                cycle_slots   = VALUES(cycle_slots),
                dwell_seconds = VALUES(dwell_seconds),
                return_home   = VALUES(return_home)
        ")->execute([
            $targetId,
            $src['mode'],
            $src['patrol_id'],
            $src['cycle_slots'],
            $src['dwell_seconds'],
            $src['return_home'],
        ]);

        // Get target schedule id
        $stmt = $db->prepare("SELECT id FROM camera_schedules WHERE camera_id = ?");
        $stmt->execute([$targetId]);
        $targetScheduleId = $stmt->fetchColumn();

        // Replace days
        $db->prepare("DELETE FROM schedule_days WHERE schedule_id = ?")
           ->execute([$targetScheduleId]);

        $dayStmt = $db->prepare("
            INSERT INTO schedule_days (schedule_id, day_of_week, patrol_start, patrol_stop, enabled)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($srcDays as $day) {
            $dayStmt->execute([
                $targetScheduleId,
                $day['day_of_week'],
                $day['patrol_start'],
                $day['patrol_stop'],
                $day['enabled'],
            ]);
        }

        log_action($db, $targetId, 'schedule_change',
            "Schedule copied from camera {$sourceId} by " . ($_SESSION['auth']['email'] ?? 'unknown'),
            'manual');
        \$copied++;
    }

    $db->commit();

    return [
        'copied'    => $copied,
        'source_id' => $sourceId,
        'targets'   => count($targetIds),
    ];
}

// ── Toggle camera scheduling on/off ──────────────────────────────────────────
/**
 * Lightweight enable/disable — just flips the enabled flag and logs it clearly.
 * The dashboard toggle calls this instead of a full save_schedule.
 */
function action_toggle_enabled(PDO $db): array
{
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $cameraId = $input['camera_id'] ?? throw new InvalidArgumentException('camera_id required');
    $enabled  = (int)(bool)($input['enabled'] ?? false);

    $stmt = $db->prepare(
        "SELECT c.name, c.enabled AS prev_enabled, cs.mode
         FROM cameras c
         LEFT JOIN camera_schedules cs ON cs.camera_id = c.id
         WHERE c.id = ? AND c.is_ptz = 1"
    );
    $stmt->execute([$cameraId]);
    $cam = $stmt->fetch();
    if (!$cam) throw new RuntimeException("Camera not found: {$cameraId}");

    $camName = $cam['name'];
    $mode    = $cam['mode'] ?? 'patrol';
    $prev    = (int)$cam['prev_enabled'];

    // Nothing changed — no-op
    if ($prev === $enabled) {
        return ['enabled' => (bool)$enabled, 'camera_id' => $cameraId, 'changed' => false];
    }

    $db->prepare("UPDATE cameras SET enabled = ? WHERE id = ?")
       ->execute([$enabled, $cameraId]);

    // Log as patrol_start or patrol_stop so it appears clearly in the timeline
    $action = $enabled ? 'patrol_start' : 'patrol_stop';
    $detail = $enabled
        ? "Patrol scheduling ENABLED for {$camName} via dashboard toggle"
        : "Patrol scheduling DISABLED for {$camName} via dashboard toggle";

    log_action($db, $cameraId, $action, $detail, 'manual', $mode);

    return ['enabled' => (bool)$enabled, 'camera_id' => $cameraId, 'changed' => true];
}
