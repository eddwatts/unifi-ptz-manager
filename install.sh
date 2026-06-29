#!/usr/bin/env bash
# =============================================================================
# PTZ Patrol Manager — Install Script
# Target: Debian 12 (Bookworm) minimal
# Idempotent: safe to re-run on an existing install
# Run as root: bash <(curl -fsSL https://raw.githubusercontent.com/GITHUB_USER/GITHUB_REPO/main/install.sh)
# =============================================================================

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

info()    { echo -e "${CYAN}[INFO]${RESET}  $*"; }
success() { echo -e "${GREEN}[OK]${RESET}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${RESET}  $*"; }
error()   { echo -e "${RED}[ERROR]${RESET} $*" >&2; exit 1; }
step()    { echo -e "\n${BOLD}${CYAN}▶ $*${RESET}"; }
skip()    { echo -e "${CYAN}[SKIP]${RESET}  $* (already installed)"; }

# ── Config ────────────────────────────────────────────────────────────────────
GITHUB_USER="eddwatts"
GITHUB_REPO="unifi-ptz-manager"
GITHUB_BRANCH="main"
GITHUB_ARCHIVE="https://github.com/${GITHUB_USER}/${GITHUB_REPO}/archive/refs/heads/${GITHUB_BRANCH}.tar.gz"

INSTALL_DIR="/var/www/ptz"
WEB_USER="www-data"
NGINX_CONF="/etc/nginx/sites-available/ptz"
SYSTEMD_UNIT="/etc/systemd/system/ptz-patrol.service"
SYSTEMD_UPDATE_UNIT="/etc/systemd/system/ptz-update.service"
SYSTEMD_UPDATE_TIMER="/etc/systemd/system/ptz-update.timer"
LOG_FILE="/var/log/ptz_install.log"

PHP_PACKAGES=(
    php8.2-fpm php8.2-mysql php8.2-curl
    php8.2-gd  php8.2-mbstring php8.2-xml
)

# Collected during prompts
DB_PASS=""
SERVER_HOST=""
APP_TZ=""
INSTALL_CF=""
AUTO_UPDATE=""
UPDATE_TIME=""

# ── Preflight ─────────────────────────────────────────────────────────────────
preflight() {
    step "Preflight checks"

    [[ $EUID -eq 0 ]] || error "Run as root: sudo bash install.sh"

    if [[ -f /etc/os-release ]]; then
        . /etc/os-release
        if [[ "$ID" != "debian" ]]; then
            warn "Detected OS: ${PRETTY_NAME}. This script targets Debian 12."
        elif [[ "${VERSION_ID}" != "12" ]]; then
            warn "Detected Debian ${VERSION_ID}. Tested on Debian 12 (Bookworm)."
        else
            success "Debian 12 (Bookworm) detected"
        fi
    fi

    curl -fsSL --max-time 5 https://1.1.1.1 &>/dev/null || error "No internet access."
    success "Internet reachable"

    FREE_MB=$(df /var --output=avail -m | tail -1)
    [[ $FREE_MB -ge 500 ]] || error "Less than 500MB free on /var."
    success "Disk space OK (${FREE_MB}MB free)"

    # Detect re-run
    if [[ -f "${INSTALL_DIR}/config.php" ]]; then
        warn "Existing installation detected at ${INSTALL_DIR}"
        warn "Re-running install.sh is safe — existing config.php and snapshots/ will be preserved."
        echo
    fi
}

