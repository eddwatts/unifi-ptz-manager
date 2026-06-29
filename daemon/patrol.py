#!/usr/bin/env python3
"""
daemon/patrol.py — PTZ patrol scheduler daemon.

Responsibilities:
  - Camera sync from Protect every SYNC_INTERVAL_HOURS
  - Patrol start/stop/preset-cycle based on DB schedules
  - NTP clock check every NTP_CHECK_INTERVAL_HOURS (default 3)
  - Heartbeat JSON written every poll cycle for status.php
  - Camera offline state-change detection and logging
  - Action logging to DB for dashboard display

Heartbeat file: /tmp/ptz_daemon_heartbeat.json
Log file:       /var/log/unifi_ptz_daemon.log
"""

import re
import ssl
import json
import time
import socket
import struct
import logging
import urllib.request
import urllib.error
import mysql.connector
from datetime import datetime, timezone
from pathlib import Path

# ── Parse config.php ──────────────────────────────────────────────────────────

# Both files live outside the web root in /etc/ptz/
CONFIG_PATH  = Path("/etc/ptz/config.php")
SECRETS_PATH = Path("/etc/ptz/secrets.php")

def load_config() -> dict:
    """
    Parse PHP define() constants from config.php and /etc/ptz/secrets.php.
    Secrets file takes precedence on key collisions (it's loaded second).
    Falls back gracefully if secrets file is missing (will error later on DB connect).
    """
    cfg = {}
    for path in [CONFIG_PATH, SECRETS_PATH]:
        if not path.exists():
            continue
        text = path.read_text()
        for m in re.finditer(r"define\('(\w+)',\s*'([^']*)'\)", text):
            cfg[m.group(1)] = m.group(2)
        for m in re.finditer(r"define\('(\w+)',\s*(\d+)\)", text):
            cfg[m.group(1)] = int(m.group(2))
    return cfg

CFG = load_config()

SYNC_INTERVAL_HOURS = CFG.get("SYNC_INTERVAL_HOURS", 6)
NTP_CHECK_INTERVAL_HOURS = 3
POLL_INTERVAL = 60          # seconds between schedule evaluations
HEARTBEAT_PATH = Path("/tmp/ptz_daemon_heartbeat.json")
DAEMON_START = time.monotonic()

# ── Logging ───────────────────────────────────────────────────────────────────

logging.basicConfig(
    level   = logging.INFO,
    format  = "%(asctime)s [%(levelname)s] %(message)s",
    datefmt = "%Y-%m-%d %H:%M:%S",
    handlers = [
        logging.StreamHandler(),
        logging.FileHandler("/var/log/unifi_ptz_daemon.log"),
    ]
)
log = logging.getLogger("ptz-daemon")

# ── DB ────────────────────────────────────────────────────────────────────────

def get_db():
    return mysql.connector.connect(
        host       = CFG["DB_HOST"],
        port       = CFG.get("DB_PORT", 3306),
        database   = CFG["DB_NAME"],
        user       = CFG["DB_USER"],
        password   = CFG["DB_PASS"],
        autocommit = True,
        connection_timeout = 10,
    )

def log_action(
    db,
    camera_id:    str,
    action:       str,
    detail:       str = "",
    by:           str = "daemon",
    mode:         str = "unknown",
    api_status:   int | None = None,
    api_response: str = "",
):
    """
    Write an enriched entry to action_log.
    camera_name is denormalised so logs survive camera deletion.
    api_status is the HTTP code returned by Protect (200, 204, 4xx etc).
    """
    try:
        cur = db.cursor(dictionary=True)
        cur.execute("SELECT name FROM cameras WHERE id = %s", (camera_id,))
        row = cur.fetchone()
        cam_name = row["name"] if row else camera_id

        cur.execute(
            """INSERT INTO action_log
                (camera_id, camera_name, action, camera_mode, detail,
                 api_status, api_response, triggered_by)
               VALUES (%s, %s, %s, %s, %s, %s, %s, %s)""",
            (camera_id, cam_name, action, mode, detail,
             api_status, api_response[:500] if api_response else None, by)
        )
    except Exception as e:
        log.error("log_action failed: %s", e)

