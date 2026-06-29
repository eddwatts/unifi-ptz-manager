<?php
/**
 * users.php — user access management.
 *
 * Lists all authorised users, lets admins add/revoke/re-enable individual accounts.
 * Only users with role='admin' (or ADMIN_EMAIL) can manage access.
 *
 * Access flow summary:
 *  - ADMIN_EMAIL  → always allowed, cannot be revoked via UI, always sees this page
 *  - access_users table, enabled=1 → allowed in, can be revoked
 *  - access_users table, enabled=0 → denied, shown as Revoked in this list
 */

require_once '/etc/ptz/config.php';

if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') {
    require_once __DIR__ . '/auth_check.php';
} else {
    $authUser = ['name' => 'Admin', 'email' => defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '', 'picture' => ''];
}

// Only admins can manage users
$currentEmail = $authUser['email'] ?? '';
$isAdmin      = $currentEmail === (defined('ADMIN_EMAIL') ? strtolower(ADMIN_EMAIL) : '');

if (!$isAdmin) {
    // Check if they're a DB admin
    try {
        $chk = get_db()->prepare("SELECT role FROM access_users WHERE email = ? AND enabled = 1");
        $chk->execute([$currentEmail]);
        $row = $chk->fetch();
        $isAdmin = ($row['role'] ?? '') === 'admin';
    } catch (Throwable) {}
}

if (!$isAdmin) {
    http_response_code(403);
    header('Location: index.php');
    exit;
}

