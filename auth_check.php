<?php
/**
 * auth_check.php — include at the top of every protected page.
 *
 * Usage (one line, before any output):
 *   require_once __DIR__ . '/auth_check.php';
 *
 * Redirects to login.php if not authenticated.
 * Exposes $authUser array with email, name, picture.
 */

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session.php';
}

// Redirect to login if no valid session
if (empty($_SESSION['auth']['email'])) {
    $return = urlencode($_SERVER['PHP_SELF'] ?? 'index.php');
    header('Location: login.php?return=' . $return);
    exit;
}

// Optional: session timeout (8 hours — covers a school working day)
$SESSION_TTL = 8 * 3600;
if (isset($_SESSION['auth']['at']) && (time() - $_SESSION['auth']['at']) > $SESSION_TTL) {
    session_unset();
    session_destroy();
    header('Location: login.php?reason=timeout');
    exit;
}

// Expose for use in templates
$authUser = $_SESSION['auth'];