# ── Protect API ───────────────────────────────────────────────────────────────

CTX = ssl.create_default_context()
CTX.check_hostname = False
CTX.verify_mode    = ssl.CERT_NONE
BASE_URL = f"https://{CFG['NVR_IP']}/proxy/protect/integration/v1"

def _headers() -> dict:
    return {
        "Content-Type": "application/json",
        "X-API-Key":    CFG["API_TOKEN"],
    }

def api_get(path: str) -> dict:
    req = urllib.request.Request(BASE_URL + path, headers=_headers())
    with urllib.request.urlopen(req, context=CTX, timeout=10) as r:
        return json.loads(r.read())

def api_post(path: str, body: dict = None) -> dict | None:
    data = json.dumps(body or {}).encode()
    req  = urllib.request.Request(
        url=BASE_URL + path, data=data, headers=_headers(), method="POST"
    )
    with urllib.request.urlopen(req, context=CTX, timeout=10) as r:
        raw = r.read()
        return json.loads(raw) if raw else None

def patrol_start(camera_id: str, patrol_id: str) -> int:
    """Returns HTTP status code from Protect API."""
    api_post(f"/cameras/{camera_id}/ptz/patrol/start", {"patrolId": patrol_id})
    log.info("PATROL_START  cam=%s patrol=%s", camera_id, patrol_id)
    return _last_status[0]

def patrol_stop(camera_id: str) -> int:
    api_post(f"/cameras/{camera_id}/ptz/patrol/stop")
    log.info("PATROL_STOP   cam=%s", camera_id)
    return _last_status[0]

def goto_preset(camera_id: str, slot: int) -> int:
    api_post(f"/cameras/{camera_id}/ptz/move", {
        "type": "toPreset", "payload": {"slot": slot}
    })
    log.info("PRESET_MOVE   cam=%s slot=%d", camera_id, slot)
    return _last_status[0]

# ── Camera state verification ────────────────────────────────────────────────

def get_camera_online_state(camera_id: str) -> tuple[bool, str]:
    """
    Query Protect for current camera connectivity state.
    Returns (is_online: bool, state_string: str).

    The public API does NOT expose patrol/preset state — only CONNECTED/DISCONNECTED.
    This is used to:
      1. Verify camera is reachable before issuing a command
      2. Confirm camera is still online after a command (as close to confirmation as the API allows)
      3. Detect reconnections so we can re-issue patrols
    """
    try:
        data  = api_get(f"/cameras/{camera_id}")
        state = data.get("state", "UNKNOWN")
        return state == "CONNECTED", state
    except Exception as e:
        log.warning("State check failed cam=%s: %s", camera_id, e)
        return False, "UNKNOWN"