# ── Collect config ────────────────────────────────────────────────────────────
collect_config() {
    step "Configuration"
    echo -e "${BOLD}Answer the prompts below. Press Enter to accept defaults.${RESET}\n"

    # DB password — skip if already set in existing config
    if grep -q "define('DB_PASS'" "${INSTALL_DIR}/config.php" 2>/dev/null; then
        DB_PASS=$(grep "define('DB_PASS'" "${INSTALL_DIR}/config.php" \
                  | sed "s/.*define('DB_PASS', '\([^']*\)'.*/\1/")
        info "DB password loaded from existing config.php"
    else
        while true; do
            read -rsp "  MySQL password for ptz_user: " DB_PASS; echo
            read -rsp "  Confirm password:            " DB_PASS2; echo
            [[ "$DB_PASS" == "$DB_PASS2" ]] && [[ -n "$DB_PASS" ]] && break
            warn "Passwords don't match or are empty. Try again."
        done
    fi

    read -rp "  Server hostname or IP (e.g. ptz.yourdomain.com): " SERVER_HOST
    SERVER_HOST="${SERVER_HOST:-localhost}"

    read -rp "  Timezone [Europe/London]: " APP_TZ
    APP_TZ="${APP_TZ:-Europe/London}"

    read -rp "  Install Cloudflare Tunnel (cloudflared)? [Y/n]: " INSTALL_CF
    INSTALL_CF="${INSTALL_CF:-Y}"

    echo
    read -rp "  Enable automatic project updates from GitHub? [Y/n]: " AUTO_UPDATE
    AUTO_UPDATE="${AUTO_UPDATE:-Y}"

    if [[ "${AUTO_UPDATE,,}" == "y" ]]; then
        read -rp "  Daily update time [03:00]: " UPDATE_TIME
        UPDATE_TIME="${UPDATE_TIME:-03:00}"
        # Validate HH:MM format
        if ! [[ "$UPDATE_TIME" =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]]; then
            warn "Invalid time format — defaulting to 03:00"
            UPDATE_TIME="03:00"
        fi
        info "Auto-updates scheduled at ${UPDATE_TIME} daily"
    fi

    echo
    success "Configuration collected"
}

