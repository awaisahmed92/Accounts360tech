-- ============================================================
-- Accounts360tech — Tenant Core Schema (v1)
-- Applied by bootstrap.php for each tenant DB.
-- {{TENANT_DB}} is replaced at runtime with the actual DB name.
-- ============================================================

USE {{TENANT_DB}};

-- ── 1. users ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(100)  NOT NULL,
    email               VARCHAR(150)  UNIQUE NOT NULL,
    password_hash       VARCHAR(255)  NOT NULL,
    role                ENUM('admin','user') DEFAULT 'user',
    is_active           TINYINT(1) DEFAULT 1,
    email_verified_at   TIMESTAMP NULL DEFAULT NULL,
    last_login_at       TIMESTAMP NULL DEFAULT NULL,
    failed_login_count  TINYINT DEFAULT 0,
    locked_until        TIMESTAMP NULL DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. refresh_tokens ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token       VARCHAR(128) UNIQUE NOT NULL,
    expires_at  TIMESTAMP NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token    (token),
    INDEX idx_user_id  (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. documents ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS documents (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    original_file_name  VARCHAR(255) NOT NULL,
    stored_file_name    VARCHAR(255) NOT NULL,
    file_path           VARCHAR(500) NOT NULL,
    file_type           VARCHAR(50)  NOT NULL,
    file_size           INT NOT NULL,
    status              ENUM('pending','processing','ready','error','archived') DEFAULT 'pending',
    deleted_at          TIMESTAMP NULL DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status),
    INDEX idx_deleted_at  (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. extracted_data ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS extracted_data (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    document_id       INT NOT NULL,
    supplier_name     VARCHAR(150),
    document_date     DATE,
    total_amount      DECIMAL(12,2),
    tax_amount        DECIMAL(12,2),
    currency          VARCHAR(10) DEFAULT 'USD',
    category          VARCHAR(100) DEFAULT 'Other / Uncategorized',
    confidence_score  DECIMAL(5,2),
    is_approved       TINYINT(1) DEFAULT 0,
    approved_by       INT NULL,
    approved_at       TIMESTAMP NULL DEFAULT NULL,
    rejection_reason  VARCHAR(500) NULL,
    archived_at       TIMESTAMP NULL DEFAULT NULL,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_document_id     (document_id),
    INDEX idx_duplicate_check (supplier_name(100), document_date, total_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. line_items ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS line_items (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    extracted_data_id   INT NOT NULL,
    description         VARCHAR(500),
    quantity            DECIMAL(10,3),
    unit_price          DECIMAL(12,2),
    line_total          DECIMAL(12,2),
    sort_order          INT DEFAULT 0,
    FOREIGN KEY (extracted_data_id) REFERENCES extracted_data(id) ON DELETE CASCADE,
    INDEX idx_extracted_data_id (extracted_data_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. processing_queue ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS processing_queue (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    document_id   INT NOT NULL UNIQUE,
    status        ENUM('pending','processing','done','failed') DEFAULT 'pending',
    retry_count   TINYINT DEFAULT 0,
    last_error    TEXT NULL,
    claimed_at    TIMESTAMP NULL DEFAULT NULL,
    completed_at  TIMESTAMP NULL DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. processing_logs ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS processing_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    document_id     INT NOT NULL,
    attempt_number  TINYINT NOT NULL DEFAULT 1,
    ai_http_status  SMALLINT NULL,
    ai_response_ms  INT NULL,
    error_message   TEXT NULL,
    started_at      TIMESTAMP NOT NULL,
    finished_at     TIMESTAMP NULL,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_document_id (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. password_resets ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  TIMESTAMP NOT NULL,
    used_at     TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9. document_audit_logs ────────────────────────────────────
CREATE TABLE IF NOT EXISTS document_audit_logs (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    document_id    INT NOT NULL,
    action_type    ENUM('update','approve','archive','delete') NOT NULL,
    action_payload JSON NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_document_action_time (document_id, action_type, created_at),
    INDEX idx_user_time            (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 10. admin_audit_logs ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id   INT NOT NULL,
    target_user_id  INT NOT NULL,
    action_type     ENUM('activate_user','deactivate_user') NOT NULL,
    reason          VARCHAR(255) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_admin_time  (admin_user_id, created_at),
    INDEX idx_target_time (target_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 11. _schema_migrations ────────────────────────────────────
-- Tracks which SQL files have been applied and their checksum.
-- Used by bootstrap.php for idempotent re-runs.
CREATE TABLE IF NOT EXISTS _schema_migrations (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    filename     VARCHAR(255) NOT NULL UNIQUE,
    checksum     VARCHAR(64)  NOT NULL,
    applied_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