// ── Handle AJAX actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    try {
        $db = get_db();

        // Ensure system placeholder row exists for audit logs
        $db->exec("INSERT IGNORE INTO cameras (id, name, is_ptz, enabled)
                   VALUES ('system', 'System', 0, 0)");

        /** Write a user_change audit entry. */
        $logChange = function(string $detail) use ($db, $currentEmail): void {
            $ip = !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
                ? filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)
                : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $db->prepare(
                "INSERT INTO action_log
                    (camera_id, camera_name, action, detail, triggered_by, actor, ip_address)
                 VALUES ('system', 'System', 'user_change', ?, 'manual', ?, ?)"
            )->execute([$detail, $currentEmail, $ip ?: 'unknown']);
        };

        switch ($action) {

            case 'add_user':
                $email = strtolower(trim($input['email'] ?? ''));
                $role  = in_array($input['role'] ?? 'viewer', ['admin','viewer']) ? $input['role'] : 'viewer';
                $notes = substr(trim($input['notes'] ?? ''), 0, 255);

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Invalid email address.');
                }
                if ($email === strtolower(ADMIN_EMAIL ?? '')) {
                    throw new InvalidArgumentException('Admin email is always authorised — no need to add it here.');
                }

                $db->prepare(
                    "INSERT INTO access_users (email, role, notes, added_by, enabled)
                     VALUES (?, ?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE
                         role    = VALUES(role),
                         notes   = VALUES(notes),
                         enabled = 1,
                         added_by = VALUES(added_by)"
                )->execute([$email, $role, $notes ?: null, $currentEmail]);

                    $logChange("Access granted to {$email} (role: {$role})" . ($notes ? " — {$notes}" : ''));
                echo json_encode(['ok' => true, 'message' => "Access granted to {$email}"]);
                break;

            case 'set_enabled':
                $email   = strtolower(trim($input['email'] ?? ''));
                $enabled = (int)(bool)($input['enabled'] ?? false);

                if ($email === strtolower(ADMIN_EMAIL ?? '')) {
                    throw new InvalidArgumentException('Cannot revoke the admin account.');
                }
                if ($email === $currentEmail && !$enabled) {
                    throw new InvalidArgumentException('Cannot revoke your own access.');
                }

                $db->prepare(
                    "UPDATE access_users SET enabled = ? WHERE email = ?"
                )->execute([$enabled, $email]);

                $msg = $enabled ? "Access restored for {$email}" : "Access revoked for {$email}";
                $logChange($msg);
                echo json_encode(['ok' => true, 'message' => $msg]);
                break;

            case 'set_role':
                $email = strtolower(trim($input['email'] ?? ''));
                $role  = in_array($input['role'] ?? '', ['admin','viewer']) ? $input['role'] : null;
                if (!$role) throw new InvalidArgumentException('Invalid role.');
                if ($email === $currentEmail) throw new InvalidArgumentException('Cannot change your own role.');

                $db->prepare("UPDATE access_users SET role = ? WHERE email = ?")
                   ->execute([$role, $email]);

                $logChange("Role changed for {$email} to {$role}");
                echo json_encode(['ok' => true, 'message' => "Role updated for {$email}"]);
                break;

            case 'delete_user':
                $email = strtolower(trim($input['email'] ?? ''));
                if ($email === strtolower(ADMIN_EMAIL ?? '')) {
                    throw new InvalidArgumentException('Cannot delete the admin account.');
                }
                if ($email === $currentEmail) {
                    throw new InvalidArgumentException('Cannot delete your own account.');
                }

                $db->prepare("DELETE FROM access_users WHERE email = ?")->execute([$email]);
                $logChange("User removed from access list: {$email}");
                echo json_encode(['ok' => true, 'message' => "User {$email} removed"]);
                break;

            case 'list_users':
                $users = $db->query(
                    "SELECT email, name, role, enabled, notes, added_by,
                            last_login, login_count, created_at
                     FROM access_users
                     ORDER BY enabled DESC, role ASC, email ASC"
                )->fetchAll();

                echo json_encode(['ok' => true, 'data' => $users]);
                break;

            default:
                throw new InvalidArgumentException("Unknown action: {$action}");
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Load users for initial render ─────────────────────────────────────────────
$users = [];
try {
    $users = get_db()->query(
        "SELECT email, name, role, enabled, notes, added_by,
                last_login, login_count, created_at
         FROM access_users
         ORDER BY enabled DESC, role ASC, email ASC"
    )->fetchAll();
} catch (Throwable) {}

$adminEmail   = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PTZ Patrol Manager — User Access</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:#0d1117; --surface:#161b22; --surface-2:#1c2128;
    --border:#30363d; --accent:#00b4d8; --success:#3fb950;
    --warning:#d29922; --error:#f85149; --text:#e6edf3; --muted:#7d8590;
    --radius:8px;
  }
  body { background:var(--bg); color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; min-height:100vh; }

  .topbar { background:var(--surface); border-bottom:1px solid var(--border);
    padding:0 1.5rem; height:56px; display:flex; align-items:center;
    justify-content:space-between; position:sticky; top:0; z-index:50; }
  .topbar-brand { display:flex; align-items:center; gap:.5rem;
    font-weight:700; color:var(--accent); font-size:.95rem; }
  .btn-icon { background:transparent; border:1px solid var(--border); color:var(--muted);
    padding:.4rem .65rem; border-radius:6px; cursor:pointer; font-size:.8rem;
    display:flex; align-items:center; gap:.3rem; text-decoration:none;
    transition:color .15s,border-color .15s; }
  .btn-icon:hover { color:var(--text); border-color:#484f58; }

  .main { max-width:900px; margin:0 auto; padding:1.5rem; }
  .page-header { margin-bottom:1.5rem; }
  .page-header h1 { font-size:1.25rem; font-weight:700; }
  .page-header p  { color:var(--muted); font-size:.875rem; margin-top:.3rem; line-height:1.5; }

  /* Info boxes */
  .info-box { background:var(--surface); border:1px solid var(--border);
    border-radius:var(--radius); padding:.9rem 1.1rem; margin-bottom:1rem;
    font-size:.83rem; line-height:1.55; color:var(--muted); }
  .info-box strong { color:var(--text); }
  .info-box.warning { border-color:rgba(210,153,34,.35); background:rgba(210,153,34,.05); }
  .info-box.success { border-color:rgba(63,185,80,.35); background:rgba(63,185,80,.05); }

  /* Add user form */
  .add-form { background:var(--surface); border:1px solid var(--border);
    border-radius:var(--radius); padding:1.25rem; margin-bottom:1.5rem; }
  .add-form h2 { font-size:.95rem; font-weight:600; margin-bottom:1rem; }
  .form-row { display:flex; gap:.65rem; flex-wrap:wrap; align-items:flex-end; }
  .form-group { display:flex; flex-direction:column; gap:.3rem; }
  .form-group label { font-size:.73rem; font-weight:700; color:var(--muted);
    text-transform:uppercase; letter-spacing:.05em; }
  .form-group input, .form-group select, .form-group textarea {
    background:var(--bg); border:1px solid var(--border); border-radius:6px;
    color:var(--text); padding:.5rem .7rem; font-size:.875rem; outline:none;
    transition:border-color .2s;
  }
  .form-group input:focus, .form-group select:focus { border-color:var(--accent); }
  .form-group input[type="email"] { min-width:240px; }

  /* User table */
  .user-table-wrap { background:var(--surface); border:1px solid var(--border);
    border-radius:var(--radius); overflow:hidden; }
  table { width:100%; border-collapse:collapse; font-size:.83rem; }
  thead th { padding:.6rem 1rem; text-align:left; color:var(--muted);
    font-size:.72rem; text-transform:uppercase; letter-spacing:.05em;
    border-bottom:2px solid var(--border); white-space:nowrap; }
  tbody tr { border-bottom:1px solid rgba(48,54,61,.45); transition:background .1s; }
  tbody tr:hover { background:var(--surface-2); }
  tbody tr:last-child { border-bottom:none; }
  tbody tr.revoked { opacity:.6; }
  td { padding:.55rem 1rem; vertical-align:middle; }

  .role-badge { padding:.15rem .5rem; border-radius:4px; font-size:.7rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.04em; }
  .role-admin  { background:rgba(0,180,216,.12); color:var(--accent);
    border:1px solid rgba(0,180,216,.3); }
  .role-viewer { background:rgba(125,133,144,.1); color:var(--muted);
    border:1px solid var(--border); }
  .role-system { background:rgba(63,185,80,.12); color:var(--success);
    border:1px solid rgba(63,185,80,.3); }

  .status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; flex-shrink:0; }
  .dot-active  { background:var(--success); box-shadow:0 0 5px var(--success); }
  .dot-revoked { background:var(--error); }
  .dot-system  { background:var(--accent); }

  .action-btns { display:flex; gap:.35rem; flex-wrap:wrap; }
  .btn-sm { padding:.25rem .65rem; border-radius:5px; border:1px solid var(--border);
    background:transparent; color:var(--muted); cursor:pointer; font-size:.75rem;
    transition:all .15s; white-space:nowrap; }
  .btn-sm:hover { color:var(--text); border-color:#484f58; }
  .btn-sm.danger:hover { color:var(--error); border-color:var(--error); }
  .btn-sm.primary { color:var(--accent); border-color:rgba(0,180,216,.4); }
  .btn-sm.primary:hover { background:rgba(0,180,216,.08); }

  .btn { padding:.5rem 1rem; border-radius:6px; border:none; font-size:.875rem;
    font-weight:600; cursor:pointer; transition:opacity .15s; }
  .btn-primary { background:var(--accent); color:#000; }
  .btn-primary:hover { opacity:.88; }

  /* Toast */
  #toast { position:fixed; bottom:1.5rem; left:50%; transform:translateX(-50%) translateY(4rem);
    background:var(--surface-2); border:1px solid var(--border); border-radius:8px;
    padding:.65rem 1.25rem; font-size:.875rem; z-index:999; transition:transform .3s;
    white-space:nowrap; box-shadow:0 4px 20px rgba(0,0,0,.4); }
  #toast.show { transform:translateX(-50%) translateY(0); }
  #toast.success { border-color:var(--success); color:var(--success); }
  #toast.error   { border-color:var(--error);   color:var(--error); }

  .empty-state { padding:2.5rem; text-align:center; color:var(--muted); font-size:.875rem; }

  @media (max-width: 600px) {
    .form-row { flex-direction:column; }
    .form-group input[type="email"] { min-width:0; width:100%; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-brand">
    <a href="index.php" style="color:var(--muted);text-decoration:none;display:flex;align-items:center;gap:.3rem">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    User Access
  </div>
  <div style="display:flex;align-items:center;gap:.5rem">
    <?php if (!empty($authUser['picture'])): ?>
      <img src="<?= htmlspecialchars($authUser['picture']) ?>" width="24" height="24"
           style="border-radius:50%;border:1px solid var(--border)">
    <?php endif; ?>
    <span style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($authUser['name'] ?? '') ?></span>
    <a href="auth.php?action=logout" class="btn-icon">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
    </a>
  </div>
</div>

<div class="main">

  <div class="page-header">
    <h1>User Access Management</h1>
    <p>Control who can log in via Google SSO. Access is granted per person — only accounts listed here can log in.</p>
  </div>

  <!-- Admin account info -->
  <?php if ($adminEmail): ?>
  <div class="info-box success">
    <strong>Admin account:</strong> <?= htmlspecialchars($adminEmail) ?> —
    always has access, cannot be revoked. Change in
    <a href="setup.php" style="color:var(--accent)">Settings → Google SSO</a>.
  </div>
  <?php endif; ?>

  <?php if (!$adminEmail && empty($users)): ?>
  <div class="info-box warning">
    <strong>⚠ No access configured.</strong>
    Add an admin email in <a href="setup.php" style="color:var(--accent)">Settings → Google SSO</a>
    or add users below. Without this, nobody can log in.
  </div>
  <?php endif; ?>

  <!-- Add user -->
  <div class="add-form">
    <h2>Grant access to a user</h2>
    <div class="form-row">
      <div class="form-group" style="flex:1">
        <label>Google email address</label>
        <input type="email" id="new-email" placeholder="user@example.com" autocomplete="off">
      </div>
      <div class="form-group">
        <label>Role</label>
        <select id="new-role">
          <option value="viewer">Viewer — can see dashboard, cannot change settings</option>
          <option value="admin">Admin — full access including Settings and this page</option>
        </select>
      </div>
      <div class="form-group" style="flex:1">
        <label>Notes (optional)</label>
        <input type="text" id="new-notes" placeholder="e.g. Site manager, temp access until June">
      </div>
      <div class="form-group">
        <label>&nbsp;</label>
        <button class="btn btn-primary" onclick="addUser()">Grant access</button>
      </div>
    </div>
  </div>

  <!-- User table -->
  <div class="user-table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:24px"></th>
          <th>Email</th>
          <th>Name</th>
          <th>Role</th>
          <th>Last login</th>
          <th>Logins</th>
          <th>Added by</th>
          <th>Notes</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="user-tbody">
        <?php if (empty($users)): ?>
          <tr><td colspan="9" class="empty-state">No individual users added yet.
            No users added yet. Add accounts above to grant access.</td></tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <?php
              $isRevoked    = !(int)$u['enabled'];
              $isCurrentUser= strtolower($u['email']) === strtolower($currentEmail);
              $isAdminUser  = strtolower($u['email']) === strtolower($adminEmail);
            ?>
            <tr class="<?= $isRevoked ? 'revoked' : '' ?>" id="row-<?= md5($u['email']) ?>">
              <td>
                <span class="status-dot <?= $isAdminUser ? 'dot-system' : ($isRevoked ? 'dot-revoked' : 'dot-active') ?>"></span>
              </td>
              <td style="font-weight:500;font-family:monospace;font-size:.8rem">
                <?= htmlspecialchars($u['email']) ?>
                <?php if ($isCurrentUser): ?>
                  <span style="font-size:.68rem;color:var(--accent);margin-left:.3rem">(you)</span>
                <?php endif; ?>
              </td>
              <td style="color:var(--muted)"><?= htmlspecialchars($u['name'] ?? '—') ?></td>
              <td>
                <select class="btn-sm" onchange="setRole('<?= htmlspecialchars($u['email']) ?>', this.value)"
                        <?= $isCurrentUser || $isAdminUser ? 'disabled' : '' ?>>
                  <option value="viewer" <?= $u['role'] === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                  <option value="admin"  <?= $u['role'] === 'admin'  ? 'selected' : '' ?>>Admin</option>
                </select>
              </td>
              <td style="color:var(--muted);font-size:.78rem;white-space:nowrap">
                <?= $u['last_login'] ? htmlspecialchars(date('j M Y H:i', strtotime($u['last_login']))) : 'Never' ?>
              </td>
              <td style="text-align:center;color:var(--muted)"><?= (int)$u['login_count'] ?></td>
              <td style="color:var(--muted);font-size:.78rem">
                <?= htmlspecialchars($u['added_by'] ?? '—') ?>
              </td>
              <td style="color:var(--muted);font-size:.78rem;max-width:150px;overflow:hidden;
                          text-overflow:ellipsis;white-space:nowrap"
                  title="<?= htmlspecialchars($u['notes'] ?? '') ?>">
                <?= htmlspecialchars($u['notes'] ?? '—') ?>
              </td>
              <td>
                <div class="action-btns">
                  <?php if (!$isAdminUser && !$isCurrentUser): ?>
                    <?php if ($isRevoked): ?>
                      <button class="btn-sm primary" onclick="setEnabled('<?= htmlspecialchars($u['email']) ?>', true)">Restore</button>
                    <?php else: ?>
                      <button class="btn-sm danger" onclick="setEnabled('<?= htmlspecialchars($u['email']) ?>', false)">Revoke</button>
                    <?php endif; ?>
                    <button class="btn-sm danger" onclick="deleteUser('<?= htmlspecialchars($u['email']) ?>')">Remove</button>
                  <?php else: ?>
                    <span style="font-size:.72rem;color:var(--muted)">protected</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<div id="toast"></div>

<script>
function toast(msg, type = 'success') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'show ' + type;
  clearTimeout(el._t);
  el._t = setTimeout(() => el.className = '', 3500);
}

async function apiCall(action, data = {}) {
  const r = await fetch('users.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, ...data }),
  });
  const j = await r.json();
  if (!j.ok) throw new Error(j.error);
  return j;
}

async function addUser() {
  const email = document.getElementById('new-email').value.trim();
  const role  = document.getElementById('new-role').value;
  const notes = document.getElementById('new-notes').value.trim();

  if (!email) { toast('Enter an email address', 'error'); return; }

  try {
    const r = await apiCall('add_user', { email, role, notes });
    toast(r.message);
    document.getElementById('new-email').value = '';
    document.getElementById('new-notes').value = '';
    location.reload();   // simplest — table rebuild
  } catch(e) {
    toast(e.message, 'error');
  }
}

async function setEnabled(email, enabled) {
  const verb = enabled ? 'Restore' : 'Revoke';
  if (!enabled && !confirm(`Revoke access for ${email}?\n\nThey will be denied on their next login attempt.`)) return;
  try {
    const r = await apiCall('set_enabled', { email, enabled });
    toast(r.message);
    location.reload();
  } catch(e) {
    toast(e.message, 'error');
  }
}

async function setRole(email, role) {
  try {
    const r = await apiCall('set_role', { email, role });
    toast(r.message);
  } catch(e) {
    toast(e.message, 'error');
    location.reload();   // revert select on failure
  }
}

async function deleteUser(email) {
  if (!confirm(`Permanently remove ${email} from the access list?\n\nThey can be re-added later.`)) return;
  try {
    const r = await apiCall('delete_user', { email });
    toast(r.message);
    const row = document.getElementById('row-' + btoa(email).replace(/=/g,''));
    // Simple fallback — just reload
    location.reload();
  } catch(e) {
    toast(e.message, 'error');
  }
}

// Enter to submit add form
document.getElementById('new-email').addEventListener('keydown', e => {
  if (e.key === 'Enter') addUser();
});
</script>
</body>
</html>