def issue_with_retry(
    db,
    camera_id:  str,
    cam_name:   str,
    action_fn,           # callable that performs the API call, returns http_status int
    action_type: str,    # 'patrol_start' | 'patrol_stop' | 'preset_move'
    detail:     str,
    mode:       str,
) -> bool:
    """
    Issue a PTZ command with:
      1. Pre-flight online check  — skip if camera is offline
      2. Command execution        — calls action_fn()
      3. Status verification      — logs 204 (accepted) vs 4xx (rejected)
      4. Post-command online poll — confirms camera still connected after command
      5. Retry on failure         — up to MAX_RETRIES with backoff

    Returns True if command was accepted (204), False if ultimately failed.

    Audit trail logged at every stage:
      - COMMAND_SENT   — intent logged before the call
      - ACCEPTED/FAILED — result of API call
      - ONLINE_CONFIRMED — camera still connected after command
      - OFFLINE_SKIP   — camera was offline, command skipped
      - RETRY_n        — each retry attempt
      - PERSISTENT_FAIL — gave up after MAX_RETRIES
    """
    global _errors_since_start

    state = _retry_state.setdefault(camera_id, {"attempts": 0, "last_try": 0.0})

    # ── Pre-flight: check camera is online ────────────────────────────────────
    is_online, online_state = get_camera_online_state(camera_id)
    if not is_online:
        msg = (f"Command skipped — {cam_name} is {online_state}. "
               f"Will reissue when camera reconnects.")
        log.warning("OFFLINE_SKIP  cam=%s state=%s action=%s", camera_id, online_state, action_type)
        log_action(db, camera_id, "error", msg, "daemon", mode,
                   api_status=None, api_response=online_state)
        # Mark as needing reissue on reconnect
        _offline_state[camera_id] = {
            "was_running": _camera_state.get(camera_id) == "running",
            "offline_since": time.time(),
            "pending_action": action_type,
            "pending_detail": detail,
        }
        return False

    # ── Log intent before calling API ─────────────────────────────────────────
    attempt = state["attempts"] + 1
    prefix  = f"[Attempt {attempt}/{MAX_RETRIES}] " if attempt > 1 else ""
    log_action(db, camera_id, action_type,
               f"{prefix}COMMAND SENT — {detail} | Camera online: {online_state}",
               "daemon", mode)

    # ── Issue command ─────────────────────────────────────────────────────────
    http_status = 0
    try:
        http_status = action_fn()
    except Exception as e:
        http_status = _last_status[0] or 0
        _errors_since_start += 1
        state["attempts"] += 1
        state["last_try"]  = time.time()

        if state["attempts"] < MAX_RETRIES:
            backoff = RETRY_BACKOFF_SECS[min(state["attempts"] - 1, len(RETRY_BACKOFF_SECS) - 1)]
            log_action(db, camera_id, "error",
                       f"Command failed (attempt {state['attempts']}/{MAX_RETRIES}), "
                       f"retrying in {backoff}s — {e}",
                       "daemon", mode, http_status)
            time.sleep(backoff)
            return issue_with_retry(db, camera_id, cam_name, action_fn,
                                    action_type, detail, mode)
        else:
            log_action(db, camera_id, "error",
                       f"PERSISTENT FAILURE after {MAX_RETRIES} attempts — {detail} — {e}",
                       "daemon", mode, http_status)
            state["attempts"] = 0
            return False

    # ── Verify response code ──────────────────────────────────────────────────
    if http_status not in (200, 204):
        _errors_since_start += 1
        state["attempts"] += 1
        state["last_try"]  = time.time()

        if state["attempts"] < MAX_RETRIES:
            backoff = RETRY_BACKOFF_SECS[min(state["attempts"] - 1, len(RETRY_BACKOFF_SECS) - 1)]
            log_action(db, camera_id, "error",
                       f"API returned HTTP {http_status} (attempt {state['attempts']}/{MAX_RETRIES}), "
                       f"retrying in {backoff}s",
                       "daemon", mode, http_status)
            time.sleep(backoff)
            return issue_with_retry(db, camera_id, cam_name, action_fn,
                                    action_type, detail, mode)
        else:
            log_action(db, camera_id, "error",
                       f"PERSISTENT FAILURE — API HTTP {http_status} after {MAX_RETRIES} attempts — {detail}",
                       "daemon", mode, http_status)
            state["attempts"] = 0
            return False

    # ── Command accepted — post-command online verification ───────────────────
    state["attempts"] = 0  # reset retry counter on success
    log.info("API_ACCEPTED  cam=%s action=%s status=%d", camera_id, action_type, http_status)

    # Brief pause then verify camera still connected
    time.sleep(VERIFY_DELAY_SECS)
    is_still_online, post_state = get_camera_online_state(camera_id)

    if is_still_online:
        log_action(db, camera_id, action_type,
                   f"CONFIRMED — {detail} | API: HTTP {http_status} | "
                   f"Camera verified online {VERIFY_DELAY_SECS}s after command ({post_state})",
                   "daemon", mode, http_status)
    else:
        # Camera went offline after we sent the command — unusual but loggable
        log_action(db, camera_id, "error",
                   f"UNVERIFIED — {detail} | API accepted (HTTP {http_status}) but "
                   f"camera is now {post_state} — command may not have executed",
                   "daemon", mode, http_status)
        _errors_since_start += 1

    return True


