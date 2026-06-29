#!/usr/bin/env bash
# =============================================================================
# PTZ Patrol Manager — Update Script
# Pulls latest files from GitHub, preserves config and snapshots,
# restarts the daemon. Safe to re-run at any time.
# Run as root: bash update.sh
# =============================================================================

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

info()    { echo -e "${CYAN}[INFO]${RESET}  $*"; }
success() { echo -e "${GREEN}[OK]${RESET}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${RESET}  $*"; }
error()   { echo -e "${RED}[ERROR]${RESET} $*" >&2; exit 1; }
step()    { echo -e "\n${BOLD}${CYAN}▶ $*${RESET}"; }

GITHUB_USER="eddwatts"
GITHUB_REPO="unifi-ptz-manager"
GITHUB_BRANCH="main"
GITHUB_ARCHIVE="https://github.com/${GITHUB_USER}/${GITHUB_REPO}/archive/refs/heads/${GITHUB_BRANCH}.tar.gz"

INSTALL_DIR="/var/www/ptz"
BACKUP_DIR="/var/backups/ptz-update-$(date +%Y%m%d-%H%M%S)"

# --non-interactive flag: skips confirmation prompts, used by systemd timer
NON_INTERACTIVE=false
for arg in "$@"; do [[ "$arg" == "--non-interactive" ]] && NON_INTERACTIVE=true; done

[[ $EUID -eq 0 ]] || error "Run as root: sudo bash update.sh"
[[ -d "$INSTALL_DIR" ]] || error "Install dir not found: $INSTALL_DIR — run install.sh first"

step "PTZ Patrol Manager Update"
echo -e "  Started:  $(date '+%Y-%m-%d %H:%M:%S')"
[[ "$NON_INTERACTIVE" == "true" ]] && info "Running non-interactively (systemd timer)"
echo -e "  Pulling from: ${BOLD}github.com/${GITHUB_USER}/${GITHUB_REPO}${RESET}\n"

# ── Backup preserved files ────────────────────────────────────────────────────
step "Backing up config and data"
mkdir -p "$BACKUP_DIR"

# Always preserve these — never overwrite on update
# Files to preserve during update — all credentials live in /etc/ptz/
PRESERVE=(snapshots/)         # web root items to preserve
ETC_PTZ='/etc/ptz'            # config + secrets (always preserved)
for item in "${PRESERVE[@]}"; do
    [[ -e "${INSTALL_DIR}/${item}" ]] && cp -r "${INSTALL_DIR}/${item}" "${BACKUP_DIR}/"
done
# Back up /etc/ptz/ entirely (config + secrets)
[[ -d "$ETC_PTZ" ]] && cp -r "$ETC_PTZ" "${BACKUP_DIR}/etc-ptz"
success "Backup written to ${BACKUP_DIR}"

# ── Stop daemon ───────────────────────────────────────────────────────────────
step "Stopping patrol daemon"
if systemctl is-active ptz-patrol &>/dev/null; then
    systemctl stop ptz-patrol
    success "Daemon stopped"
else
    info "Daemon was not running"
fi

# ── Download latest ───────────────────────────────────────────────────────────
step "Downloading latest release"
TMPDIR_DL=$(mktemp -d)

curl -fsSL --max-time 30 "$GITHUB_ARCHIVE" -o "${TMPDIR_DL}/ptz.tar.gz" \
    || error "Download failed from ${GITHUB_ARCHIVE}"

tar -xzf "${TMPDIR_DL}/ptz.tar.gz" -C "${TMPDIR_DL}"
EXTRACTED=$(find "${TMPDIR_DL}" -maxdepth 1 -mindepth 1 -type d | head -1)
success "Downloaded and extracted"

# ── Copy new files — skip preserved items ─────────────────────────────────────
step "Installing updated files"

rsync -a --exclude='config.php' --exclude='snapshots/' \
    "${EXTRACTED}/" "${INSTALL_DIR}/" 2>/dev/null \
    || cp -r "${EXTRACTED}/." "${INSTALL_DIR}/"

# Restore preserved files from backup
for item in "${PRESERVE[@]}"; do
    [[ -e "${BACKUP_DIR}/${item}" ]] && cp -r "${BACKUP_DIR}/${item}" "${INSTALL_DIR}/"
done
# Restore secrets file (never overwrite with update)
[[ -f "${BACKUP_DIR}/secrets.php" ]] && cp "${BACKUP_DIR}/secrets.php" "$SECRETS_FILE" && \
    chown root:www-data "$SECRETS_FILE" && chmod 640 "$SECRETS_FILE"

rm -rf "${TMPDIR_DL}"
success "Files updated (config.php and snapshots/ preserved)"

# ── Update Python dependencies ────────────────────────────────────────────────
step "Updating Python dependencies"
if [[ -f /opt/ptz-venv/bin/pip ]]; then
    /opt/ptz-venv/bin/pip install --quiet --upgrade mysql-connector-python \
        && success "Python deps updated"
else
    warn "venv not found at /opt/ptz-venv — skipping Python update"
fi

# ── Fix permissions ───────────────────────────────────────────────────────────
step "Fixing permissions"
chown -R root:www-data "$INSTALL_DIR"
find "$INSTALL_DIR" -type f -exec chmod 640 {} \;
find "$INSTALL_DIR" -type d -exec chmod 750 {} \;
[[ -f "${INSTALL_DIR}/config.php" ]]  && chmod 640 "${INSTALL_DIR}/config.php"
[[ -d "${INSTALL_DIR}/snapshots" ]]   && chown -R www-data:www-data "${INSTALL_DIR}/snapshots"
chmod +x "${INSTALL_DIR}/install.sh" "${INSTALL_DIR}/update.sh" 2>/dev/null || true
success "Permissions set"

# ── Run schema migrations (IF NOT EXISTS is safe to replay) ──────────────────
step "Checking database schema"
if [[ -f "${INSTALL_DIR}/config.php" ]] && [[ -f "${INSTALL_DIR}/sql/schema.sql" ]]; then
    source <(grep -E "define\('DB_(HOST|NAME|USER|PASS|PORT)" "${INSTALL_DIR}/config.php" \
        | sed "s/define('\([^']*\)',\s*'\([^']*\)');/\1='\2'/")
    DB_HOST="${DB_HOST:-localhost}"; DB_PORT="${DB_PORT:-3306}"
    mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
        < "${INSTALL_DIR}/sql/schema.sql" 2>/dev/null \
        && success "Schema up to date" \
        || warn "Schema check skipped — run manually if needed"
fi

# ── Reload Nginx + PHP-FPM ────────────────────────────────────────────────────
step "Reloading web services"
systemctl reload nginx    2>/dev/null && info "Nginx reloaded"
systemctl reload php8.2-fpm 2>/dev/null && info "PHP-FPM reloaded" || true

# ── Restart daemon ────────────────────────────────────────────────────────────
step "Restarting patrol daemon"
systemctl start ptz-patrol
sleep 2
if systemctl is-active ptz-patrol &>/dev/null; then
    success "Daemon running"
else
    warn "Daemon did not start — check: journalctl -fu ptz-patrol"
fi

# ── Summary ───────────────────────────────────────────────────────────────────
echo
echo -e "${BOLD}${GREEN}Update complete.${RESET}"
echo -e "  Backup:  ${BACKUP_DIR}"
echo -e "  Status:  $(systemctl is-active ptz-patrol) (ptz-patrol)"
echo -e "  Check:   journalctl -fu ptz-patrol"