# ── Idempotent package installer ──────────────────────────────────────────────
# Only calls apt-get if at least one package is missing
ensure_packages() {
    local desc="$1"; shift
    local packages=("$@")
    local missing=()

    for pkg in "${packages[@]}"; do
        dpkg -l "$pkg" 2>/dev/null | grep -q "^ii" || missing+=("$pkg")
    done

    if [[ ${#missing[@]} -eq 0 ]]; then
        skip "${desc}"
        return
    fi

    step "Installing ${desc}"
    apt-get install -y --no-install-recommends "${missing[@]}" >> "$LOG_FILE" 2>&1
    success "${desc} installed"
}

# ── System update ─────────────────────────────────────────────────────────────
system_update() {
    step "Updating package lists"
    apt-get update -qq >> "$LOG_FILE" 2>&1
    success "Package lists updated"
}

# ── PHP ───────────────────────────────────────────────────────────────────────
install_php() {
    ensure_packages "PHP 8.2" "${PHP_PACKAGES[@]}"

    # Drop-in php.ini for security hardening and session config
    PHP_INI_DIR="/etc/php/8.2/fpm/conf.d"
    if [[ -d "$PHP_INI_DIR" ]]; then
        cp "${INSTALL_DIR}/ptz-php.ini" "${PHP_INI_DIR}/99-ptz.ini" 2>/dev/null || true
        # open_basedir path is now set — but if INSTALL_DIR differs, patch it
        sed -i "s|open_basedir = /var/www/ptz|open_basedir = ${INSTALL_DIR}|"             "${PHP_INI_DIR}/99-ptz.ini" 2>/dev/null || true
        systemctl reload php8.2-fpm >> "$LOG_FILE" 2>&1 || true
        success "PHP hardening config installed (${PHP_INI_DIR}/99-ptz.ini)"
    else
        warn "PHP FPM conf.d not found — skipping php.ini hardening"
    fi

    success "PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'installed')"
}

# ── Nginx ─────────────────────────────────────────────────────────────────────
install_nginx() {
    ensure_packages "Nginx" nginx

    # Remove default site if present
    rm -f /etc/nginx/sites-enabled/default

    # Write/overwrite vhost (safe to redo — config is deterministic)
    cat > "$NGINX_CONF" << NGINXEOF
server {
    listen 127.0.0.1:80;
    server_name ${SERVER_HOST};

    root ${INSTALL_DIR};
    index index.php;

    access_log /var/log/nginx/ptz_access.log;
    error_log  /var/log/nginx/ptz_error.log;

    location ~* ^/(config\.php|auth_check\.php|ProtectAPI\.php|daemon/|sql/|snapshots/\.htaccess) {
        deny all; return 404;
    }

    location ~ /\. { deny all; return 404; }

    location ~ \.php$ {
        include        snippets/fastcgi-php.conf;
        fastcgi_pass   unix:/run/php/php8.2-fpm.sock;
        fastcgi_param  SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include        fastcgi_params;
        add_header X-Frame-Options DENY;
        add_header X-Content-Type-Options nosniff;
        add_header Referrer-Policy strict-origin-when-cross-origin;
    }

    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1h;
        add_header Cache-Control "public, immutable";
    }

    location / { try_files \$uri \$uri/ =404; }
}
NGINXEOF

    ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/ptz

    nginx -t >> "$LOG_FILE" 2>&1 || error "Nginx config test failed — check $LOG_FILE"
    systemctl enable --now nginx  >> "$LOG_FILE" 2>&1
    systemctl reload nginx        >> "$LOG_FILE" 2>&1
    success "Nginx configured (127.0.0.1:80)"
}

# ── MariaDB ───────────────────────────────────────────────────────────────────
install_mariadb() {
    ensure_packages "MariaDB" mariadb-server
    systemctl enable --now mariadb >> "$LOG_FILE" 2>&1

    # Idempotent secure setup — IF NOT EXISTS guards prevent errors on re-run
    mysql -e "DELETE FROM mysql.user WHERE User='';"                                               >> "$LOG_FILE" 2>&1 || true
    mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');" >> "$LOG_FILE" 2>&1 || true
    mysql -e "DROP DATABASE IF EXISTS test;"                                                       >> "$LOG_FILE" 2>&1 || true
    mysql << SQL >> "$LOG_FILE" 2>&1
CREATE DATABASE IF NOT EXISTS unifi_ptz CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ptz_user'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON unifi_ptz.* TO 'ptz_user'@'localhost';
FLUSH PRIVILEGES;
SQL
    success "MariaDB ready — database 'unifi_ptz' and user 'ptz_user' configured"
}

# ── Python ────────────────────────────────────────────────────────────────────
install_python() {
    ensure_packages "Python 3" python3 python3-pip python3-venv

    if [[ ! -d /opt/ptz-venv ]]; then
        python3 -m venv /opt/ptz-venv >> "$LOG_FILE" 2>&1
    else
        skip "Python venv"
    fi

    /opt/ptz-venv/bin/pip install --quiet --upgrade mysql-connector-python >> "$LOG_FILE" 2>&1
    success "Python $(python3 --version | cut -d' ' -f2) ready"
}

# ── Cloudflare Tunnel ─────────────────────────────────────────────────────────
install_cloudflared() {
    if [[ "${INSTALL_CF,,}" != "y" ]]; then
        info "Skipping Cloudflare Tunnel"
        return
    fi

    if command -v cloudflared &>/dev/null; then
        skip "cloudflared ($(cloudflared --version 2>&1 | head -1))"
    else
        step "Installing cloudflared"
        apt-get install -y --no-install-recommends lsb-release curl gnupg >> "$LOG_FILE" 2>&1
        mkdir -p /etc/apt/keyrings

        curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg \
            -o /etc/apt/keyrings/cloudflare-main.gpg 2>> "$LOG_FILE"

        echo "deb [signed-by=/etc/apt/keyrings/cloudflare-main.gpg] \
https://pkg.cloudflare.com/cloudflared $(lsb_release -cs) main" \
            > /etc/apt/sources.list.d/cloudflared.list

        apt-get update -qq >> "$LOG_FILE" 2>&1
        apt-get install -y cloudflared >> "$LOG_FILE" 2>&1
        success "cloudflared $(cloudflared --version 2>&1 | head -1) installed"
    fi

    # Sudoers — always write (idempotent, overwrites same content)
    cat > /etc/sudoers.d/ptz-cloudflared << 'SUDOERS'
# PTZ Patrol Manager — allow web server to manage cloudflared only
www-data ALL=(root) NOPASSWD: /usr/bin/cloudflared service install *
www-data ALL=(root) NOPASSWD: /usr/bin/cloudflared service uninstall
www-data ALL=(root) NOPASSWD: /bin/systemctl start cloudflared
www-data ALL=(root) NOPASSWD: /bin/systemctl stop cloudflared
www-data ALL=(root) NOPASSWD: /bin/systemctl restart cloudflared
www-data ALL=(root) NOPASSWD: /bin/systemctl status cloudflared
www-data ALL=(root) NOPASSWD: /bin/systemctl enable cloudflared
www-data ALL=(root) NOPASSWD: /bin/systemctl is-enabled cloudflared
www-data ALL=(root) NOPASSWD: /usr/bin/apt-get install -y cloudflared
SUDOERS

    if visudo -c -f /etc/sudoers.d/ptz-cloudflared >> "$LOG_FILE" 2>&1; then
        chmod 440 /etc/sudoers.d/ptz-cloudflared
        success "Cloudflare sudoers entry configured"
    else
        rm -f /etc/sudoers.d/ptz-cloudflared
        warn "Sudoers validation failed — tunnel web install may not work"
    fi

    info "Configure tunnel via setup.php → Cloudflare Tunnel section"
}

# ── UFW ───────────────────────────────────────────────────────────────────────
configure_firewall() {
    step "Configuring firewall (UFW)"
    ensure_packages "UFW" ufw

    # Only reset if UFW is not yet enabled — avoid disrupting an existing config on re-run
    if ! ufw status | grep -q "Status: active"; then
        ufw --force reset >> "$LOG_FILE" 2>&1
        ufw default deny incoming  >> "$LOG_FILE" 2>&1
        ufw default allow outgoing >> "$LOG_FILE" 2>&1

        SSH_PORT=$(ss -tlnp | awk '/sshd/ {match($4,/:([0-9]+)$/,m); if(m[1]) print m[1]}' | head -1)
        SSH_PORT="${SSH_PORT:-22}"
        ufw allow "${SSH_PORT}/tcp" comment "SSH" >> "$LOG_FILE" 2>&1
        ufw --force enable >> "$LOG_FILE" 2>&1
        success "UFW enabled — SSH (port ${SSH_PORT}) only"
    else
        skip "UFW (already active — rules preserved)"
    fi
}

# ── Fail2ban ──────────────────────────────────────────────────────────────────
install_fail2ban() {
    ensure_packages "Fail2ban" fail2ban

    # Only write jail.local if not already present
    if [[ ! -f /etc/fail2ban/jail.local ]]; then
        cat > /etc/fail2ban/jail.local << 'EOF'
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5

[sshd]
enabled = true
EOF
    fi

    systemctl enable --now fail2ban >> "$LOG_FILE" 2>&1
    success "Fail2ban protecting SSH"
}

# ── Unattended upgrades ───────────────────────────────────────────────────────
install_unattended_upgrades() {
    step "Enabling unattended security upgrades"
    ensure_packages "Unattended-upgrades" unattended-upgrades

    cat > /etc/apt/apt.conf.d/50unattended-upgrades-ptz << 'EOF'
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}-security";
};
Unattended-Upgrade::AutoFixInterruptedDpkg "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
EOF

    cat > /etc/apt/apt.conf.d/20auto-upgrades << 'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
    success "Unattended security upgrades enabled"
}