def check_reconnected_cameras(db, cameras: list[dict]):
    """
    On each poll cycle, check if any camera that was offline has come back.
    If it was supposed to be patrolling, re-issue the patrol command.
    """
    now = time.time()
    for cam in cameras:
        cam_id = cam["camera_id"]
        if cam_id not in _offline_state:
            continue

        offline_info = _offline_state[cam_id]
        is_online, state_str = get_camera_online_state(cam_id)

        if not is_online:
            # Still offline — log if it's been a while
            offline_secs = int(now - offline_info["offline_since"])
            if offline_secs % 300 < 60:  # log roughly every 5 min
                log.warning("STILL_OFFLINE  cam=%s (%ds)", cam_id, offline_secs)
            continue

        # Camera back online
        offline_secs = int(now - offline_info["offline_since"])
        log.info("RECONNECTED  cam=%s after %ds", cam_id, offline_secs)
        log_action(db, cam_id, "sync",
                   f"Camera reconnected after {offline_secs}s offline ({state_str})",
                   "daemon", cam.get("mode") or "unknown")
        del _offline_state[cam_id]

        # Re-issue patrol if it should currently be running
        if offline_info.get("was_running") and is_in_patrol_window(cam["schedule_days"]) and cam["enabled"]:
            log.info("REISSUE_PATROL  cam=%s after reconnect", cam_id)
            log_action(db, cam_id, "patrol_start",
                       f"Re-issuing patrol after reconnect (was running before offline)",
                       "daemon", cam.get("mode") or "unknown")
            # Let evaluate_camera handle it naturally on next poll
            # by resetting state to stopped so it triggers the start path
            _camera_state[cam_id] = "stopped"


# ── NTP sync check ────────────────────────────────────────────────────────────

NTP_SERVER  = "pool.ntp.org"
NTP_PORT    = 123
NTP_TIMEOUT = 5
# Offset thresholds
NTP_WARN_MS  = 500    # warn if drift > 500ms
NTP_ERROR_MS = 5000   # error if drift > 5s


# ── Command retry / verification ─────────────────────────────────────────────
# No API exists to query current PTZ patrol state from Protect.
# The patrol start/stop endpoints return 204 (accepted) with no body.
# We verify by: (1) checking camera online before command, (2) confirming
# camera still online after, (3) retrying on non-204 responses.
MAX_RETRIES          = int(CFG.get("PTZ_MAX_RETRIES",       3))
VERIFY_DELAY_SECS    = int(CFG.get("PTZ_VERIFY_DELAY_SECS", 3))
RETRY_BACKOFF_SECS   = [5, 15, 30]   # grows with each attempt
OFFLINE_REISSUE_SECS = 120

# Per-camera retry tracking { camera_id: { 'attempts': int, 'last_try': float } }
_retry_state:  dict[str, dict] = {}
# Cameras currently offline { camera_id: { 'was_running': bool, 'offline_since': float, ... } }
_offline_state: dict[str, dict] = {}

_ntp_state = {
    "last_check":  0.0,
    "last_sync":   None,   # ISO timestamp
    "offset_ms":   None,
    "status":      "pending",
    "server":      NTP_SERVER,
}

def ntp_query() -> float | None:
    """
    Query NTP server. Returns clock offset in milliseconds (positive = local ahead).
    Returns None on failure.
    """
    try:
        # NTP packet: 48 bytes, mode=3 (client), version=3
        data = b'\x1b' + 47 * b'\0'
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.settimeout(NTP_TIMEOUT)
        s.sendto(data, (NTP_SERVER, NTP_PORT))
        raw, _ = s.recvfrom(1024)
        s.close()

        if len(raw) < 48:
            return None

        # Transmit timestamp is at bytes 40-47 (64-bit NTP timestamp)
        integ, frac = struct.unpack("!II", raw[40:48])
        ntp_time    = integ + frac / (2**32)
        # NTP epoch is 1900, Unix epoch is 1970 — 70 years = 2208988800 seconds
        ntp_unix    = ntp_time - 2208988800
        offset_ms   = (ntp_unix - time.time()) * 1000
        return round(offset_ms, 1)

    except Exception as e:
        log.warning("NTP query failed: %s", e)
        return None

