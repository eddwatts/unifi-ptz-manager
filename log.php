<?php
/**
 * log.php — full-featured activity log viewer.
 *
 * Features: filter by camera / action / trigger / date range / search,
 * pagination, CSV export, live auto-refresh toggle.
 */

require_once '/etc/ptz/config.php';

if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') {
    require_once __DIR__ . '/auth_check.php';
} else {
    $authUser = ['name' => 'Admin', 'email' => '', 'picture' => ''];
}

// ── CSV export (before any HTML output) ──────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ptz-log-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');

    $db = get_db();

    // Build same filter as the viewer
    $where = []; $params = [];
    if (!empty($_GET['camera_id']))   { $where[] = 'al.camera_id = ?';     $params[] = $_GET['camera_id']; }
    if (!empty($_GET['action_type'])) { $where[] = 'al.action = ?';        $params[] = $_GET['action_type']; }
    if (!empty($_GET['triggered_by'])){ $where[] = 'al.triggered_by = ?';  $params[] = $_GET['triggered_by']; }
    if (!empty($_GET['date_from']))   { $where[] = 'al.created_at >= ?';   $params[] = $_GET['date_from'] . ' 00:00:00'; }
    if (!empty($_GET['date_to']))     { $where[] = 'al.created_at <= ?';   $params[] = $_GET['date_to']   . ' 23:59:59'; }
    if (!empty($_GET['search']))      { $where[] = '(al.camera_name LIKE ? OR al.detail LIKE ?)';
                                        $params[] = '%' . $_GET['search'] . '%';
                                        $params[] = '%' . $_GET['search'] . '%'; }

    $sql = "SELECT al.created_at, COALESCE(al.camera_name, c.name, al.camera_id) AS camera_name,
                   al.action, al.camera_mode, al.triggered_by, al.actor, al.ip_address, al.detail,
                   al.api_status, al.api_response
            FROM action_log al
            LEFT JOIN cameras c ON c.id = al.camera_id
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY al.created_at DESC LIMIT 10000";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'Camera', 'Action', 'Mode', 'Triggered By', 'Actor', 'IP Address', 'Detail', 'API Status', 'API Response']);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['created_at'], $row['camera_name'], $row['action'],
            $row['camera_mode'], $row['triggered_by'],
            $row['actor'], $row['ip_address'], $row['detail'],
            $row['api_status'], $row['api_response'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Load camera list for filter dropdown ──────────────────────────────────────
$cameras = [];
try {
    $cameras = get_db()->query(
        "SELECT id, name FROM cameras WHERE is_ptz = 1 ORDER BY name"
    )->fetchAll();
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PTZ Patrol Manager — Activity Log</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:       #0d1117; --surface: #161b22; --surface-2: #1c2128;
    --border:   #30363d; --accent:  #00b4d8; --success: #3fb950;
    --warning:  #d29922; --error:   #f85149; --text:    #e6edf3;
    --muted:    #7d8590; --radius:  8px;
  }
  body { background:var(--bg); color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
    min-height:100vh; }

  /* ── Topbar ── */
  .topbar { background:var(--surface); border-bottom:1px solid var(--border);
    padding:0 1.5rem; height:56px; display:flex; align-items:center;
    justify-content:space-between; position:sticky; top:0; z-index:50; }
  .topbar-brand { display:flex; align-items:center; gap:.5rem;
    font-weight:700; color:var(--accent); font-size:.95rem; }
  .topbar-right { display:flex; align-items:center; gap:.5rem; }
  .btn-icon { background:transparent; border:1px solid var(--border);
    color:var(--muted); padding:.4rem .65rem; border-radius:6px;
    cursor:pointer; font-size:.8rem; display:flex; align-items:center; gap:.3rem;
    text-decoration:none; transition:color .15s,border-color .15s; }
  .btn-icon:hover { color:var(--text); border-color:#484f58; }
  .btn-icon.active { color:var(--accent); border-color:var(--accent); }

  /* ── Layout ── */
  .main { max-width:1400px; margin:0 auto; padding:1.25rem 1.5rem; }

  /* ── Filter bar ── */
  .filter-bar {
    display:flex; flex-wrap:wrap; gap:.6rem; align-items:flex-end;
    background:var(--surface); border:1px solid var(--border);
    border-radius:var(--radius); padding:1rem 1.1rem; margin-bottom:1.1rem;
  }
  .filter-group { display:flex; flex-direction:column; gap:.3rem; min-width:0; }
  .filter-group label { font-size:.73rem; font-weight:700; color:var(--muted);
    text-transform:uppercase; letter-spacing:.05em; }
  .filter-group select,
  .filter-group input[type="text"],
  .filter-group input[type="date"] {
    background:var(--bg); border:1px solid var(--border); border-radius:6px;
    color:var(--text); padding:.4rem .65rem; font-size:.85rem; outline:none;
    transition:border-color .2s; min-width:130px;
  }
  .filter-group select:focus,
  .filter-group input:focus { border-color:var(--accent); }
  .filter-group input[type="text"] { min-width:180px; }

  .filter-actions { display:flex; gap:.5rem; align-items:flex-end; margin-left:auto; }

  /* ── Stats row ── */
  .stats-row { display:flex; gap:.75rem; margin-bottom:.9rem; flex-wrap:wrap; }
  .stat-chip {
    background:var(--surface); border:1px solid var(--border); border-radius:6px;
    padding:.35rem .75rem; font-size:.78rem; color:var(--muted);
    display:flex; align-items:center; gap:.4rem;
  }
  .stat-chip strong { color:var(--text); font-size:.9rem; }

  /* ── Table ── */
  .log-table-wrap { background:var(--surface); border:1px solid var(--border);
    border-radius:var(--radius); overflow:hidden; }
  table { width:100%; border-collapse:collapse; font-size:.82rem; }

  thead { position:sticky; top:56px; z-index:10; background:var(--surface); }
  thead th {
    padding:.6rem 1rem; text-align:left; color:var(--muted);
    font-size:.73rem; text-transform:uppercase; letter-spacing:.05em;
    border-bottom:2px solid var(--border); white-space:nowrap;
  }
  tbody tr { border-bottom:1px solid rgba(48,54,61,.45); transition:background .1s; }
  tbody tr:hover { background:var(--surface-2); }
  tbody tr:last-child { border-bottom:none; }
  td { padding:.5rem 1rem; vertical-align:top; }

  /* Column widths */
  .col-time    { width:150px; white-space:nowrap; color:var(--muted); font-size:.78rem; }
  .col-camera  { width:170px; font-weight:500; }
  .col-action  { width:130px; }
  .col-mode    { width:80px; }
  .col-by      { width:80px; }
  .col-detail  { min-width:200px; color:var(--muted); line-height:1.4; }
  .col-api     { width:90px; text-align:center; }

  /* Action badges */
  .action-badge {
    display:inline-flex; align-items:center; gap:.3rem;
    padding:.15rem .55rem; border-radius:4px;
    font-size:.73rem; font-weight:700; white-space:nowrap;
  }
  .ab-patrol_start { background:rgba(63,185,80,.12);  color:var(--success);
    border:1px solid rgba(63,185,80,.25); }
  .ab-patrol_stop  { background:rgba(248,81,73,.1);   color:var(--error);
    border:1px solid rgba(248,81,73,.25); }
  .ab-preset_move  { background:rgba(0,180,216,.1);   color:var(--accent);
    border:1px solid rgba(0,180,216,.25); }
  .ab-sync         { background:rgba(210,153,34,.1);  color:var(--warning);
    border:1px solid rgba(210,153,34,.25); }
  .ab-error        { background:rgba(248,81,73,.08);  color:var(--error);
    border:1px solid rgba(248,81,73,.2); }

  /* Mode pill */
  .mode-pill { padding:.1rem .4rem; border-radius:3px; font-size:.7rem;
    font-weight:600; text-transform:uppercase; }
  .mode-patrol { background:rgba(0,180,216,.1); color:var(--accent); }
  .mode-cycle  { background:rgba(210,153,34,.1); color:var(--warning); }
  .mode-unknown{ color:var(--muted); }

  /* Trigger icon */
  .by-daemon { color:var(--accent); }
  .by-manual { color:var(--success); }
  .by-sync   { color:var(--muted); }

  /* API status pill */
  .api-ok  { color:var(--success); font-size:.78rem; font-weight:600; }
  .api-err { color:var(--error);   font-size:.78rem; font-weight:600; }
  .api-nil { color:var(--muted);   font-size:.75rem; }

  /* Detail expand */
  .detail-text { cursor:pointer; }
  .detail-text.truncated { display:-webkit-box; -webkit-line-clamp:2;
    -webkit-box-orient:vertical; overflow:hidden; }
  .api-response-text { margin-top:.3rem; font-family:monospace; font-size:.72rem;
    color:var(--error); background:rgba(248,81,73,.06);
    border:1px solid rgba(248,81,73,.15); border-radius:4px;
    padding:.3rem .5rem; display:none; }
  .api-response-text.show { display:block; }

  /* ── Pagination ── */
  .pagination { display:flex; align-items:center; justify-content:space-between;
    padding:.75rem 1rem; border-top:1px solid var(--border);
    flex-wrap:wrap; gap:.5rem; }
  .pag-info { font-size:.8rem; color:var(--muted); }
  .pag-buttons { display:flex; gap:.35rem; }
  .pag-btn {
    padding:.3rem .65rem; border-radius:5px; border:1px solid var(--border);
    background:transparent; color:var(--muted); cursor:pointer; font-size:.8rem;
    transition:all .15s;
  }
  .pag-btn:hover:not(:disabled) { color:var(--text); border-color:#484f58; }
  .pag-btn.active { background:var(--accent); color:#000; border-color:var(--accent); font-weight:600; }
  .pag-btn:disabled { opacity:.35; cursor:not-allowed; }

  /* ── Empty / loading ── */
  .empty-state { text-align:center; padding:3rem 1rem; color:var(--muted); }
  .empty-state p { font-size:.9rem; margin-top:.4rem; }

  /* ── Auto-refresh badge ── */
  .refresh-dot { width:7px; height:7px; border-radius:50%;
    background:var(--success); flex-shrink:0;
    box-shadow:0 0 5px var(--success); }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
  .refresh-dot.pulsing { animation:pulse 1.5s ease infinite; }

  /* ── Buttons ── */
  .btn { padding:.45rem 1rem; border-radius:6px; border:none;
    font-size:.85rem; font-weight:600; cursor:pointer;
    display:inline-flex; align-items:center; gap:.4rem; transition:opacity .15s; }
  .btn-primary { background:var(--accent); color:#000; }
  .btn-primary:hover { opacity:.88; }
  .btn-ghost  { background:transparent; color:var(--muted); border:1px solid var(--border); }
  .btn-ghost:hover { color:var(--text); }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-brand">
    <a href="index.php" style="color:var(--muted);text-decoration:none;display:flex;align-items:center;gap:.3rem">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6M9 16h6M9 8h6M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/></svg>
    Activity Log
  </div>
  <div class="topbar-right">
    <!-- Live refresh toggle -->
    <button class="btn-icon" id="refresh-toggle" onclick="toggleRefresh()" title="Auto-refresh every 30s">
      <span class="refresh-dot" id="refresh-dot"></span>
      <span id="refresh-label">Auto-refresh</span>
    </button>
    <!-- CSV export — carries current filters -->
    <button class="btn-icon" onclick="exportCSV()" title="Export to CSV">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
      Export CSV
    </button>
    <?php if (!empty($authUser['picture'])): ?>
      <img src="<?= htmlspecialchars($authUser['picture']) ?>" width="24" height="24"
           style="border-radius:50%;border:1px solid var(--border)">
    <?php endif; ?>
    <a href="auth.php?action=logout" class="btn-icon" title="Sign out">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
    </a>
  </div>
</div>

<div class="main">

  <!-- Filter bar -->
  <div class="filter-bar">
    <div class="filter-group">
      <label>Camera</label>
      <select id="f-camera">
        <option value="">All cameras</option>
        <?php foreach ($cameras as $c): ?>
          <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filter-group">
      <label>Action</label>
      <select id="f-action">
        <option value="">All actions</option>
        <option value="patrol_start">Patrol start</option>
        <option value="patrol_stop">Patrol stop</option>
        <option value="preset_move">Preset move</option>
        <option value="sync">Sync</option>
        <option value="error">Error</option>
        <optgroup label="Access">
          <option value="login">Login</option>
          <option value="login_denied">Login denied</option>
          <option value="logout">Logout</option>
        </optgroup>
        <optgroup label="Changes">
          <option value="schedule_change">Schedule change</option>
          <option value="config_change">Config change</option>
          <option value="user_change">User change</option>
        </optgroup>
      </select>
    </div>

    <div class="filter-group">
      <label>Triggered by</label>
      <select id="f-by">
        <option value="">Any</option>
        <option value="daemon">Daemon (scheduled)</option>
        <option value="manual">Manual (dashboard)</option>
        <option value="sync">Sync</option>
      </select>
    </div>

    <div class="filter-group">
      <label>From date</label>
      <input type="date" id="f-from" value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
    </div>

    <div class="filter-group">
      <label>To date</label>
      <input type="date" id="f-to" value="<?= date('Y-m-d') ?>">
    </div>

    <div class="filter-group">
      <label>Search</label>
      <input type="text" id="f-search" placeholder="Camera name or detail…">
    </div>

    <div class="filter-actions">
      <button class="btn btn-ghost" onclick="clearFilters()">Clear</button>
      <button class="btn btn-primary" onclick="loadLogs(1)">Filter</button>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-row" id="stats-row">
    <div class="stat-chip"><strong id="stat-total">—</strong> entries</div>
    <div class="stat-chip" id="stat-starts" style="display:none">
      <span style="color:var(--success)">▶</span> <strong id="stat-starts-n">0</strong> starts
    </div>
    <div class="stat-chip" id="stat-stops" style="display:none">
      <span style="color:var(--error)">■</span> <strong id="stat-stops-n">0</strong> stops
    </div>
    <div class="stat-chip" id="stat-errors" style="display:none">
      <span style="color:var(--error)">⚠</span> <strong id="stat-errors-n">0</strong> errors
    </div>
    <div class="stat-chip" style="margin-left:auto;font-size:.73rem" id="stat-updated"></div>
  </div>

  <!-- Log table -->
  <div class="log-table-wrap">
    <table id="log-table">
      <thead>
        <tr>
          <th class="col-time">Timestamp</th>
          <th class="col-camera">Camera</th>
          <th class="col-action">Action</th>
          <th class="col-mode">Mode</th>
          <th class="col-by">Source</th>
          <th class="col-detail">Detail / API response</th>
          <th class="col-api">API status</th>
          <th style="min-width:140px">Actor</th>
          <th style="width:120px">IP address</th>
        </tr>
      </thead>
      <tbody id="log-body">
        <tr><td colspan="7" class="empty-state">
          <p>Loading…</p>
        </td></tr>
      </tbody>
    </table>

    <div class="pagination" id="pagination" style="display:none"></div>
  </div>

</div><!-- /main -->

<script>
const ACTION_LABELS = {
  patrol_start:    'Patrol start',
  patrol_stop:     'Patrol stop',
  preset_move:     'Preset move',
  sync:            'Sync',
  error:           'Error',
  login:           'Login',
  login_denied:    'Login denied',
  logout:          'Logout',
  schedule_change: 'Schedule change',
  config_change:   'Config change',
  user_change:     'User change',
};

// Colour map for new action types
const ACTION_EXTRA_COLORS = {
  login:           ['rgba(63,185,80,.12)',  '#3fb950', 'rgba(63,185,80,.25)'],
  login_denied:    ['rgba(248,81,73,.1)',   '#f85149', 'rgba(248,81,73,.25)'],
  logout:          ['rgba(125,133,144,.1)', '#7d8590', 'rgba(125,133,144,.3)'],
  schedule_change: ['rgba(0,180,216,.1)',   '#00b4d8', 'rgba(0,180,216,.25)'],
  config_change:   ['rgba(210,153,34,.1)',  '#d29922', 'rgba(210,153,34,.25)'],
  user_change:     ['rgba(210,153,34,.1)',  '#d29922', 'rgba(210,153,34,.25)'],
};

let _currentPage = 1;
let _refreshTimer = null;
let _autoRefresh  = false;

// ── Filter state ──────────────────────────────────────────────────────────────
function getFilters(page = 1) {
  return {
    camera_id:    document.getElementById('f-camera').value,
    action_type:  document.getElementById('f-action').value,
    triggered_by: document.getElementById('f-by').value,
    date_from:    document.getElementById('f-from').value,
    date_to:      document.getElementById('f-to').value,
    search:       document.getElementById('f-search').value.trim(),
    page,
    per_page: 100,
  };
}

function clearFilters() {
  document.getElementById('f-camera').value = '';
  document.getElementById('f-action').value = '';
  document.getElementById('f-by').value     = '';
  document.getElementById('f-search').value = '';
  document.getElementById('f-from').value   = new Date(Date.now() - 7*864e5).toISOString().slice(0,10);
  document.getElementById('f-to').value     = new Date().toISOString().slice(0,10);
  loadLogs(1);
}

// ── Load logs from API ────────────────────────────────────────────────────────
async function loadLogs(page = 1) {
  _currentPage = page;
  const filters = getFilters(page);
  const qs = new URLSearchParams(Object.fromEntries(
    Object.entries(filters).filter(([,v]) => v !== '')
  )).toString();

  try {
    const r = await fetch(`api.php?action=get_log&${qs}`, { cache: 'no-store' });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error);
    renderLogs(j.data);
  } catch(e) {
    document.getElementById('log-body').innerHTML =
      `<tr><td colspan="7" class="empty-state"><p style="color:var(--error)">${e.message}</p></td></tr>`;
  }
}

function renderLogs(data) {
  const logs   = data.logs   || [];
  const total  = data.total  || 0;
  const page   = data.page   || 1;
  const pages  = data.pages  || 1;
  const perPage= data.per_page || 100;

  // Stats
  document.getElementById('stat-total').textContent = total.toLocaleString();
  const starts = logs.filter(l => l.action === 'patrol_start').length;
  const stops  = logs.filter(l => l.action === 'patrol_stop').length;
  const errors = logs.filter(l => l.action === 'error').length;

  const showStat = (id, n) => {
    document.getElementById(id).style.display = n > 0 ? '' : 'none';
    document.getElementById(id + '-n').textContent = n;
  };
  showStat('stat-starts', starts);
  showStat('stat-stops',  stops);
  showStat('stat-errors', errors);
  document.getElementById('stat-updated').textContent =
    'Updated ' + new Date().toLocaleTimeString();

  // Table body
  if (!logs.length) {
    document.getElementById('log-body').innerHTML =
      '<tr><td colspan="7" class="empty-state"><p>No log entries match the current filters.</p></td></tr>';
    document.getElementById('pagination').style.display = 'none';
    return;
  }

  document.getElementById('log-body').innerHTML = logs.map(l => {
    const actionBadge = `<span class="action-badge ab-${l.action}">${ACTION_LABELS[l.action] || l.action}</span>`;

    const modePill = l.camera_mode && l.camera_mode !== 'unknown'
      ? `<span class="mode-pill mode-${l.camera_mode}">${l.camera_mode}</span>` : '';

    const byIcon = {
      daemon: `<span class="by-daemon" title="Scheduled by daemon">⚙ daemon</span>`,
      manual: `<span class="by-manual" title="Triggered manually">👤 manual</span>`,
      sync:   `<span class="by-sync"   title="Sync operation">↺ sync</span>`,
    }[l.triggered_by] || l.triggered_by;

    const apiPill = l.api_status
      ? (l.api_status < 300
          ? `<span class="api-ok">${l.api_status}</span>`
          : `<span class="api-err">${l.api_status}</span>`)
      : `<span class="api-nil">—</span>`;

    const hasResponse = l.api_response && l.action === 'error';
    const detailHtml  = `
      <span class="detail-text truncated" onclick="this.classList.toggle('truncated')">
        ${escHtml(l.detail || '—')}
      </span>
      ${hasResponse
        ? `<div class="api-response-text" id="resp-${l.id}"
               onclick="this.classList.toggle('show')"
               title="Click to expand">${escHtml(l.api_response)}</div>`
        : ''}`;

    const actor = l.actor
      ? `<span style="font-size:.78rem;font-family:monospace" title="${escHtml(l.actor)}">${escHtml(l.actor)}</span>`
      : `<span style="color:var(--muted);font-size:.75rem">daemon</span>`;

    const ipAddr = l.ip_address
      ? `<span style="font-size:.75rem;font-family:monospace;color:var(--muted)">${escHtml(l.ip_address)}</span>`
      : `<span style="color:var(--muted);font-size:.75rem">—</span>`;

    return `<tr>
      <td class="col-time">${l.created_at}</td>
      <td class="col-camera">${escHtml(l.camera_name || '—')}</td>
      <td class="col-action">${actionBadge}</td>
      <td class="col-mode">${modePill}</td>
      <td class="col-by">${byIcon}</td>
      <td class="col-detail">${detailHtml}</td>
      <td class="col-api">${apiPill}</td>
      <td>${actor}</td>
      <td>${ipAddr}</td>
    </tr>`;
  }).join('');

  // Pagination
  const pag = document.getElementById('pagination');
  const start = (page - 1) * perPage + 1;
  const end   = Math.min(page * perPage, total);
  pag.style.display = '';
  pag.innerHTML = `
    <span class="pag-info">Showing ${start.toLocaleString()}–${end.toLocaleString()} of ${total.toLocaleString()} entries</span>
    <div class="pag-buttons">
      ${renderPagButtons(page, pages)}
    </div>`;
}

function renderPagButtons(current, total) {
  const pages = [];
  // Always show first, last, current ± 2
  const show = new Set([1, total, current-2, current-1, current, current+1, current+2]
    .filter(p => p >= 1 && p <= total));

  let prev = 0;
  for (const p of [...show].sort((a,b) => a-b)) {
    if (prev && p - prev > 1) pages.push(`<span style="color:var(--muted);padding:0 .2rem">…</span>`);
    pages.push(`<button class="pag-btn ${p===current?'active':''}" onclick="loadLogs(${p})">${p}</button>`);
    prev = p;
  }

  return `
    <button class="pag-btn" onclick="loadLogs(${current-1})" ${current<=1?'disabled':''}>←</button>
    ${pages.join('')}
    <button class="pag-btn" onclick="loadLogs(${current+1})" ${current>=total?'disabled':''}>→</button>`;
}

function escHtml(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Auto-refresh ──────────────────────────────────────────────────────────────
function toggleRefresh() {
  _autoRefresh = !_autoRefresh;
  const dot   = document.getElementById('refresh-dot');
  const label = document.getElementById('refresh-label');
  const btn   = document.getElementById('refresh-toggle');

  if (_autoRefresh) {
    dot.classList.add('pulsing');
    label.textContent = 'Live';
    btn.classList.add('active');
    _refreshTimer = setInterval(() => loadLogs(_currentPage), 30000);
  } else {
    dot.classList.remove('pulsing');
    label.textContent = 'Auto-refresh';
    btn.classList.remove('active');
    clearInterval(_refreshTimer);
  }
}

// ── CSV export ────────────────────────────────────────────────────────────────
function exportCSV() {
  const filters = getFilters(1);
  const qs = new URLSearchParams(Object.fromEntries(
    Object.entries({...filters, export: 'csv'}).filter(([,v]) => v !== '')
  )).toString();
  window.location = `log.php?${qs}`;
}

// ── Search on Enter ───────────────────────────────────────────────────────────
document.getElementById('f-search').addEventListener('keydown', e => {
  if (e.key === 'Enter') loadLogs(1);
});

// ── Boot ──────────────────────────────────────────────────────────────────────
loadLogs(1);
</script>
</body>
</html>