# ── Download project ──────────────────────────────────────────────────────────
download_project() {
    step "Downloading PTZ Patrol Manager from GitHub"
    mkdir -p "$INSTALL_DIR"

    TMPDIR_DL=$(mktemp -d)

    if curl -fsSL --max-time 30 "$GITHUB_ARCHIVE" -o "${TMPDIR_DL}/ptz.tar.gz" 2>> "$LOG_FILE"; then
        tar -xzf "${TMPDIR_DL}/ptz.tar.gz" -C "${TMPDIR_DL}" >> "$LOG_FILE" 2>&1
        EXTRACTED=$(find "${TMPDIR_DL}" -maxdepth 1 -mindepth 1 -type d | head -1)

        # Preserve existing config.php and snapshots on re-run
        rsync -a \
            --exclude='config.php' \
            --exclude='snapshots/' \
            "${EXTRACTED}/" "${INSTALL_DIR}/" 2>/dev/null \
            || cp -r "${EXTRACTED}/." "${INSTALL_DIR}/"

        rm -rf "${TMPDIR_DL}"
        success "Project files downloaded"
    else
        rm -rf "${TMPDIR_DL}"
        error "Could not download from GitHub (${GITHUB_ARCHIVE})\nCheck GITHUB_USER and GITHUB_REPO at top of script."
    fi
}