def check_ntp():
    """Run NTP check and update _ntp_state. Log warnings if drift is significant."""
    offset_ms = ntp_query()
    now       = datetime.now(timezone.utc).isoformat()

    if offset_ms is None:
        _ntp_state.update({"status": "error", "last_sync": now, "offset_ms": None})
        log.warning("NTP  Could not reach %s", NTP_SERVER)
        return

    _ntp_state.update({
        "last_sync": now,
        "offset_ms": offset_ms,
        "last_check": time.monotonic(),
    })

    abs_offset = abs(offset_ms)
    if abs_offset > NTP_ERROR_MS:
        _ntp_state["status"] = "error"
        log.error(
            "NTP  Clock drift CRITICAL: %.0fms. Schedules may fire at wrong times. "
            "Consider enabling systemd-timesyncd: timedatectl set-ntp true",
            offset_ms
        )
    elif abs_offset > NTP_WARN_MS:
        _ntp_state["status"] = "warning"
        log.warning("NTP  Clock drift: %.0fms (threshold %dms)", offset_ms, NTP_WARN_MS)
    else:
        _ntp_state["status"] = "ok"
        log.info("NTP  Clock OK — offset %.1fms", offset_ms)

# ── Camera sync ───────────────────────────────────────────────────────────────

# Track known camera states to detect offline transitions
_known_states: dict[str, str] = {}
_last_cam_sync: str | None = None

def sync_cameras(db) -> int:
    global _last_cam_sync
    log.info("SYNC  Fetching cameras from Protect")
    new_count = 0

    try:
        cameras = api_get("/cameras").get("data", [])
    except Exception as e:
        log.error("SYNC  Failed to fetch cameras: %s", e)
        return 0

    cur     = db.cursor(dictionary=True)
    seen    = set()

    for cam in cameras:
        if not cam.get("isPtz", False):
            continue

        cam_id     = cam["id"]
        cam_name   = cam.get("name", "Unknown")
        cam_state  = cam.get("state", "UNKNOWN")
        seen.add(cam_id)

        # Offline state change detection
        prev_state = _known_states.get(cam_id)
        if prev_state and prev_state != cam_state:
            if cam_state == "DISCONNECTED":
                log.warning("CAMERA_OFFLINE  %s (%s)", cam_name, cam_id)
                log_action(db, cam_id, "error", f"Camera went offline (was {prev_state})")
            elif prev_state == "DISCONNECTED" and cam_state == "CONNECTED":
                log.info("CAMERA_ONLINE  %s (%s)", cam_name, cam_id)
                log_action(db, cam_id, "sync", f"Camera back online")
        _known_states[cam_id] = cam_state

        try:
            patrols = api_get(f"/cameras/{cam_id}/ptz/patrols").get("data", [])
        except Exception:
            patrols = []
        try:
            presets = api_get(f"/cameras/{cam_id}/ptz/presets").get("data", [])
        except Exception:
            presets = []

        has_patrol = len(patrols) > 0

        cur.execute("SELECT id FROM cameras WHERE id = %s", (cam_id,))
        existing = cur.fetchone()

        if not existing:
            cur.execute("""
                INSERT INTO cameras (id, name, model, state, is_ptz, has_patrol, enabled, last_synced)
                VALUES (%s, %s, %s, %s, 1, %s, 0, NOW())
            """, (cam_id, cam_name, cam.get("type"), cam_state, 1 if has_patrol else 0))
            cur.execute(
                "INSERT IGNORE INTO camera_schedules (camera_id, mode) VALUES (%s, %s)",
                (cam_id, "patrol" if has_patrol else "cycle")
            )
            new_count += 1
            log.info("SYNC  New PTZ camera: %s (%s)", cam_name, cam_id)
            log_action(db, cam_id, "sync", f"New camera discovered: {cam_name}")
        else:
            cur.execute("""
                UPDATE cameras
                SET name=%s, model=%s, state=%s, has_patrol=%s, last_synced=NOW()
                WHERE id=%s
            """, (cam_name, cam.get("type"), cam_state, 1 if has_patrol else 0, cam_id))

        cur.execute("DELETE FROM camera_patrols WHERE camera_id=%s", (cam_id,))
        for p in patrols:
            cur.execute(
                "INSERT INTO camera_patrols (camera_id,patrol_id,patrol_name) VALUES(%s,%s,%s)",
                (cam_id, p["id"], p.get("name", "Patrol"))
            )

        cur.execute("DELETE FROM camera_presets WHERE camera_id=%s", (cam_id,))
        for p in presets:
            cur.execute(
                "INSERT INTO camera_presets (camera_id,slot,preset_name) VALUES(%s,%s,%s)",
                (cam_id, p["slot"], p.get("name", f"Preset {p['slot']}"))
            )

    # Mark gone cameras disconnected
    cur.execute("SELECT id FROM cameras WHERE is_ptz=1")
    known_db = {r["id"] for r in cur.fetchall()}
    for gid in known_db - seen:
        cur.execute("UPDATE cameras SET state='DISCONNECTED' WHERE id=%s", (gid,))
        log.warning("SYNC  Camera no longer in Protect: %s", gid)

    _last_cam_sync = datetime.now(timezone.utc).isoformat()
    log.info("SYNC  Complete — %d PTZ cameras, %d new", len(seen), new_count)
    return new_count

