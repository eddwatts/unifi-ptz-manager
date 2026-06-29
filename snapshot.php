<?php
/**
 * snapshot.php — proxy/cache camera snapshots from Protect API.
 *
 * Usage: snapshot.php?camera_id=abc123&refresh=1
 *
 * Caches to /snapshots/{camera_id}.jpg for 30s to avoid hammering the NVR.
 * The refresh=1 param forces a new fetch regardless of cache age.
 *
 * Returns JPEG directly — use as <img src="snapshot.php?camera_id=xxx">
 */

require_once '/etc/ptz/config.php';
require_once __DIR__ . '/ProtectAPI.php';

// Snapshot images are only for authenticated users.
// The session cookie (SameSite=Strict) is sent with same-origin <img> requests,
// so this is transparent to the UI — no extra login step.
if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') {
    require_once __DIR__ . '/auth_check.php';
}

$cameraId = $_GET['camera_id'] ?? '';
$refresh  = isset($_GET['refresh']);

if (!preg_match('/^[a-zA-Z0-9]{24}$/', $cameraId)) {
    http_response_code(400);
    exit('Invalid camera ID');
}

$cacheDir  = __DIR__ . '/snapshots';
$cachePath = "{$cacheDir}/{$cameraId}.jpg";
$cacheTtl  = 30; // seconds

// Serve from cache if fresh enough
if (!$refresh && file_exists($cachePath) && (time() - filemtime($cachePath)) < $cacheTtl) {
    header('Content-Type: image/jpeg');
    header('X-Cache: HIT');
    readfile($cachePath);
    exit;
}

// Fetch fresh snapshot from Protect
try {
    $api  = new ProtectAPI();
    $jpeg = $api->getSnapshot($cameraId);

    if (!$jpeg) {
        // Camera offline — serve stale cache if we have it, else placeholder
        if (file_exists($cachePath)) {
            header('Content-Type: image/jpeg');
            header('X-Cache: STALE');
            readfile($cachePath);
        } else {
            serve_placeholder();
        }
        exit;
    }

    // Write to cache
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0750, true);
    file_put_contents($cachePath, $jpeg);

    header('Content-Type: image/jpeg');
    header('X-Cache: MISS');
    echo $jpeg;

} catch (Throwable $e) {
    // On any error, serve stale cache or placeholder — never break the UI
    if (file_exists($cachePath)) {
        header('Content-Type: image/jpeg');
        header('X-Cache: ERROR-STALE');
        readfile($cachePath);
    } else {
        serve_placeholder();
    }
}

/**
 * Return a minimal 1×1 grey JPEG so the <img> doesn't show a broken icon.
 * Generated once with: php -r "echo base64_encode(imagejpeg(...));"
 */
function serve_placeholder(): void
{
    header('Content-Type: image/jpeg');
    // 320x180 grey JPEG placeholder (base64-encoded minimal image)
    $img = imagecreatetruecolor(320, 180);
    imagefilledrectangle($img, 0, 0, 319, 179, imagecolorallocate($img, 40, 40, 40));
    $white = imagecolorallocate($img, 120, 120, 120);
    imagestring($img, 3, 110, 80, 'No snapshot', $white);
    imagejpeg($img, null, 60);
    imagedestroy($img);
}