# ── Permissions ───────────────────────────────────────────────────────────────
configure_permissions() {
    step "Configuring file permissions"

    mkdir -p "${INSTALL_DIR}/snapshots" "${INSTALL_DIR}/docs"
    touch "${INSTALL_DIR}/snapshots/.gitkeep" "${INSTALL_DIR}/docs/.gitkeep" 2>/dev/null || true

    chown -R root:"$WEB_USER" "$INSTALL_DIR"
    find "$INSTALL_DIR" -type f -exec chmod 640 {} \;
    find "$INSTALL_DIR" -type d -exec chmod 750 {} \;

    [[ -f "${INSTALL_DIR}/config.php" ]] && chmod 640 "${INSTALL_DIR}/config.php"
    chown -R "$WEB_USER":"$WEB_USER" "${INSTALL_DIR}/snapshots"
    chmod 750 "${INSTALL_DIR}/snapshots"
    chmod 750 "${INSTALL_DIR}/daemon"

    # Scripts must be executable
    chmod +x "${INSTALL_DIR}/install.sh" \
             "${INSTALL_DIR}/update.sh"  2>/dev/null || true

    success "Permissions configured"
}

# ── Write config.php ──────────────────────────────────────────────────────────
write_initial_config() {
    # Generate a random secret key for this installation
    APP_SECRET=$(php -r "echo bin2hex(random_bytes(32));" 2>/dev/null \
                 || openssl rand -hex 32 \
                 || cat /proc/sys/kernel/random/uuid | tr -d '-')

    # ── /etc/ptz/ directory ────────────────────────────────────────────────────
    mkdir -p /etc/ptz
    chown root:"$WEB_USER" /etc/ptz
    chmod 750 /etc/ptz

    # ── /etc/ptz/config.php — non-sensitive settings ────────────────────────────
    # Always write (idempotent — no credentials in this file)
    step "Writing /etc/ptz/config.php (settings) and /etc/ptz/secrets.php (credentials)"

    cat > /etc/ptz/config.php << PHP
<?php
/**
 * config.php — non-sensitive settings only.
 * Credentials are stored in /etc/ptz/secrets.php (outside web root).
 * Complete setup at: http://${SERVER_HOST}/setup.php
 */

if (php_sapi_name() !== 'cli'
    && realpath(\$_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404); exit;
}

define('APP_TIMEZONE',        '${APP_TZ}');
define('APP_VERSION',         '1.0.0');
define('SYNC_INTERVAL_HOURS',       6);
define('SNAPSHOT_REFRESH_SECONDS',  30);
define('STATUS_PUBLIC',            'y');
define('AUTO_UPDATE',          '${AUTO_UPDATE,,}');
define('AUTO_UPDATE_TIME',     '${UPDATE_TIME}');
define('RATE_LIMIT_READS',     120);
define('RATE_LIMIT_WRITES',     30);
define('RATE_LIMIT_WINDOW',     60);
define('SECRETS_FILE',         '/etc/ptz/secrets.php');
define('INSTALL_DIR',          '/var/www/ptz');

date_default_timezone_set(APP_TIMEZONE);

if (file_exists(SECRETS_FILE)) {
    require_once SECRETS_FILE;
} else {
    foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS','DB_PORT',
              'NVR_IP','API_TOKEN','API_BASE',
              'GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET','GOOGLE_REDIRECT_URI',
              'ADMIN_EMAIL','APP_SECRET'] as \$k) {
        if (!defined(\$k)) define(\$k, \$k === 'DB_PORT' ? 3306 : '');
    }
}