# ── Heartbeat ─────────────────────────────────────────────────────────────────

_errors_since_start = 0

def write_heartbeat(cameras_managed: int, patrolling_now: int):
    """Write diagnostic JSON consumed by status.php."""
    cameras_offline = len(_offline_state)
    cameras_retrying = sum(1 for s in _retry_state.values() if s.get("attempts", 0) > 0)

    payload = {
        "ts":                  int(time.time()),
        "timestamp":           datetime.now(timezone.utc).isoformat(),
        "cameras_managed":     cameras_managed,
        "patrolling_now":      patrolling_now,
        "cameras_offline":     cameras_offline,
        "cameras_retrying":    cameras_retrying,
        "last_ntp_sync":       _ntp_state["last_sync"],
        "ntp_offset_ms":       _ntp_state["offset_ms"],
        "ntp_status":          _ntp_state["status"],
        "ntp_server":          _ntp_state["server"],
        "last_cam_sync":       _last_cam_sync,
        "errors_since_start":  _errors_since_start,
        "uptime_seconds":      int(time.monotonic() - DAEMON_START),
        "max_retries":         MAX_RETRIES,
        "verify_delay_secs":   VERIFY_DELAY_SECS,
    }
    try:
        HEARTBEAT_PATH.write_text(json.dumps(payload))
    except Exception as e:
        log.error("Heartbeat write failed: %s", e)

# ── Cycle state ───────────────────────────────────────────────────────────────

_cycle_state:     dict[str, int]   = {}
_last_cycle_time: dict[str, float] = {}

def advance_cycle(camera_id: str, slots: list[int], dwell: int, db):
    now  = time.monotonic()
    if now - _last_cycle_time.get(camera_id, 0) < dwell:
        return
    idx       = _cycle_state.get(camera_id, -1)
    next_idx  = (idx + 1) % len(slots)
    next_slot = slots[next_idx]
    try:
        goto_preset(camera_id, next_slot)
        log_action(db, camera_id, "preset_move", f"Cycle to slot {next_slot}")
        _cycle_state[camera_id]     = next_idx
        _last_cycle_time[camera_id] = now
    except Exception as e:
        log_action(db, camera_id, "error", f"Cycle move failed: {e}")

# ── Schedule evaluation ───────────────────────────────────────────────────────

_camera_state: dict[str, str] = {}

def is_in_patrol_window(schedule_days: list[dict]) -> bool:
    now   = datetime.now()
    dow   = now.weekday()
    t_now = now.time().replace(second=0, microsecond=0)
    for day in schedule_days:
        if not day["enabled"] or day["day_of_week"] != dow:
            continue
        start = (datetime.min + day["patrol_start"]).time()
        stop  = (datetime.min + day["patrol_stop"]).time()
        if start <= t_now < stop:
            return True
    return False

