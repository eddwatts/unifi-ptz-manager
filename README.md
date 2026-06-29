<div align="center">

# PTZ Patrol Manager

**Self-hosted PTZ camera patrol scheduler for UniFi Protect**

Automated patrol scheduling with a web UI, live snapshots, Google SSO,
full audit logging, and Cloudflare Tunnel access — no open inbound ports required.

[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php&logoColor=white)](https://php.net)
[![Python](https://img.shields.io/badge/Python-3.11+-3776AB?logo=python&logoColor=white)](https://python.org)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.6+-003545?logo=mariadb&logoColor=white)](https://mariadb.org)
[![Debian](https://img.shields.io/badge/Debian-12-A81D33?logo=debian&logoColor=white)](https://debian.org)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

</div>

---

## Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [OS Recommendation](#os-recommendation)
- [Pre-Installation](#pre-installation)
- [Installation](#installation)
- [Setup Wizard](#setup-wizard)
- [Dashboard](#dashboard)
- [Schedule Editor](#schedule-editor)
- [User Management](#user-management)
- [Activity Log](#activity-log)
- [Status Endpoint](#status-endpoint)
- [Security](#security)
- [Post-Installation](#post-installation)
- [Troubleshooting](#troubleshooting)
- [File Structure](#file-structure)

---

## Overview

PTZ Patrol Manager adds time-based patrol scheduling to UniFi Protect — something the Protect UI doesn't provide natively. A Python daemon polls the schedule database every 60 seconds and issues start/stop commands to Protect's Integration API. Every action is logged with who triggered it, when, what the API responded, and whether the camera was confirmed online.

**Key features:**

- 📷 Live camera snapshots — configurable auto-refresh, click to force
- 🗓️ Per-camera weekly schedule — per-day start/stop times
- 🔄 Two patrol modes — **Protect patrol** (built-in route) or **preset cycling** (timed steps)
- 🧪 Test patrol — 30-second live test before committing to a schedule
- 📋 Duplicate schedule — copy to multiple cameras in one click
- 🔒 Google SSO — explicit per-user access grants, no domain wildcards
- 👥 User management — add, revoke, restore, change role; full audit trail
- 📊 Full audit log — every action with actor, IP, API response code
- 🌐 Cloudflare Tunnel — configure from the UI, no open inbound ports
- 🔄 Auto-updates — daily systemd timer, preserves all credentials
- 📦 One-command install on Debian 12

---

## Architecture

```
Browser → Cloudflare Tunnel → Nginx (127.0.0.1:80) → PHP 8.2-FPM
                                                            │
                                          ┌─────────────────┼──────────────────┐
                                          │                 │                  │
                                     MariaDB           Python Daemon      UniFi Protect
                                    unifi_ptz          patrol.py          Integration API
                                          │            (60s poll)               │
                                          └─────────────────┴──────────────────┘
```

**Config split — nothing sensitive in the web root:**

```
/etc/ptz/config.php     ← non-sensitive settings  (root:www-data 640)
/etc/ptz/secrets.php    ← all credentials          (root:www-data 640)
/var/www/ptz/           ← web root (PHP files only, no credentials)
```

---

## Requirements

| Resource | Minimum |
|----------|---------|
| OS | Debian 12 (Bookworm) |
| RAM | 512 MB |
| Disk | 10 GB |
| PHP | 8.2+ with pdo_mysql, curl, gd, mbstring, xml |
| Python | 3.11+ |
| MariaDB | 10.6+ |
| UniFi Protect | 5.3+ (Integration API required) |

**External services:**

| Service | Cost | Purpose |
|---------|------|---------|
| Google Cloud | Free | OAuth 2.0 credentials |
| Cloudflare | Free plan | Tunnel + HTTPS |

**Network:** Outbound port **7844** (TCP/UDP) for Cloudflare Tunnel only. No inbound ports needed. Port 443 outbound is optional (enables cloudflared auto-updates).

---

## OS Recommendation

**Debian 12 (Bookworm) minimal — strongly recommended.** All packages are available from Debian or official repos. No third-party PPAs.

Designed for a **dedicated low-cost device** — not an existing server.

| Option | Notes |
|--------|-------|
| **Raspberry Pi 4 or 5** (Pi OS Lite 64-bit) | ⭐ Ideal — low power, fanless, ~£50–80 |
| **Old PC or mini PC** (NUC, Beelink, etc.) | Give it a purpose — x86-64, Debian 12 |
| Ubuntu 24.04 LTS | Works, minor differences, not primary target |
| Alpine Linux | ❌ musl libc causes PHP extension issues |

---

## Pre-Installation

### 1 — UniFi Protect API token

1. In the Protect web UI: **Settings → Control Plane → Integrations**
2. Click **Create API Key**, name it, copy the token — shown once only

### 2 — Google OAuth 2.0

1. [console.cloud.google.com](https://console.cloud.google.com) → New Project
2. **APIs & Services → OAuth consent screen** → External → fill app name → save
3. **Credentials → Create Credentials → OAuth 2.0 Client ID** → Web application
4. Add Authorised redirect URI: `https://your-domain.com/auth.php?action=callback`
5. Copy **Client ID** and **Client Secret**

### 3 — Cloudflare Tunnel

1. [one.dash.cloudflare.com](https://one.dash.cloudflare.com) → **Networks → Tunnels → Create a tunnel**
2. Name it, save, copy the **install command** (starts with `sudo cloudflared service install eyJ…`)
3. Add a **Public Hostname**: your subdomain → `HTTP` → `localhost:80`

---

## Installation

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/GITHUB_USER/GITHUB_REPO/main/install.sh)
```

**Idempotent** — safe to re-run. Existing credentials and data are always preserved.

Prompts during install:

| Prompt | Notes |
|--------|-------|
| MySQL password | Choose a strong password |
| Server hostname | Your Cloudflare public hostname |
| Timezone | Default: Europe/London |
| Install Cloudflare Tunnel? | Y recommended |
| Enable auto-updates? | Y recommended |
| Daily update time | Default 03:00 |

What gets installed: PHP 8.2, Nginx (localhost only), MariaDB, Python 3 venv, cloudflared, UFW, Fail2ban, unattended-upgrades, PHP hardening ini, systemd services, `/etc/ptz/` config directory.

---

## Setup Wizard

Browse to `http://YOUR_HOST/setup.php` after installation.

**Step 1 — UniFi Protect + admin email**
Enter NVR IP, API token, and your Google admin email. Click **Test Connection** — must pass before continuing.

**Step 2 — Database & settings**
DB fields are pre-filled. Enter the MySQL password, timezone, camera sync interval.

**Step 3 — Google SSO**
Fill in Client ID, Client Secret, Redirect URI. Your admin email is seeded into the user access list automatically.

After the wizard completes, go to **Settings → Cloudflare Tunnel** and paste the install command from the Cloudflare dashboard.

---

## Dashboard

Each PTZ camera appears as a card showing:

- Live snapshot with configurable auto-refresh
- State badge (Live / Offline)
- Enable/disable scheduling toggle
- Schedule summary (mode, active days)
- **Start · Stop · Test · Schedule · Duplicate** action buttons
- Last action line (what happened, when)

**Daemon health dot** in the topbar shows green/amber/red — polls `/status.php` every 30 seconds.

### Test patrol
Click **Test** to run a 30-second patrol immediately. A countdown badge appears on the card. The daemon also reads the expiry and stops cleanly even if the page is closed.

### Duplicate schedule
Click **Duplicate** on a camera card to copy its schedule to one or more other cameras. Each target's enabled state is preserved.

---

## Schedule Editor

Click **Schedule** on any camera card:

- **Mode:** Protect patrol (built-in route) or Preset cycling (timed preset steps)
- **Patrol mode:** select from patrols configured in Protect
- **Cycle mode:** select preset slots, set dwell time in seconds, return-to-home option
- **Weekly timetable:** enable days, set start/stop times per day
- **Copy to…** — copy this schedule to other cameras from within the modal

---

## User Management

Navigate to **Users** (👥 icon in topbar — admin only) or `/users.php`.

Access is **explicit and per-person** — no domain wildcards. Every account that can view the CCTV dashboard must be individually granted access.

| Column | Detail |
|--------|--------|
| Status | Green dot = active, red = revoked |
| Email | Google account email |
| Name | Display name from Google (populated on first login) |
| Role | Admin (full access) or Viewer (dashboard only) |
| Last login | Date and time of most recent login |
| Logins | Total login count |
| Added by | Admin who granted access |
| Notes | Optional admin note |

**Actions:** Grant access · Revoke · Restore · Remove · Change role

Protected: current user cannot revoke themselves. Admin email cannot be revoked via UI (change it in Settings).

---

## Activity Log

### Quick view — modal

Click the log icon 📋 in the topbar. Filter by camera and action type. Shows the 50 most recent entries. Link to full viewer at the top.

### Full viewer — `/log.php`

**Filters:** camera · action type · triggered-by · date range · text search

**Columns:**

| Column | Detail |
|--------|--------|
| Timestamp | Server local time |
| Camera | Camera name — denormalised, survives camera deletion |
| Action | Colour-coded badge |
| Mode | patrol or cycle |
| Source | ⚙ daemon · 👤 manual · ↺ sync |
| Detail | Human-readable description |
| API status | HTTP code from Protect (204 = accepted, 4xx = error) |
| Actor | Email of user who triggered the action |
| IP address | Client IP (via CF-Connecting-IP) |

**Action types logged:**

| Action | Logged by | Actor captured |
|--------|-----------|---------------|
| Patrol start (scheduled) | Daemon | — |
| Patrol start (manual) | Dashboard | ✅ Google email |
| Patrol stop | Daemon / Dashboard | ✅ if manual |
| Preset move | Daemon | — |
| Test patrol | Dashboard | ✅ |
| Schedule saved | Dashboard | ✅ |
| Schedule mode changed | Dashboard | ✅ |
| Enable/disable toggle | Dashboard | ✅ |
| Schedule copied | Dashboard | ✅ |
| Login / denied / logout | Auth system | ✅ |
| User granted/revoked/removed | Users page | ✅ |
| Config changed | Settings wizard | ✅ |
| Tunnel install/start/stop | Tunnel setup | ✅ |
| Camera offline/reconnect | Daemon | — |
| API retry attempts | Daemon | — |
| NTP drift warning | Daemon | — |

**Retention:** configurable in Settings (default 90 days). Nightly cron purges older entries. Set to 0 to keep forever.

**CSV export:** carries current filters, up to 10,000 rows.

**Auto-refresh:** Live mode refreshes every 30 seconds.

---

## Status Endpoint

`GET /status.php` — returns JSON health of all components. HTTP 200 = ok/degraded, HTTP 503 = critical component down. Accessible without login by default (configurable in Settings).

```bash
curl https://your-domain.com/status.php?pretty=1
```

```json
{
  "status": "ok",
  "components": {
    "php":       { "status": "ok", "version": "8.2.x" },
    "database":  { "status": "ok", "cameras_total": 3, "rate_limit_hits": 4 },
    "protect":   { "status": "ok", "cameras_ptz": 2, "response_ms": 18 },
    "daemon":    {
      "status": "ok",
      "age_seconds": 42,
      "patrolling_now": 1,
      "cameras_offline": 0,
      "cameras_retrying": 0,
      "ntp_offset_ms": 8.2,
      "ntp_status": "ok",
      "errors_since_start": 0,
      "uptime_seconds": 86400
    },
    "tunnel":    { "status": "ok", "running": true, "connected": true },
    "snapshots": { "status": "ok", "writable": true, "cached_snapshots": 3 },
    "config":    {
      "auto_update": "y",
      "update_timer_active": true,
      "next_update": "2025-06-27 03:00:00"
    }
  }
}
```

Use with Uptime Kuma, Zabbix, or any HTTP monitor that checks status codes.

---

## Security

| Control | Detail |
|---------|--------|
| Config location | `/etc/ptz/` — outside web root, Nginx cannot serve these files |
| File permissions | `root:www-data 640` — web server reads, nobody else |
| Authentication | Google OAuth 2.0 — no password login |
| Authorisation | Explicit per-user DB table — no domain wildcards |
| Session cookies | `HttpOnly` · `Secure` · `SameSite=Strict` · renamed from PHPSESSID |
| Session fixation | `session_regenerate_id(true)` on every login |
| API rate limiting | 120 reads / 30 writes per 60s per session (HTTP 429) |
| Tunnel rate limit | 10 install attempts per 5 minutes |
| PHP hardening | `open_basedir` · `expose_php=Off` · dangerous functions disabled |
| Network | Nginx on 127.0.0.1 only · UFW default deny · SSH only |
| Tunnel | Cloudflare outbound only — zero open inbound ports |
| Sudo scope | `www-data` limited to cloudflared and systemctl cloudflared only |
| Snapshot auth | Requires active SSO session |
| Brute force | Fail2ban on SSH |
| OS patching | Unattended security upgrades |
| Project updates | Optional daily auto-update (systemd timer, Persistent=true) |

---

## Post-Installation

### Daemon management

```bash
systemctl start ptz-patrol        # Start
systemctl stop ptz-patrol         # Stop
systemctl restart ptz-patrol      # Restart (picks up config changes within 60s)
systemctl status ptz-patrol       # Status
journalctl -fu ptz-patrol         # Follow live log
```

### Manual update

```bash
sudo bash /var/www/ptz/update.sh
```

Backs up `/etc/ptz/` and `snapshots/` first. Config and credentials always preserved.

### Log files

| File | Contents |
|------|----------|
| `/var/log/unifi_ptz_daemon.log` | Daemon stdout — patrols, NTP, sync, retry events |
| `/var/log/nginx/ptz_access.log` | Web UI requests |
| `/var/log/nginx/ptz_error.log` | Nginx errors |
| `/var/log/ptz_install.log` | Full install.sh output |
| Activity log in UI | Every action with actor, IP, API response |
| `journalctl -u cloudflared` | Tunnel connection log |

---

## Troubleshooting

<details>
<summary><strong>Cameras not appearing after sync</strong></summary>

- Verify NVR IP and API token in Settings → UniFi Protect → Test Connection
- Only PTZ cameras are imported (`isPtz: true` in Protect API)
- Test directly: `curl -k https://NVR_IP/proxy/protect/integration/v1/cameras -H "X-API-Key: TOKEN"`
- Check daemon log: `journalctl -fu ptz-patrol`

</details>

<details>
<summary><strong>Patrol not starting on schedule</strong></summary>

- Check camera Enable toggle is on in the dashboard
- Daemon polls every 60s — up to 60s delay after changes
- Check the activity log for error entries — API status codes will show if Protect rejected the command
- For patrol mode: verify a patrol route exists in Protect for that camera
- Check: `journalctl -fu ptz-patrol`

</details>

<details>
<summary><strong>Patrol command shows UNVERIFIED in log</strong></summary>

Protect's public API accepts commands with HTTP 204 but provides no patrol state endpoint. UNVERIFIED means the API accepted the command but the camera went offline in the 3 seconds after. Check Protect for the camera's status directly.

</details>

<details>
<summary><strong>Google login failing</strong></summary>

- **Redirect URI mismatch** — the URI in Google Cloud Console must exactly match `GOOGLE_REDIRECT_URI` in `/etc/ptz/secrets.php`
- **Email denied** — check Users page to confirm the account has been granted access

</details>

<details>
<summary><strong>Cloudflare Tunnel not connecting</strong></summary>

- `journalctl -fu cloudflared`
- Token rotated? Paste new install command in Settings → Cloudflare Tunnel
- Check outbound port 7844: `ufw allow out 7844`

</details>

<details>
<summary><strong>Daemon health dot showing red</strong></summary>

The heartbeat file at `/tmp/ptz_daemon_heartbeat.json` is older than 3 minutes.

```bash
systemctl status ptz-patrol
journalctl -fu ptz-patrol --since "10 minutes ago"
```

Common causes: DB connection failed, `/etc/ptz/secrets.php` missing or corrupt, Python venv missing.

</details>

<details>
<summary><strong>Snapshots showing grey placeholder</strong></summary>

- Camera may be offline — check Protect
- API token may have expired — regenerate in Protect, update in Settings
- Fix permissions: `chown www-data:www-data /var/www/ptz/snapshots && chmod 750 /var/www/ptz/snapshots`

</details>

---

## File Structure

```
/etc/ptz/                          ← outside web root (root:www-data 750)
├── config.php                     ← non-sensitive settings
└── secrets.php                    ← all credentials (NVR, DB, OAuth, APP_SECRET)

/var/www/ptz/                      ← web root
├── index.php                      ← dashboard
├── api.php                        ← JSON API endpoint
├── log.php                        ← activity log viewer
├── users.php                      ← user access management
├── status.php                     ← health check endpoint
├── snapshot.php                   ← snapshot proxy + cache
├── auth.php                       ← Google OAuth handler
├── auth_check.php                 ← one-line auth guard
├── session.php                    ← centralised session security
├── login.php                      ← login page
├── setup.php                      ← setup wizard + settings
├── tunnel_setup.php               ← Cloudflare Tunnel installer
├── ProtectAPI.php                 ← UniFi Protect HTTP wrapper
├── ptz-php.ini                    ← PHP security hardening
├── snapshots/                     ← JPEG cache (www-data writable)
├── install.sh                     ← one-command installer
├── update.sh                      ← manual/auto update script
├── daemon/
│   ├── patrol.py                  ← Python scheduler daemon
│   ├── ptz-patrol.service         ← systemd service unit
│   ├── ptz-update.service         ← auto-update one-shot service
│   └── ptz-update.timer           ← auto-update daily timer
└── sql/
    └── schema.sql                 ← DB schema + migrations
```

---

## Licence

MIT — see [LICENSE](LICENSE).

---

<div align="center">
<sub>Tested on UniFi Protect 5.3+ · Debian 12 · Raspberry Pi 4/5</sub>
</div>
