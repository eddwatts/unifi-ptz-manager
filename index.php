<?php
/**
 * index.php — PTZ Patrol Manager dashboard.
 * Redirect to setup if not configured.
 */

// First-run guard — redirect to wizard if config or DB is missing
$configPath = '/etc/ptz/config.php';
$setupNeeded = false;

// Auth check happens after config is loaded (below)

if (!file_exists($configPath)) {
    $setupNeeded = true;
} else {
    @include_once $configPath;
    if (!defined('NVR_IP') || !defined('API_TOKEN') || !defined('DB_NAME')) {
        $setupNeeded = true;
    } else {
        try {
            get_db()->query("SELECT 1 FROM cameras LIMIT 1");
        } catch (Throwable) {
            $setupNeeded = true;
        }
    }
}

if ($setupNeeded) {
    header('Location: setup.php');
    exit;
}

// Require Google login
if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') {
    require_once __DIR__ . '/auth_check.php';
} else {
    $authUser = ['name' => 'Admin', 'email' => '', 'picture' => ''];
}

// Fetch cameras from DB for initial render
$cameras = [];
try {
    $db   = get_db();
    $stmt = $db->query("
        SELECT c.*, cs.id AS schedule_id, cs.mode, cs.patrol_id AS active_patrol_id,
               cs.cycle_slots, cs.dwell_seconds, cs.return_home
        FROM cameras c
        LEFT JOIN camera_schedules cs ON cs.camera_id = c.id
        WHERE c.is_ptz = 1
        ORDER BY c.name
    ");
    $cameras = $stmt->fetchAll();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PTZ Patrol Manager</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #0d1117;
    --surface:   #161b22;
    --surface-2: #1c2128;
    --border:    #30363d;
    --accent:    #00b4d8;
    --accent-dk: #0096b7;
    --success:   #3fb950;
    --warning:   #d29922;
    --error:     #f85149;
    --text:      #e6edf3;
    --muted:     #7d8590;
    --radius:    10px;
  }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    min-height: 100vh;
  }

  /* ── Layout ── */
  .topbar {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 0 1.5rem;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
  }

  .topbar-brand {
    display: flex;
    align-items: center;
    gap: .6rem;
    font-weight: 700;
    font-size: 1rem;
    color: var(--accent);
  }

  .topbar-actions { display: flex; gap: .5rem; align-items: center; }

  .main { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }

  /* ── Toolbar ── */
  .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
    gap: .75rem;
  }

  .toolbar-left h2 { font-size: 1.15rem; font-weight: 600; }
  .toolbar-left p  { font-size: .82rem; color: var(--muted); margin-top: .15rem; }

  /* ── Camera grid ── */
  .camera-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.25rem;
  }

  .camera-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: border-color .2s;
  }

  .camera-card:hover { border-color: #484f58; }
  .camera-card.enabled { border-color: rgba(0,180,216,.35); }

  /* Snapshot */
  .snapshot-wrap {
    position: relative;
    width: 100%;
    aspect-ratio: 16/9;
    background: #111;
    overflow: hidden;
    cursor: pointer;
  }

  .snapshot-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: opacity .3s;
  }

  .snapshot-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,.55);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity .2s;
  }
  .snapshot-wrap:hover .snapshot-overlay { opacity: 1; }

  .snapshot-refresh-btn {
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.25);
    color: #fff;
    border-radius: 6px;
    padding: .4rem .8rem;
    font-size: .8rem;
    cursor: pointer;
    display: flex; align-items: center; gap: .35rem;
  }

  .snapshot-age {
    position: absolute;
    bottom: .4rem;
    right: .5rem;
    font-size: .7rem;
    color: rgba(255,255,255,.6);
    background: rgba(0,0,0,.5);
    padding: .15rem .4rem;
    border-radius: 3px;
  }

  .state-badge {
    position: absolute;
    top: .5rem;
    left: .5rem;
    padding: .2rem .55rem;
    border-radius: 4px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
  }
  .state-badge.connected    { background: rgba(63,185,80,.2);  color: var(--success); border: 1px solid rgba(63,185,80,.35); }
  .state-badge.disconnected { background: rgba(248,81,73,.2);  color: var(--error);   border: 1px solid rgba(248,81,73,.35); }
  .state-badge.unknown      { background: rgba(125,133,144,.15); color: var(--muted); border: 1px solid var(--border); }

  /* Card body */
  .camera-body {
    padding: 1rem 1.1rem;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: .75rem;
  }

  .camera-meta {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
  }

  .camera-name {
    font-weight: 600;
    font-size: 1rem;
    line-height: 1.2;
  }

  .camera-model {
    font-size: .75rem;
    color: var(--muted);
    margin-top: .15rem;
  }

  /* Toggle switch */
  .toggle-wrap { display: flex; align-items: center; gap: .4rem; flex-shrink: 0; }
  .toggle-label { font-size: .75rem; color: var(--muted); }

  .toggle {
    position: relative;
    width: 36px;
    height: 20px;
    cursor: pointer;
  }
  .toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
  .toggle-track {
    position: absolute;
    inset: 0;
    background: var(--border);
    border-radius: 10px;
    transition: background .2s;
  }
  .toggle input:checked + .toggle-track { background: var(--accent); }
  .toggle-thumb {
    position: absolute;
    top: 3px; left: 3px;
    width: 14px; height: 14px;
    background: #fff;
    border-radius: 50%;
    transition: transform .2s;
  }
  .toggle input:checked ~ .toggle-thumb { transform: translateX(16px); }

  /* Mode + schedule summary */
  .schedule-summary {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: .65rem .85rem;
    font-size: .8rem;
    color: var(--muted);
    line-height: 1.6;
  }

  .schedule-summary .mode-tag {
    display: inline-block;
    padding: .1rem .45rem;
    border-radius: 3px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-right: .35rem;
  }
  .mode-tag.patrol { background: rgba(0,180,216,.15); color: var(--accent); }
  .mode-tag.cycle  { background: rgba(210,153,34,.15); color: var(--warning); }

  /* Unconfigured badge */
  .new-badge {
    display: inline-block;
    padding: .1rem .45rem;
    border-radius: 3px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    background: rgba(210,153,34,.15);
    color: var(--warning);
    border: 1px solid rgba(210,153,34,.3);
  }

  .camera-card.unconfigured {
    border-style: dashed;
  }
  /* ── Daemon health indicator ── */
  .daemon-health {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .75rem; color: var(--muted);
    padding: .2rem .5rem;
    border: 1px solid var(--border);
    border-radius: 4px;
  }
  .daemon-health .dot {
    width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
  }
  .dot-ok       { background: var(--success); box-shadow: 0 0 5px var(--success); }
  .dot-degraded { background: var(--warning); }
  .dot-error    { background: var(--error); }
  .dot-unknown  { background: var(--muted); }

  /* ── Last action line on camera cards ── */
  .last-action {
    font-size: .72rem; color: var(--muted);
    padding: .3rem .85rem .4rem;
    border-top: 1px solid rgba(48,54,61,.5);
    display: flex; align-items: center; gap: .35rem;
  }
  .last-action .action-tag {
    padding: .05rem .35rem; border-radius: 3px; font-size: .68rem; font-weight: 600;
  }
  .tag-patrol_start { background: rgba(63,185,80,.15);  color: var(--success); }
  .tag-patrol_stop  { background: rgba(248,81,73,.12);  color: var(--error); }
  .tag-preset_move  { background: rgba(0,180,216,.12);  color: var(--accent); }
  .tag-sync         { background: rgba(125,133,144,.12); color: var(--muted); }
  .tag-error        { background: rgba(248,81,73,.12);  color: var(--error); }

  .camera-card.unconfigured .schedule-summary {
    border-color: rgba(210,153,34,.25);
    background: rgba(210,153,34,.05);
  }

  .day-chips { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .4rem; }
  .day-chip {
    padding: .1rem .45rem;
    border-radius: 3px;
    font-size: .7rem;
    border: 1px solid var(--border);
    color: var(--muted);
  }
  .day-chip.active { border-color: rgba(0,180,216,.4); color: var(--accent); background: rgba(0,180,216,.08); }

  /* Card actions */
  .camera-actions {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: .5rem;
    border-top: 1px solid var(--border);
    padding: .75rem 1.1rem;
  }

  .cam-btn {
    padding: .45rem .5rem;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--muted);
    font-size: .78rem;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: .35rem;
    transition: all .2s;
    font-weight: 500;
  }
  .cam-btn:hover { color: var(--text); border-color: #484f58; }
  .cam-btn.start:hover { color: var(--success); border-color: var(--success); }
  .cam-btn.stop:hover  { color: var(--error);   border-color: var(--error); }
  .cam-btn.schedule    { color: var(--accent); border-color: rgba(0,180,216,.4); }
  .cam-btn.schedule:hover { background: rgba(0,180,216,.08); }

  /* Empty state */
  .empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--muted);
    grid-column: 1 / -1;
  }
  .empty-state svg { color: var(--border); margin-bottom: 1rem; }
  .empty-state h3  { font-size: 1.1rem; color: var(--text); margin-bottom: .5rem; }
  .empty-state p   { font-size: .9rem; }

  /* ── Schedule modal ── */
  .modal-bg {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.7);
    backdrop-filter: blur(3px);
    z-index: 200;
    align-items: flex-start;
    justify-content: center;
    padding: 2rem 1rem;
    overflow-y: auto;
  }
  .modal-bg.open { display: flex; }

  .modal {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    width: 100%;
    max-width: 620px;
    animation: slideIn .2s ease;
  }

  @keyframes slideIn { from { opacity: 0; transform: translateY(-12px); } }

  .modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .modal-header h2 { font-size: 1.05rem; font-weight: 600; }
  .modal-header .cam-sub { font-size: .8rem; color: var(--muted); margin-top: .1rem; }

  .modal-close {
    background: none; border: none; color: var(--muted);
    cursor: pointer; font-size: 1.3rem; line-height: 1; padding: .2rem .4rem;
    border-radius: 4px;
  }
  .modal-close:hover { color: var(--text); background: var(--surface-2); }

  .modal-body   { padding: 1.5rem; }
  .modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
  }

  /* Mode selector */
  .mode-selector { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; margin-bottom: 1.25rem; }
  .mode-opt {
    border: 2px solid var(--border);
    border-radius: 8px;
    padding: .8rem 1rem;
    cursor: pointer;
    transition: border-color .2s, background .2s;
  }
  .mode-opt:hover  { border-color: #484f58; }
  .mode-opt.active { border-color: var(--accent); background: rgba(0,180,216,.06); }
  .mode-opt .opt-title { font-weight: 600; font-size: .9rem; margin-bottom: .2rem; }
  .mode-opt .opt-desc  { font-size: .78rem; color: var(--muted); line-height: 1.4; }

  /* Patrol select */
  .form-group { margin-bottom: 1.1rem; }
  .form-group label {
    display: block;
    font-size: .78rem;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: .4rem;
  }

  .form-group select, .form-group input[type="number"] {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    padding: .55rem .75rem;
    font-size: .9rem;
    outline: none;
  }
  .form-group select:focus, .form-group input:focus { border-color: var(--accent); }

  /* Preset checkboxes */
  .preset-grid { display: flex; flex-wrap: wrap; gap: .5rem; }
  .preset-chip {
    display: flex; align-items: center; gap: .3rem;
    padding: .3rem .7rem;
    border: 1px solid var(--border);
    border-radius: 5px;
    cursor: pointer;
    font-size: .82rem;
    color: var(--muted);
    transition: all .2s;
    user-select: none;
  }
  .preset-chip input { display: none; }
  .preset-chip:hover { border-color: #484f58; color: var(--text); }
  .preset-chip.checked { border-color: var(--accent); color: var(--accent); background: rgba(0,180,216,.08); }

  /* Day schedule table */
  .day-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
  .day-table th {
    text-align: left;
    padding: .4rem .5rem;
    color: var(--muted);
    font-size: .73rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    border-bottom: 1px solid var(--border);
  }
  .day-table td { padding: .5rem .5rem; vertical-align: middle; }
  .day-table tr:not(:last-child) td { border-bottom: 1px solid rgba(48,54,61,.5); }

  .day-table input[type="time"] {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 5px;
    color: var(--text);
    padding: .3rem .5rem;
    font-size: .82rem;
    width: 100%;
    outline: none;
  }
  .day-table input[type="time"]:focus { border-color: var(--accent); }

  .day-enabled-cell label {
    display: flex; align-items: center; gap: .3rem;
    cursor: pointer; font-size: .82rem; color: var(--muted);
  }
  .day-enabled-cell input[type="checkbox"] { accent-color: var(--accent); }

  /* Section dividers */
  .section-title {
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--muted);
    margin-bottom: .75rem;
    padding-top: .25rem;
  }

  /* Buttons */
  .btn {
    padding: .55rem 1.1rem;
    border-radius: 6px;
    border: none;
    font-size: .875rem;
    font-weight: 600;
    cursor: pointer;
    display: flex; align-items: center; gap: .4rem;
    transition: opacity .15s, background .15s;
  }
  .btn:disabled { opacity: .5; cursor: not-allowed; }
  .btn-primary { background: var(--accent); color: #000; }
  .btn-primary:hover:not(:disabled) { background: var(--accent-dk); }
  .btn-ghost  { background: transparent; color: var(--muted); border: 1px solid var(--border); }
  .btn-ghost:hover { color: var(--text); }
  .btn-icon   { background: transparent; border: 1px solid var(--border); color: var(--muted); padding: .45rem .75rem; border-radius: 6px; cursor: pointer; }
  .btn-icon:hover { color: var(--text); border-color: #484f58; }

  /* Toast */
  #toast {
    position: fixed;
    bottom: 1.5rem;
    left: 50%;
    transform: translateX(-50%) translateY(4rem);
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: .65rem 1.25rem;
    font-size: .875rem;
    z-index: 999;
    transition: transform .3s ease;
    white-space: nowrap;
    box-shadow: 0 4px 20px rgba(0,0,0,.4);
  }
  #toast.show { transform: translateX(-50%) translateY(0); }
  #toast.success { border-color: var(--success); color: var(--success); }
  #toast.error   { border-color: var(--error);   color: var(--error); }

  /* ── Test patrol timer ── */
  .test-timer {
    display: none;
    align-items: center; gap: .4rem;
    font-size: .75rem; color: var(--accent);
    background: rgba(0,180,216,.08);
    border: 1px solid rgba(0,180,216,.25);
    border-radius: 4px;
    padding: .2rem .55rem;
  }
  .test-timer.active { display: inline-flex; }
  .test-timer svg { flex-shrink: 0; }

  /* ── Copy schedule modal ── */
  .copy-cam-list {
    display: flex; flex-direction: column; gap: .4rem;
    max-height: 240px; overflow-y: auto;
    margin-top: .5rem;
  }
  .copy-cam-item {
    display: flex; align-items: center; gap: .6rem;
    padding: .5rem .7rem;
    border: 1px solid var(--border); border-radius: 6px;
    cursor: pointer; transition: border-color .15s;
    font-size: .85rem;
  }
  .copy-cam-item:hover { border-color: #484f58; }
  .copy-cam-item.selected { border-color: var(--accent); background: rgba(0,180,216,.06); }
  .copy-cam-item input { accent-color: var(--accent); flex-shrink: 0; }
  .copy-cam-snapshot {
    width: 48px; height: 27px; object-fit: cover;
    border-radius: 3px; background: #111; flex-shrink: 0;
  }

  /* ── Mobile schedule modal ── */
  @media (max-width: 520px) {
    .modal-bg { padding: .5rem; }
    .modal { border-radius: 8px; }
    .modal-body { padding: 1rem; }
    .modal-footer { padding: .75rem 1rem; }

    /* Collapse day table to stacked cards on mobile */
    .day-table thead { display: none; }
    .day-table tbody, .day-table tr { display: block; }
    .day-table tr {
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: .5rem .65rem;
      margin-bottom: .4rem;
      background: var(--surface-2);
    }
    .day-table tr:not(:last-child) td { border-bottom: none; }
    .day-table td { display: block; padding: .2rem 0; }

    /* Day label + checkbox row */
    .day-enabled-cell { margin-bottom: .35rem; }
    .day-enabled-cell label { font-size: .875rem; font-weight: 600; color: var(--text); }

    /* Time inputs side by side on mobile */
    .day-times-row { display: flex; gap: .5rem; }
    .day-time-wrap { flex: 1; }
    .day-time-wrap label {
      display: block; font-size: .7rem; color: var(--muted);
      text-transform: uppercase; letter-spacing: .04em; margin-bottom: .2rem;
    }
    .day-table input[type="time"] { font-size: .85rem; }

    /* Mode selector stacks vertically */
    .mode-selector { grid-template-columns: 1fr; }

    /* Preset chips wrap naturally — no change needed */

    /* Camera actions 3-col wrapping on mobile */
    .camera-actions { grid-template-columns: repeat(3, 1fr); }
  }

  @keyframes spin { to { transform: rotate(360deg); } }
  .spin { animation: spin .7s linear infinite; display: inline-block; }

  /* Return home toggle */
  .toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .6rem 0;
    border-top: 1px solid var(--border);
    margin-top: .5rem;
  }
  .toggle-row span { font-size: .875rem; color: var(--muted); }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-brand">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="3"/><path d="M15.5 6.5a4 4 0 0 1 0 11M8.5 6.5a4 4 0 0 0 0 11"/>
    </svg>
    PTZ Patrol Manager
  </div>
  <div class="topbar-actions">
    <div class="daemon-health" id="daemon-health" title="Daemon status">
      <span class="dot dot-unknown" id="daemon-dot"></span>
      <span id="daemon-label">checking…</span>
    </div>
    <span style="font-size:.78rem;color:var(--muted)" id="nvr-info"><?= htmlspecialchars(NVR_IP) ?></span>
    <button class="btn-icon" onclick="openLog()" title="Activity log">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6M9 16h6M9 8h6M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/></svg>
    </button>
    <?php
      $isAdminUser = !empty($authUser['email']) &&
                     strtolower($authUser['email']) === strtolower(defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '');
      if (!$isAdminUser) {
          try {
              $chkRole = get_db()->prepare("SELECT role FROM access_users WHERE email = ? AND enabled = 1");
              $chkRole->execute([$authUser['email'] ?? '']);
              $roleRow = $chkRole->fetch();
              $isAdminUser = ($roleRow['role'] ?? '') === 'admin';
          } catch (Throwable) {}
      }
    ?>
    <?php if ($isAdminUser): ?>
    <a href="users.php" class="btn-icon" title="User access management">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </a>
    <?php endif; ?>
    <a href="setup.php?force=1" class="btn-icon" title="Settings">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </a>
  </div>
</div>

<div class="main">
  <div class="toolbar">
    <div class="toolbar-left">
      <h2>PTZ Cameras</h2>
      <p id="camera-count">
        <?php
          $total        = count($cameras);
          $enabled      = count(array_filter($cameras, fn($c) => $c['enabled']));
          $unconfigured = count(array_filter($cameras, fn($c) => !($c['_has_days'] ?? false)));
          if ($total === 0) {
              echo 'No cameras found — the daemon will sync automatically, or click Sync now';
          } else {
              $parts = ["{$total} cameras", "{$enabled} active"];
              if ($unconfigured > 0) $parts[] = "<span style='color:var(--warning)'>{$unconfigured} not configured</span>";
              echo implode(' · ', $parts);
          }
        ?>
      </p>
      <?php if (!empty($lastSync)): ?>
      <p style="font-size:.74rem;color:var(--muted);margin-top:.2rem">
        Last synced <?= htmlspecialchars(date('D j M, H:i', strtotime($lastSync))) ?> 
        · auto-syncs every <?= SYNC_INTERVAL_HOURS ?? 6 ?>h
      </p>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-ghost" onclick="syncCameras()" id="btn-sync">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Sync cameras
      </button>
    </div>
  </div>

  <!-- Camera grid -->
  <div class="camera-grid" id="camera-grid">
    <?php if (empty($cameras)): ?>
      <?= renderEmptyState() ?>
    <?php else: ?>
      <?php foreach ($cameras as $cam): ?>
        <?= renderCameraCard($cam) ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Schedule Modal -->
<div class="modal-bg" id="schedule-modal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <h2 id="modal-title">Configure Patrol Schedule</h2>
        <div class="cam-sub" id="modal-subtitle"></div>
      </div>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body" id="modal-body">
      <!-- Populated by JS -->
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
      <button class="btn btn-ghost" style="border-color:rgba(0,180,216,.4);color:var(--accent)" id="btn-copy-schedule" onclick="openCopySchedule()"
              title="Copy this schedule to other cameras">Copy to…</button>
      <button class="btn btn-primary" id="btn-save-schedule" onclick="saveSchedule()">Save Schedule</button>
    </div>
  </div>
</div>

<!-- Log Modal -->
<div class="modal-bg" id="log-modal">
  <div class="modal" style="max-width:760px">
    <div class="modal-header">
      <div>
        <h2>Activity Log</h2>
        <div class="cam-sub">Recent camera actions — <a href="log.php" style="color:var(--accent)"
          target="_blank">Open full log viewer →</a></div>
      </div>
      <button class="modal-close" onclick="document.getElementById('log-modal').classList.remove('open')">✕</button>
    </div>
    <!-- Quick filter strip -->
    <div style="padding:.65rem 1.25rem;border-bottom:1px solid var(--border);
                display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <select id="ql-camera" onchange="openLog()"
              style="background:var(--bg);border:1px solid var(--border);border-radius:5px;
                     color:var(--text);padding:.3rem .55rem;font-size:.8rem;outline:none">
        <option value="">All cameras</option>
        <?php foreach ($cameras as $cam): ?>
          <option value="<?= htmlspecialchars($cam['id']) ?>"><?= htmlspecialchars($cam['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="ql-action" onchange="openLog()"
              style="background:var(--bg);border:1px solid var(--border);border-radius:5px;
                     color:var(--text);padding:.3rem .55rem;font-size:.8rem;outline:none">
        <option value="">All actions</option>
        <option value="patrol_start">Patrol start</option>
        <option value="patrol_stop">Patrol stop</option>
        <option value="preset_move">Preset move</option>
        <option value="error">Errors only</option>
        <option value="login">Logins</option>
        <option value="login_denied">Login denied</option>
        <option value="user_change">User changes</option>
        <option value="config_change">Config changes</option>
      </select>
      <span id="log-total" style="font-size:.75rem;color:var(--muted);margin-left:.25rem"></span>
      <a href="log.php" target="_blank" class="btn-icon" style="margin-left:auto;font-size:.75rem">
        Full viewer →
      </a>
    </div>
    <div class="modal-body" id="log-body" style="padding:0;max-height:440px;overflow-y:auto">
      <p style="padding:1.5rem;color:var(--muted);text-align:center">Loading…</p>
    </div>
  </div>
</div>

<div id="toast"></div>

<?php
// ── PHP render helpers ─────────────────────────────────────────────────────

function renderEmptyState(): string
{
    return <<<HTML
    <div class="empty-state">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M15.5 6.5a4 4 0 0 1 0 11M8.5 6.5a4 4 0 0 0 0 11"/></svg>
      <h3>No PTZ cameras found</h3>
      <p>Click <strong>Sync cameras</strong> to pull cameras from UniFi Protect.</p>
    </div>
    HTML;
}

function renderCameraCard(array $cam): string
{
    $id       = htmlspecialchars($cam['id']);
    $name     = htmlspecialchars($cam['name']);
    $model    = htmlspecialchars($cam['model'] ?? 'Unknown model');
    $state    = strtolower($cam['state'] ?? 'unknown');
    $enabled  = (bool)$cam['enabled'];
    $mode     = $cam['mode'] ?? 'patrol';
    $checked  = $enabled ? 'checked' : '';
    $cardCls  = $enabled ? 'camera-card enabled' : 'camera-card';

    $stateLabel = match($state) {
        'connected'    => '<span class="state-badge connected">Live</span>',
        'disconnected' => '<span class="state-badge disconnected">Offline</span>',
        default        => '<span class="state-badge unknown">Unknown</span>',
    };

    $modeTag = $mode === 'patrol'
        ? '<span class="mode-tag patrol">Patrol</span>'
        : '<span class="mode-tag cycle">Cycle</span>';

    // Badge logic: unconfigured = has schedule row but zero schedule_days
    $isNew = !$cam['schedule_id'] ||
             ($cam['schedule_id'] && !isset($cam['_has_days']));
    // Pass _has_days in from the calling loop (set below in initial query)
    $hasScheduleDays = $cam['_has_days'] ?? false;

    if ($hasScheduleDays) {
        $scheduleHtml = "{$modeTag} schedule configured";
    } else {
        $scheduleHtml = '<span class="new-badge">Not configured</span>'
            . '<span style="color:var(--muted);font-size:.78rem;margin-left:.35rem">— click Schedule to set up</span>';
        $cardCls .= ' unconfigured';
    }

    $snapshotUrl = "snapshot.php?camera_id={$id}";

    return <<<HTML
    <div class="{$cardCls}" id="card-{$id}" data-id="{$id}">
      <div class="snapshot-wrap" onclick="refreshSnapshot('{$id}')">
        <img class="snapshot-img" 
             id="snap-{$id}"
             src="{$snapshotUrl}"
             alt="{$name} snapshot"
             loading="lazy">
        {$stateLabel}
        <div class="snapshot-overlay">
          <button class="snapshot-refresh-btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            Refresh
          </button>
        </div>
        <span class="snapshot-age" id="snap-age-{$id}">live</span>
        <span class="test-timer" id="test-timer-{$id}">
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Test: <span id="test-countdown-{$id}">0s</span>
        </span>
      </div>
      <div class="camera-body">
        <div class="camera-meta">
          <div>
            <div class="camera-name">{$name}</div>
            <div class="camera-model">{$model}</div>
          </div>
          <div class="toggle-wrap">
            <span class="toggle-label" id="toggle-lbl-{$id}">{$enabled ? 'On' : 'Off'}</span>
            <label class="toggle" title="Enable patrol scheduling">
              <input type="checkbox" {$checked} onchange="toggleCamera('{$id}', this.checked)">
              <span class="toggle-track"></span>
              <span class="toggle-thumb"></span>
            </label>
          </div>
        </div>
        <div class="schedule-summary" id="summary-{$id}">{$scheduleHtml}</div>
      </div>
      <div class="camera-actions">
        <button class="cam-btn start" onclick="manualAction('{$id}','start')">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          Start
        </button>
        <button class="cam-btn stop" onclick="manualAction('{$id}','stop')">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
          Stop
        </button>
        <button class="cam-btn" onclick="testPatrol('{$id}',this)" title="Run a 30-second test patrol now">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Test
        </button>
        <button class="cam-btn schedule" onclick="openSchedule('{$id}')">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
          Schedule
        </button>
        <button class="cam-btn" onclick="quickDuplicate('{$id}')"
                title="Duplicate this camera's schedule to another camera"
                style="color:var(--muted)">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          Duplicate
        </button>
      </div>
      <div class="last-action" id="last-action-{$id}">
        <span style="color:var(--muted);font-size:.68rem">Loading last action…</span>
      </div>
    </div>
    HTML;
}
?>

<script>
// ── Server config passed from PHP ────────────────────────────────────────────
const PTZ_CONFIG = {
  snapshotRefreshMs: <?= (defined('SNAPSHOT_REFRESH_SECONDS') ? (int)SNAPSHOT_REFRESH_SECONDS : 30) * 1000 ?>,
  statusPublic:      <?= (defined('STATUS_PUBLIC') && STATUS_PUBLIC === 'y') ? 'true' : 'false' ?>,
};

const DAYS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
let currentCameraId = null;

// ── Toast ─────────────────────────────────────────────────────────────────────
function toast(msg, type = 'success') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'show ' + type;
  clearTimeout(el._timer);
  el._timer = setTimeout(() => el.className = '', 3200);
}

// ── API call helper ───────────────────────────────────────────────────────────
async function api(action, data = {}) {
  const url = data.method === 'GET'
    ? `api.php?action=${action}&` + new URLSearchParams(data.params ?? {})
    : 'api.php';

  const opts = data.method === 'GET'
    ? { method: 'GET' }
    : {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...data }),
      };

  const r = await fetch(url, opts);
  const j = await r.json();
  if (!j.ok) throw new Error(j.error || 'API error');
  return j.data;
}

// ── Snapshot management ───────────────────────────────────────────────────────
function refreshSnapshot(cameraId) {
  const img = document.getElementById('snap-' + cameraId);
  const age = document.getElementById('snap-age-' + cameraId);
  if (!img) return;

  img.style.opacity = '.4';
  const ts = Date.now();
  const fresh = `snapshot.php?camera_id=${cameraId}&refresh=1&t=${ts}`;

  const tmp = new Image();
  tmp.onload = () => {
    img.src = fresh;
    img.style.opacity = '1';
    age.textContent = 'just now';
  };
  tmp.onerror = () => {
    img.style.opacity = '1';
    toast('Snapshot unavailable — camera may be offline', 'error');
  };
  tmp.src = fresh;
}

// Auto-refresh snapshots — interval configurable in Settings
setInterval(() => {
  document.querySelectorAll('.camera-card').forEach(card => {
    const id  = card.dataset.id;
    const age = document.getElementById('snap-age-' + id);
    // Quietly update src (no forced-refresh, uses cache)
    const img = document.getElementById('snap-' + id);
    if (img) {
      const ts = Date.now();
      img.src  = `snapshot.php?camera_id=${id}&t=${ts}`;
      if (age) age.textContent = 'live';
    }
  });
}, PTZ_CONFIG.snapshotRefreshMs);

// ── Camera toggle ─────────────────────────────────────────────────────────────
async function toggleCamera(cameraId, enabled) {
  try {
    // Use dedicated toggle_enabled action — lightweight, clean audit log entry
    await api('toggle_enabled', { camera_id: cameraId, enabled: enabled ? 1 : 0 });

    const lbl   = document.getElementById('toggle-lbl-' + cameraId);
    const card  = document.getElementById('card-' + cameraId);
    if (lbl)  lbl.textContent = enabled ? 'On' : 'Off';
    if (card) card.classList.toggle('enabled', enabled);
    toast(enabled ? 'Patrol scheduling enabled' : 'Patrol scheduling disabled');
  } catch (e) {
    toast(e.message, 'error');
  }
}

// ── Manual start/stop ─────────────────────────────────────────────────────────
async function manualAction(cameraId, direction) {
  try {
    await api('manual_' + direction, { camera_id: cameraId });
    toast(direction === 'start' ? 'Patrol started' : 'Patrol stopped');
  } catch (e) {
    toast(e.message, 'error');
  }
}

// ── Sync ──────────────────────────────────────────────────────────────────────
async function syncCameras() {
  const btn = document.getElementById('btn-sync');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin">⟳</span> Syncing…';

  try {
    const d = await api('sync', {});
    toast(`Sync complete — ${d.ptz_cameras} PTZ cameras found`);
    setTimeout(() => location.reload(), 900);
  } catch (e) {
    toast(e.message, 'error');
    btn.disabled = false;
    btn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Sync cameras`;
  }
}

// ── Schedule modal ────────────────────────────────────────────────────────────
async function openSchedule(cameraId) {
  currentCameraId = cameraId;
  document.getElementById('modal-body').innerHTML =
    '<p style="text-align:center;color:var(--muted);padding:2rem">Loading…</p>';
  document.getElementById('schedule-modal').classList.add('open');

  try {
    const data = await api('get_schedule', { method: 'GET', params: { camera_id: cameraId } });
    document.getElementById('modal-title').textContent    = 'Patrol Schedule';
    document.getElementById('modal-subtitle').textContent = data.name + ' · ' + (data.model || '');
    document.getElementById('modal-body').innerHTML       = buildModalHtml(data);
    syncModeUI(data.mode);
  } catch (e) {
    document.getElementById('modal-body').innerHTML =
      `<p style="color:var(--error);padding:1.5rem">${e.message}</p>`;
  }
}

function buildModalHtml(data) {
  const mode         = data.mode || 'patrol';
  const patrolId     = data.active_patrol_id || '';
  const cycleSlots   = (data.cycle_slots || '').split(',').map(Number).filter(Boolean);
  const dwell        = data.dwell_seconds || 30;
  const returnHome   = data.return_home ?? 1;
  const patrols      = data.patrols || [];
  const presets      = data.presets || [];
  const scheduleDays = data.schedule_days || [];

  // Build patrol options
  let patrolOpts = patrols.length === 0
    ? '<option value="">No patrols configured in Protect</option>'
    : patrols.map(p =>
        `<option value="${p.patrol_id}" ${p.patrol_id === patrolId ? 'selected' : ''}>${p.patrol_name}</option>`
      ).join('');

  // Build preset chips (exclude slot 0 = home)
  let presetChips = presets
    .filter(p => p.slot > 0)
    .map(p => {
      const checked = cycleSlots.includes(p.slot);
      return `<label class="preset-chip ${checked ? 'checked' : ''}" onclick="togglePreset(this)">
        <input type="checkbox" value="${p.slot}" ${checked ? 'checked' : ''}>${p.preset_name}
      </label>`;
    }).join('') || '<p style="color:var(--muted);font-size:.82rem">No presets found — sync cameras first.</p>';

  // Build day schedule rows (default times if not set)
  const dayMap = {};
  scheduleDays.forEach(d => { dayMap[d.day_of_week] = d; });

  const dayRows = DAYS.map((name, i) => {
    const d = dayMap[i];
    const startVal  = d ? d.patrol_start.substring(0,5) : '18:00';
    const stopVal   = d ? d.patrol_stop.substring(0,5)  : '08:00';
    const enChecked = d?.enabled !== 0 ? 'checked' : '';
    return `<tr>
      <td class="day-enabled-cell">
        <label><input type="checkbox" class="day-en" data-day="${i}" ${enChecked}>${name}</label>
      </td>
      <td>
        <div class="day-times-row">
          <div class="day-time-wrap">
            <label>Patrol starts</label>
            <input type="time" class="day-start" data-day="${i}" value="${startVal}">
          </div>
          <div class="day-time-wrap">
            <label>Patrol stops</label>
            <input type="time" class="day-stop" data-day="${i}" value="${stopVal}">
          </div>
        </div>
      </td>
    </tr>`;
  }).join('');

  return `
    <div class="section-title">Patrol Mode</div>
    <div class="mode-selector">
      <div class="mode-opt ${mode==='patrol'?'active':''}" id="mode-patrol" onclick="switchMode('patrol')">
        <div class="opt-title">Built-in Patrol</div>
        <div class="opt-desc">Use a patrol configured in UniFi Protect. Requires a G6 PTZ or supported model.</div>
      </div>
      <div class="mode-opt ${mode==='cycle'?'active':''}" id="mode-cycle" onclick="switchMode('cycle')">
        <div class="opt-title">Preset Cycling</div>
        <div class="opt-desc">Step through selected presets on a timed interval. Works on all PTZ models.</div>
      </div>
    </div>

    <div id="patrol-config" style="display:${mode==='patrol'?'block':'none'}">
      <div class="form-group">
        <label>Select Patrol</label>
        <select id="sel-patrol">${patrolOpts}</select>
      </div>
    </div>

    <div id="cycle-config" style="display:${mode==='cycle'?'block':'none'}">
      <div class="form-group">
        <label>Presets to cycle through</label>
        <div class="preset-grid">${presetChips}</div>
      </div>
      <div class="form-group">
        <label>Dwell time (seconds per preset)</label>
        <input type="number" id="dwell-secs" value="${dwell}" min="5" max="300" step="5" style="width:120px">
      </div>
    </div>

    <div style="margin-top:1.25rem">
      <div class="section-title">Weekly Schedule</div>
      <table class="day-table">
        <thead><tr>
          <th>Day</th>
          <th>Times</th>
        </tr></thead>
        <tbody>${dayRows}</tbody>
      </table>
    </div>

    <div class="toggle-row">
      <span>Return to home preset when patrol stops</span>
      <label class="toggle" style="flex-shrink:0">
        <input type="checkbox" id="return-home" ${returnHome ? 'checked' : ''}>
        <span class="toggle-track"></span>
        <span class="toggle-thumb"></span>
      </label>
    </div>`;
}

function togglePreset(el) {
  el.classList.toggle('checked');
}

function switchMode(mode) {
  document.getElementById('mode-patrol').classList.toggle('active', mode === 'patrol');
  document.getElementById('mode-cycle').classList.toggle('active', mode === 'cycle');
  syncModeUI(mode);
}

function syncModeUI(mode) {
  const pc = document.getElementById('patrol-config');
  const cc = document.getElementById('cycle-config');
  if (pc) pc.style.display = mode === 'patrol' ? 'block' : 'none';
  if (cc) cc.style.display = mode === 'cycle'  ? 'block' : 'none';
}

async function saveSchedule() {
  const btn = document.getElementById('btn-save-schedule');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin">⟳</span> Saving…';

  try {
    const mode      = document.getElementById('mode-patrol')?.classList.contains('active') ? 'patrol' : 'cycle';
    const patrolId  = document.getElementById('sel-patrol')?.value || '';
    const dwell     = parseInt(document.getElementById('dwell-secs')?.value || '30');
    const returnHome= document.getElementById('return-home')?.checked ? 1 : 0;

    // Collect checked preset slots
    const slots = [...document.querySelectorAll('.preset-chip.checked input')]
      .map(i => i.value).join(',');

    // Collect day schedules
    const days = [];
    document.querySelectorAll('.day-en').forEach(cb => {
      const day = parseInt(cb.dataset.day);
      days.push({
        day_of_week:  day,
        patrol_start: document.querySelector(`.day-start[data-day="${day}"]`)?.value || '18:00',
        patrol_stop:  document.querySelector(`.day-stop[data-day="${day}"]`)?.value  || '08:00',
        enabled:      cb.checked ? 1 : 0,
      });
    });

    await api('save_schedule', {
      camera_id:     currentCameraId,
      enabled:       1,
      mode,
      patrol_id:     patrolId,
      cycle_slots:   slots,
      dwell_seconds: dwell,
      return_home:   returnHome,
      days,
    });

    toast('Schedule saved');
    closeModal();
    // Refresh the summary on the card
    refreshCardSummary(currentCameraId, mode);
  } catch(e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Save Schedule';
  }
}

function refreshCardSummary(cameraId, mode) {
  const el = document.getElementById('summary-' + cameraId);
  if (!el) return;
  const tag = mode === 'patrol'
    ? '<span class="mode-tag patrol">Patrol</span>'
    : '<span class="mode-tag cycle">Cycle</span>';
  el.innerHTML = `${tag} schedule configured`;

  // Enable toggle
  const card = document.getElementById('card-' + cameraId);
  if (card) card.classList.add('enabled');
}

function closeModal() {
  document.getElementById('schedule-modal').classList.remove('open');
  currentCameraId = null;
}

// Close modal on backdrop click
document.getElementById('schedule-modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

// ── Log modal ─────────────────────────────────────────────────────────────────
async function openLog() {
  document.getElementById('log-modal').classList.add('open');
  const body = document.getElementById('log-body');
  body.innerHTML = '<p style="padding:1.5rem;color:var(--muted);text-align:center">Loading…</p>';

  const cameraId  = document.getElementById('ql-camera')?.value  || '';
  const actionType= document.getElementById('ql-action')?.value  || '';

  try {
    const params = { per_page: 50 };
    if (cameraId)   params.camera_id   = cameraId;
    if (actionType) params.action_type = actionType;

    const data = await api('get_log', { method: 'GET', params });
    const logs  = data.logs  ?? data;   // handle both paginated and legacy flat array
    const total = data.total ?? logs.length;

    const totalEl = document.getElementById('log-total');
    if (totalEl) totalEl.textContent = `${total.toLocaleString()} entries`;

    if (!logs.length) {
      body.innerHTML = '<p style="padding:1.5rem;color:var(--muted);text-align:center">No activity yet.</p>';
      return;
    }

    const ACTION_BADGE = {
      patrol_start:    ['Patrol start',    'rgba(63,185,80,.12)',   '#3fb950', 'rgba(63,185,80,.25)'],
      patrol_stop:     ['Patrol stop',     'rgba(248,81,73,.1)',    '#f85149', 'rgba(248,81,73,.25)'],
      preset_move:     ['Preset move',     'rgba(0,180,216,.1)',    '#00b4d8', 'rgba(0,180,216,.25)'],
      sync:            ['Sync',            'rgba(210,153,34,.1)',   '#d29922', 'rgba(210,153,34,.25)'],
      error:           ['Error',           'rgba(248,81,73,.08)',   '#f85149', 'rgba(248,81,73,.2)'],
      login:           ['Login',           'rgba(63,185,80,.1)',    '#3fb950', 'rgba(63,185,80,.2)'],
      login_denied:    ['Login denied',    'rgba(248,81,73,.1)',    '#f85149', 'rgba(248,81,73,.25)'],
      logout:          ['Logout',          'rgba(125,133,144,.1)',  '#7d8590', 'rgba(125,133,144,.3)'],
      schedule_change: ['Schedule change', 'rgba(0,180,216,.1)',    '#00b4d8', 'rgba(0,180,216,.25)'],
      config_change:   ['Config change',   'rgba(210,153,34,.1)',   '#d29922', 'rgba(210,153,34,.25)'],
      user_change:     ['User change',     'rgba(210,153,34,.1)',   '#d29922', 'rgba(210,153,34,.25)'],
    };

    const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    body.innerHTML = `<table style="width:100%;border-collapse:collapse;font-size:.8rem">
      <thead style="position:sticky;top:0;background:var(--surface)">
        <tr>
          <th style="padding:.5rem .75rem;text-align:left;color:var(--muted);
                     border-bottom:2px solid var(--border);white-space:nowrap;font-size:.72rem;
                     text-transform:uppercase;letter-spacing:.05em">Timestamp</th>
          <th style="padding:.5rem .75rem;text-align:left;color:var(--muted);
                     border-bottom:2px solid var(--border);font-size:.72rem;
                     text-transform:uppercase;letter-spacing:.05em">Camera</th>
          <th style="padding:.5rem .75rem;text-align:left;color:var(--muted);
                     border-bottom:2px solid var(--border);font-size:.72rem;
                     text-transform:uppercase;letter-spacing:.05em">Action</th>
          <th style="padding:.5rem .75rem;text-align:left;color:var(--muted);
                     border-bottom:2px solid var(--border);font-size:.72rem;
                     text-transform:uppercase;letter-spacing:.05em">Mode</th>
          <th style="padding:.5rem .75rem;text-align:left;color:var(--muted);
                     border-bottom:2px solid var(--border);font-size:.72rem;
                     text-transform:uppercase;letter-spacing:.05em">Detail</th>
          <th style="padding:.5rem .75rem;text-align:center;color:var(--muted);
                     border-bottom:2px solid var(--border);font-size:.72rem;
                     text-transform:uppercase;letter-spacing:.05em">API</th>
        </tr>
      </thead>
      <tbody>
        ${logs.map(l => {
          const [label, bg, col, border] = ACTION_BADGE[l.action] || ['—','transparent','var(--muted)','transparent'];
          const badge = `<span style="display:inline-block;padding:.1rem .45rem;border-radius:3px;
            font-size:.7rem;font-weight:700;background:${bg};color:${col};
            border:1px solid ${border}">${label}</span>`;

          const mode = l.camera_mode && l.camera_mode !== 'unknown'
            ? `<span style="padding:.1rem .35rem;border-radius:3px;font-size:.68rem;
                font-weight:700;text-transform:uppercase;
                background:${l.camera_mode==='patrol'?'rgba(0,180,216,.1)':'rgba(210,153,34,.1)'};
                color:${l.camera_mode==='patrol'?'var(--accent)':'var(--warning)'}">${l.camera_mode}</span>`
            : '<span style="color:var(--muted);font-size:.72rem">—</span>';

          const apiSt = l.api_status
            ? `<span style="font-weight:600;font-size:.78rem;color:${l.api_status<300?'var(--success)':'var(--error)'}">${l.api_status}</span>`
            : '<span style="color:var(--muted);font-size:.72rem">—</span>';

          const source = {daemon:'⚙',manual:'👤',sync:'↺'}[l.triggered_by] || '';

          return `<tr style="border-bottom:1px solid rgba(48,54,61,.4)">
            <td style="padding:.45rem .75rem;color:var(--muted);white-space:nowrap;font-size:.75rem"
                title="${esc(l.created_at)}">${esc(l.created_at)}</td>
            <td style="padding:.45rem .75rem;font-weight:500;max-width:140px;
                overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                title="${esc(l.camera_name)}">${esc(l.camera_name||'—')}</td>
            <td style="padding:.45rem .75rem">${badge}</td>
            <td style="padding:.45rem .75rem">${mode}</td>
            <td style="padding:.45rem .75rem;color:var(--muted);max-width:220px">
              <span title="${esc(l.detail)}"
                    style="display:-webkit-box;-webkit-line-clamp:2;
                           -webkit-box-orient:vertical;overflow:hidden">
                ${source} ${esc(l.detail||'—')}
              </span>
            </td>
            <td style="padding:.45rem .75rem;text-align:center">${apiSt}</td>
            <td style="padding:.45rem .75rem;font-size:.75rem;font-family:monospace;color:var(--muted);
                max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                title="${esc(l.actor||'')}">${esc(l.actor||'daemon')}</td>
          </tr>`;
        }).join('')}
      </tbody>
    </table>
    ${total > 50 ? `<div style="padding:.65rem 1rem;border-top:1px solid var(--border);
        font-size:.78rem;color:var(--muted);text-align:center">
        Showing 50 of ${total.toLocaleString()} entries —
        <a href="log.php" target="_blank" style="color:var(--accent)">view all in full log →</a>
      </div>` : ''}`;
  } catch(e) {
    body.innerHTML = `<p style="padding:1.5rem;color:var(--error)">${e.message}</p>`;
  }
}

document.getElementById('log-modal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});

// ── Daemon health indicator ───────────────────────────────────────────────────

async function refreshDaemonHealth() {
  try {
    const r = await fetch('status.php', { cache: 'no-store' });
    const j = await r.json();

    const dot   = document.getElementById('daemon-dot');
    const label = document.getElementById('daemon-label');
    const wrap  = document.getElementById('daemon-health');
    if (!dot || !label) return;

    const s      = j.status;
    const daemon = j.components?.daemon || {};

    // Dot colour
    dot.className = 'dot dot-' + (s === 'ok' ? 'ok' : s === 'degraded' ? 'degraded' : 'error');

    // Label
    if (daemon.status === 'error') {
      label.textContent = 'Daemon offline';
      wrap.title = daemon.message || 'Daemon not running';
    } else if (daemon.patrolling_now > 0) {
      label.textContent = `Patrolling (${daemon.patrolling_now})`;
      wrap.title = `${daemon.cameras_managed} cameras managed · ${daemon.patrolling_now} patrolling`;
    } else {
      label.textContent = `Daemon OK`;
      wrap.title = `${daemon.cameras_managed} cameras managed · idle`;
    }

    // NTP warning
    if (daemon.ntp_status === 'warning' || daemon.ntp_status === 'error') {
      label.textContent += ' · Clock drift!';
    }
  } catch {
    const dot   = document.getElementById('daemon-dot');
    const label = document.getElementById('daemon-label');
    if (dot)   dot.className   = 'dot dot-unknown';
    if (label) label.textContent = 'Status unknown';
  }
}

// ── Last action per camera ────────────────────────────────────────────────────

function formatTimeAgo(dateStr) {
  if (!dateStr) return '';
  const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
  if (diff < 60)   return `${diff}s ago`;
  if (diff < 3600) return `${Math.floor(diff/60)}m ago`;
  if (diff < 86400)return `${Math.floor(diff/3600)}h ago`;
  return new Date(dateStr).toLocaleDateString();
}

async function loadLastActions() {
  try {
    const data = await api('get_log', { method: 'GET', params: { per_page: 100 } });
    const logs  = data.logs ?? data;  // handle both paginated and legacy format
    // Build map: camera_id → most recent log entry
    const latest = {};
    for (const l of logs) {
      if (!latest[l.camera_id]) latest[l.camera_id] = l;
    }
    for (const [camId, entry] of Object.entries(latest)) {
      const el = document.getElementById('last-action-' + camId);
      if (!el) continue;
      const tagClass = 'action-tag tag-' + (entry.action || 'sync');
      const timeAgo  = formatTimeAgo(entry.created_at);
      el.innerHTML = `
        <span class="${tagClass}">${(entry.action||'').replace('_',' ')}</span>
        <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
              title="${entry.detail || ''}">${entry.detail || '—'}</span>
        <span style="flex-shrink:0;color:var(--muted)">${timeAgo}</span>`;
    }
    // Clear any that had no actions
    document.querySelectorAll('[id^="last-action-"]').forEach(el => {
      if (el.querySelector('.action-tag')) return;
      el.innerHTML = '<span style="color:var(--muted);font-size:.68rem">No actions recorded yet</span>';
    });
  } catch(e) {
    console.warn('Last actions load failed:', e);
  }
}

// ── Boot ──────────────────────────────────────────────────────────────────────

// Load on page open
refreshDaemonHealth();
loadLastActions();

// Refresh health every 30s, last actions every 60s
setInterval(refreshDaemonHealth, PTZ_CONFIG.snapshotRefreshMs);
setInterval(loadLastActions, 60000);


// ── Test patrol ───────────────────────────────────────────────────────────────

const _testTimers = {};   // cameraId → intervalId

async function testPatrol(cameraId, btn) {
  const duration = 30;  // seconds

  btn.disabled = true;
  btn.innerHTML = '<span class="spin">⟳</span>';

  try {
    await api('test_patrol', { camera_id: cameraId, duration_seconds: duration });
    toast(`Test patrol started — stops in ${duration}s`);
    startTestCountdown(cameraId, duration);
  } catch(e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Test`;
  }
}

function startTestCountdown(cameraId, seconds) {
  const timer    = document.getElementById('test-timer-' + cameraId);
  const countdown= document.getElementById('test-countdown-' + cameraId);
  if (!timer || !countdown) return;

  let remaining = seconds;
  timer.classList.add('active');
  countdown.textContent = remaining + 's';

  // Clear any existing timer for this camera
  if (_testTimers[cameraId]) clearInterval(_testTimers[cameraId]);

  _testTimers[cameraId] = setInterval(() => {
    remaining--;
    countdown.textContent = remaining + 's';
    if (remaining <= 0) {
      clearInterval(_testTimers[cameraId]);
      delete _testTimers[cameraId];
      timer.classList.remove('active');
      toast('Test patrol ended');
    }
  }, 1000);
}

// ── Copy schedule ─────────────────────────────────────────────────────────────

let _copySourceId = null;

async function openCopySchedule() {
  _copySourceId = currentCameraId;
  if (!_copySourceId) return;

  // Build list of other PTZ cameras
  const cameras = await api('get_cameras', { method: 'GET', params: {} }).catch(() => []);
  const others  = cameras.filter(c => c.id !== _copySourceId);

  if (!others.length) {
    toast('No other PTZ cameras to copy to', 'error');
    return;
  }

  // Swap modal content to copy picker
  document.getElementById('modal-title').textContent = 'Copy Schedule To';
  document.getElementById('modal-subtitle').textContent =
    'Select cameras to apply this schedule to. Their enabled state will be preserved.';

  document.getElementById('modal-body').innerHTML = `
    <p style="font-size:.85rem;color:var(--muted);margin-bottom:.75rem">
      Select which cameras should receive the same schedule:
    </p>
    <div class="copy-cam-list" id="copy-cam-list">
      ${others.map(c => `
        <label class="copy-cam-item" id="copy-item-${c.id}">
          <input type="checkbox" value="${c.id}" onchange="toggleCopyItem('${c.id}', this.checked)">
          <img class="copy-cam-snapshot"
               src="snapshot.php?camera_id=${c.id}"
               alt="${c.name}" loading="lazy">
          <div>
            <div style="font-weight:600;font-size:.85rem">${c.name}</div>
            <div style="font-size:.73rem;color:var(--muted)">${c.model||'PTZ'}</div>
          </div>
          ${c.schedule_days?.length
            ? '<span style="margin-left:auto;font-size:.7rem;color:var(--muted)">has schedule</span>'
            : '<span style="margin-left:auto;font-size:.7rem;color:var(--warning)">not configured</span>'}
        </label>`
      ).join('')}
    </div>`;

  document.getElementById('btn-save-schedule').textContent = 'Copy Schedule';
  document.getElementById('btn-save-schedule').onclick = confirmCopySchedule;
  document.getElementById('btn-copy-schedule').style.display = 'none';
}

function toggleCopyItem(id, checked) {
  document.getElementById('copy-item-' + id)?.classList.toggle('selected', checked);
}

async function confirmCopySchedule() {
  const selected = [...document.querySelectorAll('#copy-cam-list input:checked')]
    .map(i => i.value);

  if (!selected.length) {
    toast('Select at least one camera', 'error');
    return;
  }

  const btn = document.getElementById('btn-save-schedule');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin">⟳</span> Copying…';

  try {
    const result = await api('copy_schedule', {
      source_camera_id: _copySourceId,
      target_camera_ids: selected,
    });
    toast(`Schedule copied to ${result.copied} camera${result.copied !== 1 ? 's' : ''}`);
    closeModal();
  } catch(e) {
    toast(e.message, 'error');
    btn.disabled = false;
    btn.textContent = 'Copy Schedule';
  }
}


// ── Quick duplicate (card button — skips schedule editor) ────────────────────

async function quickDuplicate(sourceCameraId) {
  // Load other cameras for the picker
  let cameras;
  try {
    cameras = await api('get_cameras', { method: 'GET', params: {} });
  } catch(e) {
    toast('Could not load cameras: ' + e.message, 'error');
    return;
  }

  const others = cameras.filter(c => c.id !== sourceCameraId);
  const source = cameras.find(c => c.id === sourceCameraId);

  if (!others.length) {
    toast('No other PTZ cameras to duplicate to', 'error');
    return;
  }

  // Check source has a schedule
  if (!source?.schedule_days?.length) {
    toast('No schedule configured on this camera — set one up first', 'error');
    return;
  }

  // Reuse the schedule modal as a lightweight picker
  currentCameraId  = sourceCameraId;
  _copySourceId    = sourceCameraId;

  document.getElementById('modal-title').textContent = 'Duplicate Schedule';
  document.getElementById('modal-subtitle').textContent =
    `Copy schedule from "${source?.name || 'this camera'}" to:`;

  document.getElementById('modal-body').innerHTML = `
    <p style="font-size:.82rem;color:var(--muted);margin-bottom:.75rem">
      The enabled state of each target camera is preserved. Days, times, and patrol
      mode are copied exactly.
    </p>
    <div class="copy-cam-list" id="copy-cam-list">
      ${others.map(c => `
        <label class="copy-cam-item" id="copy-item-${c.id}">
          <input type="checkbox" value="${c.id}"
                 onchange="toggleCopyItem('${c.id}', this.checked)">
          <img class="copy-cam-snapshot"
               src="snapshot.php?camera_id=${c.id}"
               alt="${c.name}" loading="lazy">
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:.85rem;
                        overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              ${c.name}
            </div>
            <div style="font-size:.72rem;color:var(--muted)">${c.model || 'PTZ'}</div>
          </div>
          <span style="font-size:.7rem;flex-shrink:0;color:${c.schedule_days?.length ? 'var(--muted)' : 'var(--warning)'}">
            ${c.schedule_days?.length ? 'has schedule' : 'no schedule'}
          </span>
        </label>`
      ).join('')}
    </div>
    <p style="font-size:.75rem;color:var(--muted);margin-top:.6rem">
      Tip: tick multiple cameras to copy to all of them at once.
    </p>`;

  const saveBtn = document.getElementById('btn-save-schedule');
  saveBtn.textContent = 'Duplicate to selected';
  saveBtn.onclick     = confirmCopySchedule;

  const copyBtn = document.getElementById('btn-copy-schedule');
  if (copyBtn) copyBtn.style.display = 'none';

  document.getElementById('schedule-modal').classList.add('open');
}

</script>
</body>
</html>