def evaluate_camera(db, cam: dict):
    global _errors_since_start
    cam_id = cam["camera_id"]

    if not cam["enabled"]:
        if _camera_state.get(cam_id) == "running":
            cam_name = cam.get("name", cam_id)
            if cam["mode"] == "patrol":
                issue_with_retry(
                    db, cam_id, cam_name,
                    action_fn   = lambda: patrol_stop(cam_id),
                    action_type = "patrol_stop",
                    detail      = "Patrol stopped — camera scheduling disabled via dashboard",
                    mode        = cam["mode"] or "unknown",
                )
            if cam["return_home"]:
                issue_with_retry(
                    db, cam_id, cam_name,
                    action_fn   = lambda: goto_preset(cam_id, 0),
                    action_type = "preset_move",
                    detail      = "Returning to home preset after scheduling disabled",
                    mode        = cam["mode"] or "unknown",
                )
            _camera_state[cam_id] = "stopped"
        return

    # ── Test patrol check ────────────────────────────────────────────────────
    # If test_until is set and in the future, override schedule (keep running).
    # Once expired, clear the flag and stop if not in a real schedule window.
    test_until_raw = cam.get("test_until")
    if test_until_raw:
        test_until = test_until_raw  # datetime object from mysql.connector
        if hasattr(test_until, "timestamp"):
            in_test = test_until.timestamp() > time.time()
        else:
            in_test = False

        if not in_test and _camera_state.get(cam_id) == "running":
            # Test just expired — stop patrol and clear the DB flag
            try:
                if cam["mode"] == "patrol":
                    patrol_stop(cam_id)
                if cam["return_home"]:
                    goto_preset(cam_id, 0)
                log_action(db, cam_id, "patrol_stop", "Test patrol ended — returned to home preset", "daemon", cam["mode"] or "unknown", _last_status[0])
                _camera_state[cam_id] = "stopped"
                db.cursor().execute(
                    "UPDATE camera_schedules SET test_until=NULL WHERE camera_id=%s", (cam_id,)
                )
            except Exception as e:
                log.error("Test stop failed cam=%s: %s", cam_id, e)
        elif in_test:
            # Still in test window — don't touch the patrol, just update state
            if _camera_state.get(cam_id) != "running":
                _camera_state[cam_id] = "running"
            return  # skip normal schedule logic during test

    should_run = is_in_patrol_window(cam["schedule_days"])
    current    = _camera_state.get(cam_id, "unknown")

    if should_run and current != "running":
        cam_name = cam.get("name", cam_id)
        if cam["mode"] == "patrol" and cam["active_patrol_id"]:
            ok = issue_with_retry(
                db, cam_id, cam_name,
                action_fn   = lambda: patrol_start(cam_id, cam["active_patrol_id"]),
                action_type = "patrol_start",
                detail      = f"Scheduled patrol started (mode: patrol, id: {cam['active_patrol_id']})",
                mode        = "patrol",
            )
            if ok:
                _camera_state[cam_id] = "running"
        elif cam["mode"] == "cycle":
            slots = [int(s) for s in (cam["cycle_slots"] or "1").split(",")]
            ok = issue_with_retry(
                db, cam_id, cam_name,
                action_fn   = lambda: goto_preset(cam_id, slots[0]),
                action_type = "patrol_start",
                detail      = f"Scheduled cycle started — moved to slot {slots[0]} of {len(slots)}",
                mode        = "cycle",
            )
            if ok:
                _cycle_state[cam_id]     = 0
                _last_cycle_time[cam_id] = time.monotonic()
                _camera_state[cam_id]    = "running" 

    elif not should_run and current == "running":
        cam_name = cam.get("name", cam_id)
        if cam["mode"] == "patrol":
            ok = issue_with_retry(
                db, cam_id, cam_name,
                action_fn   = lambda: patrol_stop(cam_id),
                action_type = "patrol_stop",
                detail      = "Scheduled patrol window ended — stopping patrol",
                mode        = cam["mode"] or "unknown",
            )
        else:
            ok = True  # cycle mode — no explicit stop needed
        if cam["return_home"]:
            issue_with_retry(
                db, cam_id, cam_name,
                action_fn   = lambda: goto_preset(cam_id, 0),
                action_type = "preset_move",
                detail      = "Returning to home preset (slot 0)",
                mode        = cam["mode"] or "unknown",
            )
        if ok:
            _camera_state[cam_id] = "stopped"
            _cycle_state.pop(cam_id, None)
            _last_cycle_time.pop(cam_id, None)
        try:
            pass  # keep except block below intact
        except Exception as e:
            _errors_since_start += 1
            log.error("Stop failed cam=%s: %s", cam_id, e)
            log_action(db, cam_id, "error", f"Failed to stop patrol: {e}", "daemon", cam["mode"] or "unknown", _last_status[0])

    elif should_run and current == "running" and cam["mode"] == "cycle":
        slots = [int(s) for s in (cam["cycle_slots"] or "1").split(",")]
        advance_cycle(cam_id, slots, cam["dwell_seconds"], db)

