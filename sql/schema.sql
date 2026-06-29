-- unifi_ptz schema
-- Run once: mysql -u root -p unifi_ptz < schema.sql

CREATE TABLE IF NOT EXISTS cameras (
    id            VARCHAR(24)  NOT NULL PRIMARY KEY,   -- Protect's 24-char device ID
    name          VARCHAR(128) NOT NULL,
    model         VARCHAR(64)  DEFAULT NULL,
    state         VARCHAR(32)  DEFAULT 'UNKNOWN',      -- CONNECTED, DISCONNECTED, etc.
    is_ptz        TINYINT(1)   NOT NULL DEFAULT 0,
    has_patrol    TINYINT(1)   NOT NULL DEFAULT 0,      -- false = use preset cycling
    enabled       TINYINT(1)   NOT NULL DEFAULT 0,      -- user enabled patrol scheduling
    last_synced   DATETIME     DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Patrols configured in Protect for this camera
CREATE TABLE IF NOT EXISTS camera_patrols (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    camera_id   VARCHAR(24)  NOT NULL,
    patrol_id   VARCHAR(64)  NOT NULL,    -- Protect's patrol UUID
    patrol_name VARCHAR(128) NOT NULL DEFAULT 'Unnamed Patrol',
    UNIQUE KEY uq_cam_patrol (camera_id, patrol_id),
    FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PTZ presets for this camera
CREATE TABLE IF NOT EXISTS camera_presets (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    camera_id   VARCHAR(24)  NOT NULL,
    slot        TINYINT      NOT NULL,    -- 0-8, slot 0 = home
    preset_name VARCHAR(128) NOT NULL DEFAULT 'Unnamed Preset',
    UNIQUE KEY uq_cam_slot (camera_id, slot),
    FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-camera patrol configuration
CREATE TABLE IF NOT EXISTS camera_schedules (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    camera_id       VARCHAR(24)  NOT NULL UNIQUE,
    mode            ENUM('patrol','cycle') NOT NULL DEFAULT 'patrol',
    patrol_id       VARCHAR(64)  DEFAULT NULL,    -- used when mode=patrol
    cycle_slots     VARCHAR(32)  DEFAULT NULL,    -- CSV of slot numbers e.g. "1,2,3,4"
    dwell_seconds   SMALLINT     NOT NULL DEFAULT 30,
    return_home     TINYINT(1)   NOT NULL DEFAULT 1,  -- go to slot 0 on stop
    test_until      DATETIME     DEFAULT NULL,          -- daemon stops patrol when this passes
    test_duration   SMALLINT     NOT NULL DEFAULT 30,   -- seconds for test patrol
    FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-day schedule windows (multiple rows per camera_schedule)
CREATE TABLE IF NOT EXISTS schedule_days (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    schedule_id     INT UNSIGNED NOT NULL,
    day_of_week     TINYINT NOT NULL,   -- 0=Mon, 1=Tue ... 6=Sun (ISO-ish)
    patrol_start    TIME    NOT NULL,   -- time patrol begins
    patrol_stop     TIME    NOT NULL,   -- time patrol ends
    enabled         TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_schedule_day (schedule_id, day_of_week),
    FOREIGN KEY (schedule_id) REFERENCES camera_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit log — every daemon action recorded here
CREATE TABLE IF NOT EXISTS action_log (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    camera_id    VARCHAR(24)   NOT NULL,
    camera_name  VARCHAR(128)  DEFAULT NULL,        -- denormalised for log readability after camera removal
    action       ENUM(
                     'patrol_start','patrol_stop','preset_move',
                     'sync','error',
                     'login','login_denied','logout',
                     'config_change','schedule_change','user_change'
                 ) NOT NULL,
    camera_mode  ENUM('patrol','cycle','unknown')    NOT NULL DEFAULT 'unknown',
    detail       TEXT          DEFAULT NULL,         -- human-readable description
    api_status   SMALLINT      DEFAULT NULL,         -- HTTP status returned by Protect API (200, 204, 4xx etc)
    api_response TEXT          DEFAULT NULL,         -- truncated API response body on error
    triggered_by ENUM('daemon','manual','sync')      NOT NULL DEFAULT 'daemon',
    actor        VARCHAR(255)  DEFAULT NULL,         -- email of user who triggered this (NULL for daemon)
    ip_address   VARCHAR(45)   DEFAULT NULL,         -- IPv4 or IPv6, NULL for daemon actions
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_camera_time  (camera_id, created_at),
    INDEX idx_action_time  (action, created_at),
    INDEX idx_created_at   (created_at),
    INDEX idx_actor        (actor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- Schema migrations — safe to run on existing installs (IF NOT EXISTS / IF EXISTS)
-- ─────────────────────────────────────────────────────────────────────────────

-- v1.1: Enrich action_log with denormalised camera name, mode, and API response
ALTER TABLE action_log
    ADD COLUMN IF NOT EXISTS camera_name  VARCHAR(128) DEFAULT NULL        AFTER camera_id,
    ADD COLUMN IF NOT EXISTS camera_mode  ENUM('patrol','cycle','unknown')
                                          NOT NULL DEFAULT 'unknown'       AFTER action,
    ADD COLUMN IF NOT EXISTS api_status   SMALLINT DEFAULT NULL            AFTER detail,
    ADD COLUMN IF NOT EXISTS api_response TEXT     DEFAULT NULL            AFTER api_status,
    ADD INDEX  IF NOT EXISTS idx_action_time  (action, created_at),
    ADD INDEX  IF NOT EXISTS idx_created_at   (created_at);

-- v1.2: test patrol columns on camera_schedules
ALTER TABLE camera_schedules
    ADD COLUMN IF NOT EXISTS test_until    DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS test_duration SMALLINT NOT NULL DEFAULT 30;

-- v1.3: rate_limit table (also created on first API request, belt-and-braces)
CREATE TABLE IF NOT EXISTS rate_limit (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rate_key    VARCHAR(64)  NOT NULL,
    action_type ENUM('read','write') NOT NULL,
    hit_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_key_type_time (rate_key, action_type, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v1.4: User access management
CREATE TABLE IF NOT EXISTS access_users (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(255)  NOT NULL UNIQUE,
    name        VARCHAR(128)  DEFAULT NULL,        -- display name from Google on first login
    role        ENUM('admin','viewer') NOT NULL DEFAULT 'viewer',
    enabled     TINYINT(1)    NOT NULL DEFAULT 1,  -- 0 = revoked
    notes       VARCHAR(255)  DEFAULT NULL,         -- optional admin note
    added_by    VARCHAR(255)  DEFAULT NULL,         -- email of admin who added this user
    last_login  DATETIME      DEFAULT NULL,
    login_count INT UNSIGNED  NOT NULL DEFAULT 0,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email   (email),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v1.4 + v1.5: Extend action_log — login events, audit fields
ALTER TABLE action_log
    MODIFY COLUMN action ENUM(
        'patrol_start','patrol_stop','preset_move',
        'sync','error',
        'login','login_denied','logout',
        'config_change','schedule_change','user_change'
    ) NOT NULL,
    ADD COLUMN IF NOT EXISTS actor      VARCHAR(255) DEFAULT NULL AFTER triggered_by,
    ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45)  DEFAULT NULL AFTER actor,
    ADD INDEX  IF NOT EXISTS idx_actor  (actor);