function get_db(): PDO {
    static \$pdo = null;
    if (\$pdo === null) {
        \$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME);
        \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }
    return \$pdo;
}
PHP

    chown root:"$WEB_USER" /etc/ptz/config.php
    chmod 640 /etc/ptz/config.php

    # ── /etc/ptz/secrets.php — credentials (only write if absent) ─────────────
    if [[ -f /etc/ptz/secrets.php ]]; then
        skip "/etc/ptz/secrets.php (existing credentials preserved)"
    else
        cat > /etc/ptz/secrets.php << PHP
<?php
/**
 * secrets.php — PTZ Patrol Manager credential store.
 * Location: /etc/ptz/secrets.php  (OUTSIDE web root — cannot be served by Nginx)
 * Owner: root:www-data  Mode: 640
 * Complete via the setup wizard at: http://${SERVER_HOST}/setup.php
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'unifi_ptz');
define('DB_USER', 'ptz_user');
define('DB_PASS', '${DB_PASS}');
define('DB_PORT', 3306);

define('NVR_IP',    '');
define('API_TOKEN', '');
define('API_BASE',  'https://' . NVR_IP . '/proxy/protect/integration/v1');

define('GOOGLE_CLIENT_ID',     '');
define('GOOGLE_CLIENT_SECRET', '');
define('GOOGLE_REDIRECT_URI',  'https://${SERVER_HOST}/auth.php?action=callback');
define('ADMIN_EMAIL',          '');

define('APP_SECRET', '${APP_SECRET}');
PHP

        chown root:"$WEB_USER" /etc/ptz/secrets.php
        chmod 640 /etc/ptz/secrets.php
        success "Credentials written to /etc/ptz/secrets.php (outside web root)"
    fi
}


# ── DB schema ─────────────────────────────────────────────────────────────────
run_schema() {
    step "Creating/updating database tables"

    SCHEMA="${INSTALL_DIR}/sql/schema.sql"
    if [[ -f "$SCHEMA" ]]; then
        # All statements use IF NOT EXISTS — safe to replay on re-run
        # Schema file also contains ALTER TABLE IF NOT EXISTS migrations
        mysql -u ptz_user -p"${DB_PASS}" unifi_ptz < "$SCHEMA" >> "$LOG_FILE" 2>&1
        success "Database schema up to date"
    else
        warn "Schema file not found — run setup.php to create tables"
    fi
}

# ── Daemon service ────────────────────────────────────────────────────────────
install_daemon_service() {
    step "Installing patrol daemon service"

    cat > "$SYSTEMD_UNIT" << EOF
[Unit]
Description=PTZ Patrol Manager — camera scheduler daemon
After=network.target mariadb.service
Wants=mariadb.service

[Service]
Type=simple
ExecStart=/opt/ptz-venv/bin/python3 ${INSTALL_DIR}/daemon/patrol.py
WorkingDirectory=${INSTALL_DIR}/daemon
Restart=on-failure
RestartSec=15
StandardOutput=journal
StandardError=journal
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${INSTALL_DIR} /var/log /tmp
PrivateTmp=false

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload >> "$LOG_FILE" 2>&1
    systemctl enable ptz-patrol >> "$LOG_FILE" 2>&1

    # Restart if already running (picks up new code on re-run)
    if systemctl is-active ptz-patrol &>/dev/null; then
        systemctl restart ptz-patrol >> "$LOG_FILE" 2>&1
        success "Patrol daemon restarted"
    else
        success "Patrol daemon installed (start after setup wizard)"
    fi
}

# ── Auto-update systemd timer ─────────────────────────────────────────────────
install_auto_update() {
    if [[ "${AUTO_UPDATE,,}" != "y" ]]; then
        # Remove timer if it exists and user has disabled it
        if systemctl is-enabled ptz-update.timer &>/dev/null 2>&1; then
            systemctl disable --now ptz-update.timer >> "$LOG_FILE" 2>&1
            info "Auto-update timer disabled"
        fi
        info "Auto-updates disabled"
        return
    fi

    step "Installing auto-update systemd timer (${UPDATE_TIME} daily)"

    UPDATE_HOUR="${UPDATE_TIME%%:*}"
    UPDATE_MIN="${UPDATE_TIME##*:}"

    # One-shot service that runs update.sh
    cat > "$SYSTEMD_UPDATE_UNIT" << EOF
[Unit]
Description=PTZ Patrol Manager — automatic update
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/bin/bash ${INSTALL_DIR}/update.sh --non-interactive
StandardOutput=journal
StandardError=journal
EOF

    # Persistent calendar timer — fires even if the system was off at the scheduled time
    cat > "$SYSTEMD_UPDATE_TIMER" << EOF
[Unit]
Description=PTZ Patrol Manager — daily auto-update at ${UPDATE_TIME}

[Timer]
OnCalendar=*-*-* ${UPDATE_HOUR}:${UPDATE_MIN}:00
Persistent=true
RandomizedDelaySec=300

[Install]
WantedBy=timers.target
EOF

    systemctl daemon-reload            >> "$LOG_FILE" 2>&1
    systemctl enable --now ptz-update.timer >> "$LOG_FILE" 2>&1
    success "Auto-update timer enabled — runs daily at ${UPDATE_TIME}"
    info "Next run: $(systemctl list-timers ptz-update.timer --no-pager 2>/dev/null | grep ptz | awk '{print $1,$2}' || echo 'see: systemctl list-timers')"
}

# ── Cron and logrotate ────────────────────────────────────────────────────────
install_cron() {
    step "Installing maintenance cron jobs"

    cat > /etc/cron.d/ptz-patrol << EOF
# PTZ Patrol Manager maintenance
# Purge snapshot cache (keep last 2h)
0 */2 * * * ${WEB_USER} find ${INSTALL_DIR}/snapshots/ -name "*.jpg" -mmin +120 -delete 2>/dev/null
# Rotate daemon log file weekly
0 3 * * 0 root /usr/sbin/logrotate -f /etc/logrotate.d/ptz-patrol 2>/dev/null
# Purge action_log entries older than LOG_RETENTION_DAYS (default 90 days)
# Reads the setting from config.php; falls back to 90 days if not set
30 2 * * * root php -r "require '/etc/ptz/config.php'; \\$days=defined('LOG_RETENTION_DAYS')&&LOG_RETENTION_DAYS>0?LOG_RETENTION_DAYS:90; \\$pdo=get_db(); \\$del=\\$pdo->prepare('DELETE FROM action_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'); \\$del->execute([\\$days]); \\$n=\\$del->rowCount(); if(\\$n>0) error_log('PTZ: purged '.\\$n.' log entries older than '.\\$days.' days');" 2>/dev/null
# Purge stale rate_limit entries (older than 1h — well beyond any window)
45 * * * * root mysql -u ptz_user -p"\$(grep DB_PASS /etc/ptz/secrets.php | sed "s/.*'\([^']*\)'.*/\1/")" unifi_ptz -e "DELETE FROM rate_limit WHERE hit_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);" 2>/dev/null
EOF

    cat > /etc/logrotate.d/ptz-patrol << 'EOF'
