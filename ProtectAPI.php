<?php
/**
 * ProtectAPI.php — thin wrapper around the UniFi Protect local Integration API.
 * All HTTP calls are made with curl; self-signed cert verification skipped (local LAN).
 */

require_once '/etc/ptz/config.php';

class ProtectAPI
{
    private string $base;
    private string $token;

    public function __construct()
    {
        $this->base  = API_BASE;
        $this->token = API_TOKEN;
    }

    // ── Public methods ────────────────────────────────────────────────────────

    /** Fetch all cameras, returns raw array from Protect. */
    public function getCameras(): array
    {
        return $this->get('/cameras')['data'] ?? [];
    }

    /** Fetch all configured patrols for a camera. */
    public function getPatrols(string $cameraId): array
    {
        return $this->get("/cameras/{$cameraId}/ptz/patrols")['data'] ?? [];
    }

    /** Fetch all presets for a camera. */
    public function getPresets(string $cameraId): array
    {
        return $this->get("/cameras/{$cameraId}/ptz/presets")['data'] ?? [];
    }

    /** Start a named patrol. */
    public function startPatrol(string $cameraId, string $patrolId): bool
    {
        $this->post("/cameras/{$cameraId}/ptz/patrol/start", ['patrolId' => $patrolId]);
        return true;
    }

    /** Stop the active patrol. */
    public function stopPatrol(string $cameraId): bool
    {
        $this->post("/cameras/{$cameraId}/ptz/patrol/stop");
        return true;
    }

    /**
     * Fetch connectivity state for a single camera.
     *
     * Returns array with at minimum:
     *   'state'      => 'CONNECTED' | 'DISCONNECTED' | 'UNKNOWN'
     *   'isPtz'      => bool
     *   'name'       => string
     *
     * NOTE: The Protect public API does NOT expose PTZ patrol/mode state.
     * 'state' reflects network connectivity only, not whether the camera is
     * currently executing a patrol. This is the closest confirmation available
     * via the integration API.
     */
    public function getCameraState(string $cameraId): array
    {
        try {
            $data = $this->get("/cameras/{$cameraId}");
            return [
                'state'   => $data['state']   ?? 'UNKNOWN',
                'isPtz'   => $data['isPtz']   ?? false,
                'name'    => $data['name']     ?? $cameraId,
                'model'   => $data['type']     ?? null,
                'online'  => ($data['state'] ?? '') === 'CONNECTED',
            ];
        } catch (Throwable) {
            return ['state' => 'UNKNOWN', 'isPtz' => false, 'name' => $cameraId, 'online' => false];
        }
    }

    /**
     * Fetch a live snapshot JPEG for a camera.
     * Returns raw binary string, or null on failure (camera offline etc).
     */
    public function getSnapshot(string $cameraId, bool $highQuality = false): ?string
    {
        $q  = $highQuality ? 'true' : 'false';
        $ch = curl_init("{$this->base}/cameras/{$cameraId}/snapshot?highQuality={$q}");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $this->token,
                'Accept: image/jpeg',
            ],
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$raw) return null;
        return $raw;
    }

    /** Move camera to a preset slot. */
    public function gotoPreset(string $cameraId, int $slot): bool
    {
        $this->post("/cameras/{$cameraId}/ptz/move", [
            'type'    => 'toPreset',
            'payload' => ['slot' => $slot],
        ]);
        return true;
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    /** Last HTTP status code returned by Protect API — read after any call. */
    public int $lastStatus = 0;

    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, $body);
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $ch = curl_init($this->base . $path);

        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $this->token,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,   // self-signed UDM cert
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $this->lastStatus = $code;  // expose for caller logging

        if ($err) {
            throw new RuntimeException("Protect API curl error: {$err}");
        }

        // 204 No Content is a valid success for stop/start
        if ($code === 204 || $raw === '') {
            return [];
        }

        if ($code >= 400) {
            throw new RuntimeException("Protect API HTTP {$code} on {$method} {$path}: {$raw}");
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Protect API invalid JSON: {$raw}");
        }

        return $decoded;
    }
}
