<?php
/**
 * login.php — login page shown to unauthenticated users.
 * Checks for an auth_error flash message from auth.php.
 */

if (session_status() === PHP_SESSION_NONE) { require_once __DIR__ . '/session.php'; }

// Already logged in → go home
if (!empty($_SESSION['auth']['email'])) {
    header('Location: index.php');
    exit;
}

// Config may not exist yet (setup wizard flow)
$configPath = '/etc/ptz/config.php';
$appName    = 'PTZ Patrol Manager';
if (file_exists($configPath)) {
    @include_once $configPath;
}

$googleConfigured = defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '';
$rawError = $_SESSION['auth_error'] ?? null;
$reason   = $_GET['reason'] ?? null;
unset($_SESSION['auth_error']);

// Decode structured denial message from auth.php
$error       = null;
$deniedEmail = null;
$deniedCode  = null;
if ($rawError && str_starts_with($rawError, 'denied:')) {
    $parts       = explode(':', $rawError, 3);
    $deniedEmail = $parts[1] ?? '';
    $deniedCode  = $parts[2] ?? 'not_found';
} elseif ($rawError) {
    $error = $rawError;
}

$deniedMessages = [
    'revoked'   => 'Your access to this system has been revoked. Contact the system administrator.',
    'not_found' => 'Your account has not been granted access to this system. Contact the system administrator to request access.',
];

// Build return URL to pass through to OAuth flow
$return = $_GET['return'] ?? 'index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($appName) ?> — Sign in</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:       #0d1117;
    --surface:  #161b22;
    --border:   #30363d;
    --accent:   #00b4d8;
    --error:    #f85149;
    --warning:  #d29922;
    --text:     #e6edf3;
    --muted:    #7d8590;
    --radius:   10px;
  }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
  }

  .login-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 2.5rem 2rem;
    width: 100%;
    max-width: 380px;
    text-align: center;
  }

  .brand-icon {
    width: 52px; height: 52px;
    background: rgba(0,180,216,.1);
    border: 1px solid rgba(0,180,216,.25);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.25rem;
    color: var(--accent);
  }

  h1 {
    font-size: 1.25rem;
    font-weight: 700;
    letter-spacing: -.02em;
    margin-bottom: .3rem;
  }

  .subtitle {
    font-size: .85rem;
    color: var(--muted);
    margin-bottom: 2rem;
    line-height: 1.4;
  }

  /* Alert */
  .alert {
    background: rgba(248,81,73,.08);
    border: 1px solid rgba(248,81,73,.3);
    border-radius: 7px;
    padding: .75rem 1rem;
    font-size: .85rem;
    color: var(--error);
    margin-bottom: 1.25rem;
    text-align: left;
    line-height: 1.5;
  }
  .alert.warning {
    background: rgba(210,153,34,.08);
    border-color: rgba(210,153,34,.3);
    color: var(--warning);
  }

  /* Google sign-in button — follows Google brand guidelines */
  .btn-google {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .75rem;
    width: 100%;
    padding: .7rem 1rem;
    background: #fff;
    border: 1px solid #dadce0;
    border-radius: 6px;
    color: #3c4043;
    font-size: .9rem;
    font-weight: 500;
    font-family: 'Google Sans', Roboto, sans-serif;
    cursor: pointer;
    text-decoration: none;
    transition: background .15s, box-shadow .15s;
  }
  .btn-google:hover {
    background: #f8f9fa;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
  }
  .btn-google svg { flex-shrink: 0; }

  /* Not-configured warning */
  .setup-notice {
    background: rgba(210,153,34,.08);
    border: 1px solid rgba(210,153,34,.25);
    border-radius: 7px;
    padding: .85rem 1rem;
    font-size: .83rem;
    color: var(--warning);
    line-height: 1.5;
  }
  .setup-notice a { color: var(--accent); }

  .divider {
    border: none; border-top: 1px solid var(--border);
    margin: 1.5rem 0;
  }

  .footer-note {
    font-size: .75rem;
    color: var(--muted);
    margin-top: 1.5rem;
    line-height: 1.5;
  }
</style>
</head>
<body>
<div class="login-card">

  <div class="brand-icon">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <path d="M15.5 6.5a4 4 0 0 1 0 11M8.5 6.5a4 4 0 0 0 0 11"/>
      <circle cx="12" cy="12" r="3"/>
    </svg>
  </div>

  <h1>PTZ Patrol Manager</h1>
  <p class="subtitle">Sign in to manage camera patrol schedules</p>

  <?php if ($reason === 'timeout'): ?>
    <div class="alert warning">Your session expired after 8 hours. Please sign in again.</div>
  <?php endif; ?>

  <?php if ($deniedEmail): ?>
    <div class="alert">
      <strong>Access denied</strong><br>
      <span style="word-break:break-all"><?= htmlspecialchars($deniedEmail) ?></span>
      is not authorised to access this system.<br>
      <?= htmlspecialchars($deniedMessages[$deniedCode] ?? 'Contact the system administrator.') ?>
    </div>
  <?php elseif ($error): ?>
    <div class="alert"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($googleConfigured): ?>
    <a class="btn-google" href="auth.php?action=login&return=<?= urlencode($return) ?>">
      <!-- Google 'G' logo SVG — official colours -->
      <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
        <path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/>
        <path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z" fill="#34A853"/>
        <path d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/>
        <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z" fill="#EA4335"/>
      </svg>
      Sign in with Google
    </a>

  <?php else: ?>
    <div class="setup-notice">
      Google SSO is not yet configured.<br>
      <a href="setup.php">Complete setup</a> to enable sign-in.
    </div>
  <?php endif; ?>

  <p class="footer-note">
    Access is restricted to authorised accounts.<br>
    Contact your administrator if you need access.
  </p>

</div>
</body>
</html>