/var/log/unifi_ptz_daemon.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    create 640 root root
}
EOF
    success "Cron and logrotate configured"
}

# ── Summary ───────────────────────────────────────────────────────────────────
print_summary() {
    local IS_RERUN=false
    [[ -f "${INSTALL_DIR}/config.php" ]] && IS_RERUN=true

    echo
    echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════════╗${RESET}"
    echo -e "${BOLD}${GREEN}║           PTZ Patrol Manager — Installation Complete          ║${RESET}"
    echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════════════╝${RESET}"
    echo
    echo -e "  ${BOLD}Next steps:${RESET}"
    echo
    echo -e "  ${CYAN}1.${RESET} Complete setup wizard:"
    echo -e "     ${BOLD}http://${SERVER_HOST}/setup.php${RESET}"
    echo

    if [[ "${INSTALL_CF,,}" == "y" ]]; then
        echo -e "  ${CYAN}2.${RESET} Set up Cloudflare Tunnel via the web UI:"
        echo -e "     Go to one.dash.cloudflare.com → Networks → Tunnels → Create a tunnel"
        echo -e "     Copy the install command and paste it into setup.php → Cloudflare Tunnel"
        echo
    fi

    echo -e "  ${CYAN}3.${RESET} Start the patrol daemon after setup wizard completes:"
    echo -e "     ${BOLD}systemctl start ptz-patrol${RESET}"
    echo

    if [[ "${AUTO_UPDATE,,}" == "y" ]]; then
        echo -e "  ${CYAN}4.${RESET} Auto-updates configured at ${BOLD}${UPDATE_TIME}${RESET} daily"
        echo -e "     ${BOLD}systemctl list-timers ptz-update.timer${RESET}"
        echo
    fi

    echo -e "  ${CYAN}5.${RESET} To update manually at any time:"
    echo -e "     ${BOLD}bash ${INSTALL_DIR}/update.sh${RESET}"
    echo
    echo -e "  ${BOLD}Config/secrets:${RESET} /etc/ptz/ (outside web root)"
  echo -e "  ${BOLD}Web root:${RESET}       ${INSTALL_DIR}"
    echo -e "  ${BOLD}DB name:${RESET}       unifi_ptz"
    echo -e "  ${BOLD}DB user:${RESET}       ptz_user"
    echo -e "  ${BOLD}Install log:${RESET}   ${LOG_FILE}"
    echo
    echo -e "  ${BOLD}Service commands:${RESET}"
    echo -e "    systemctl {start|stop|restart|status} ptz-patrol"
    echo -e "    journalctl -fu ptz-patrol"
    echo
}

# ── Main ──────────────────────────────────────────────────────────────────────
main() {
    mkdir -p "$(dirname "$LOG_FILE")"
    touch "$LOG_FILE"
    chmod 640 "$LOG_FILE"

    echo -e "\n${BOLD}${CYAN}PTZ Patrol Manager — Installer${RESET}"
    echo -e "${CYAN}══════════════════════════════${RESET}"
    echo -e "Log: ${LOG_FILE}\n"

    preflight
    collect_config
    system_update
    install_php
    install_nginx
    install_mariadb
    install_python
    install_cloudflared
    configure_firewall
    install_fail2ban
    install_unattended_upgrades
    download_project
    configure_permissions
    write_initial_config
    run_schema
    install_daemon_service
    install_auto_update
    install_cron
    print_summary
}

main "$@"
