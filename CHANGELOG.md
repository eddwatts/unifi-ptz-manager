# Changelog

All notable changes to PTZ Patrol Manager are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.5.0] — Current

### Security
- All credentials moved to `/etc/ptz/secrets.php` — entirely outside the web root
- Non-sensitive settings in `/etc/ptz/config.php` — also outside web root
- Nginx cannot serve either file regardless of configuration
- `session.php` centralises all cookie security: `SameSite=Strict`, `HttpOnly`, `Secure`, renamed session
- `snapshot.php` now requires active SSO session (was unauthenticated)
- `ptz-php.ini` hardening drop-in: `open_basedir`, `expose_php=Off`, disabled functions
- `APP_SECRET` generated per-installation, stored in secrets file

### Authentication & access control
- `users.php` — dedicated user management page: add, revoke, restore, remove, change role
- `access_users` DB table — explicit per-user grants with role, enabled flag, last login, login count, added-by
- No domain wildcards — access must be individually granted per person (appropriate for CCTV)
- Admin email seeded into `access_users` on first wizard save
- Setup wizard step 1 now asks for admin email before proceeding
- Clear login denial messages: "access revoked" vs "not on access list"
- Revoke takes effect on next session check (8h max)

### Audit logging
- `actor` (email) and `ip_address` columns added to `action_log`
- IP resolution via `CF-Connecting-IP` header (real client IP through Cloudflare)
- Login events logged: successful login, login denied, logout
- Manual actions (start/stop/test) log the Google email of who triggered them
- Schedule changes log diff (previous mode → new mode, enable/disable changes)
- Enable/disable dashboard toggle is a dedicated `toggle_enabled` action — logged as `patrol_start`/`patrol_stop`
- User access changes logged: add, revoke, restore, role change, remove
- Config changes logged when Settings are saved
- Tunnel install/start/stop/restart logged via `config_change`
- All new action types in log viewer filter: login, login_denied, logout, schedule_change, config_change, user_change

### PTZ command reliability
- Pre-flight camera online check before every command
- Post-command online verification (configurable delay, default 3s)
- Retry on non-204 API response (configurable attempts, default 3, with backoff: 5s, 15s, 30s)
- Offline camera detection — reissues patrol automatically on reconnect
- Honest API limitation documented: Protect public API has no patrol state endpoint
- `PTZ_MAX_RETRIES` and `PTZ_VERIFY_DELAY_SECS` configurable in `/etc/ptz/config.php`

### Log system
- Full-featured log viewer at `/log.php`
- Filters: camera, action type, triggered-by, date range, text search
- Columns: timestamp, camera, action badge, mode pill, source (daemon/manual/sync), detail, API status, actor, IP
- Pagination (100/page, smart page buttons)
- CSV export carries current filters, up to 10,000 rows
- Auto-refresh live mode (30s)
- Dashboard log modal upgraded: quick-filter by camera and action, shows actor column, links to full viewer
- `LOG_RETENTION_DAYS` constant (default 90) — nightly cron purges older entries
- Log retention configurable in Settings (0 = keep forever)

### Setup & configuration
- Auto-update toggle and daily time configurable in Settings (systemd timer, `Persistent=true`)
- Snapshot refresh interval configurable in Settings (10–300s, default 30s)
- Status endpoint public/private toggle in Settings
- `STATUS_PUBLIC` controls whether `/status.php` requires SSO session
- `SNAPSHOT_REFRESH_SECONDS` passed from PHP to JS as `PTZ_CONFIG` object — no hardcoded intervals in JS

### Infrastructure
- `tunnel_setup.php` audit: fixed double-execution bug (command ran twice), fixed `apt-get update` without sudo, added rate limiting, added audit logging
- Log retention cron job (nightly, reads `LOG_RETENTION_DAYS` from config)
- Rate limit table purge cron (hourly, removes stale entries)
- Schema migration block fully covers all new columns (safe to replay)

---

## [1.0.0] — Initial release

### Core
- PTZ camera discovery and sync from UniFi Protect Integration API v1
- Per-camera patrol scheduling with per-day-of-week start and stop times
- Two patrol modes: built-in Protect patrol (G6 PTZ) or timed preset cycling (all PTZ models)
- Python daemon: 60-second poll cycle, NTP sync every 3h, camera sync every N hours
- Camera offline/reconnect detection and state logging
- Daemon heartbeat file at `/tmp/ptz_daemon_heartbeat.json`

### Web UI
- Dashboard with live camera snapshots (auto-refresh)
- Schedule editor: day/time picker, patrol mode or cycle mode, return-to-home option
- Test patrol button — 30-second live test with countdown badge on card
- Copy schedule — copy one camera's schedule to multiple targets
- Duplicate schedule button — shortcut directly from camera card
- Daemon health indicator in topbar (polls status every 30s)
- Last-action line on each camera card

### Authentication
- Google OAuth 2.0 SSO — no password login
- Email allowlist stored in `access_users` DB table
- Admin email always has access (cannot be revoked)

### Infrastructure
- One-command install on Debian 12 — idempotent, safe to re-run
- Setup wizard with 3-step browser-based configuration
- Cloudflare Tunnel setup via web UI — no inbound ports needed
- `/status.php` health check endpoint with HTTP 200/503
- `update.sh` for manual and scheduled updates
- systemd service + update timer
- UFW default deny, Fail2ban, unattended-upgrades
- API rate limiting: 120 reads / 30 writes per 60s per session (HTTP 429)

### Database
- 7 tables: cameras, camera_patrols, camera_presets, camera_schedules, schedule_days, action_log, rate_limit
- Schema migrations safe to replay (CREATE TABLE IF NOT EXISTS + ALTER TABLE ADD COLUMN IF NOT EXISTS)