# ── Fetch cameras ─────────────────────────────────────────────────────────────

def fetch_cameras(db) -> list[dict]:
    cur = db.cursor(dictionary=True)
    cur.execute("""
        SELECT c.id AS camera_id, c.name, c.enabled, c.has_patrol,
               cs.mode, cs.patrol_id AS active_patrol_id, cs.cycle_slots,
               cs.dwell_seconds, cs.return_home,
               cs.test_until, cs.test_duration
        FROM cameras c
        LEFT JOIN camera_schedules cs ON cs.camera_id = c.id
        WHERE c.is_ptz = 1
    """)
    cameras = cur.fetchall()
    for cam in cameras:
        cur.execute("""
            SELECT sd.* FROM schedule_days sd
            JOIN camera_schedules cs ON cs.id = sd.schedule_id
            WHERE cs.camera_id = %s ORDER BY sd.day_of_week
        """, (cam["camera_id"],))
        cam["schedule_days"] = cur.fetchall()
    return cameras

# ── Main loop ─────────────────────────────────────────────────────────────────

def main():
    log.info("PTZ patrol daemon starting")
    log.info("Config: NVR=%s  DB=%s@%s/%s  sync=%dh  ntp_check=%dh",
             CFG["NVR_IP"], CFG["DB_USER"], CFG["DB_HOST"],
             CFG["DB_NAME"], SYNC_INTERVAL_HOURS, NTP_CHECK_INTERVAL_HOURS)

    sync_secs     = SYNC_INTERVAL_HOURS * 3600
    ntp_secs      = NTP_CHECK_INTERVAL_HOURS * 3600
    last_sync     = 0.0   # force immediate sync on start
    last_ntp      = 0.0   # force immediate NTP check on start

    while True:
        try:
            db  = get_db()
            now = time.monotonic()

            # ── NTP check ────────────────────────────────────────────────────
            if now - last_ntp >= ntp_secs:
                check_ntp()
                last_ntp = now

            # ── Camera sync ───────────────────────────────────────────────────
            if now - last_sync >= sync_secs:
                sync_cameras(db)
                last_sync = now

            # ── Schedule evaluation ───────────────────────────────────────────
            cameras = fetch_cameras(db)

            # Check for cameras that were offline and have reconnected
            check_reconnected_cameras(db, cameras)

            for cam in cameras:
                evaluate_camera(db, cam)

            patrolling = sum(
                1 for c in cameras
                if _camera_state.get(c["camera_id"]) == "running"
            )

            # ── Heartbeat ─────────────────────────────────────────────────────
            write_heartbeat(
                cameras_managed = len(cameras),
                patrolling_now  = patrolling,
            )

            db.close()

        except mysql.connector.Error as e:
            _errors_since_start += 1
            log.error("DB error: %s", e)
            write_heartbeat(0, 0)   # keep heartbeat alive even on DB error
        except Exception as e:
            _errors_since_start += 1
            log.error("Unexpected error: %s", e)

        time.sleep(POLL_INTERVAL)

if __name__ == "__main__":
    main()
