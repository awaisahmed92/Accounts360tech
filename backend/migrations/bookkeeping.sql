-- ============================================================
-- Accounts360tech — Bookkeeping Module Migration
-- Run after schema.sql:
--   mysql -u root -p accounts360tech < bookkeeping.sql
-- ============================================================

USE accounts360tech;

-- ── 1. accounts (Chart of Accounts) ─────────────────────────
CREATE TABLE IF NOT EXISTS accounts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NULL,                          -- NULL = system/shared default
    code        VARCHAR(20)  NOT NULL,
    name        VARCHAR(150) NOT NULL,
    type        ENUM('Asset','Liability','Equity','Income','Expense') NOT NULL,
    sub_type    VARCHAR(100) NULL,
    description VARCHAR(500) NULL,
    parent_id   INT NULL,
    is_active   TINYINT(1) DEFAULT 1,
    is_system   TINYINT(1) DEFAULT 0,              -- system accounts cannot be deleted
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES accounts(id) ON DELETE SET NULL,
    INDEX idx_user_type (user_id, type),
    INDEX idx_code      (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. journal_entries ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS journal_entries (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    document_id INT NULL,                          -- linked approved document (optional)
    entry_date  DATE NOT NULL,
    reference   VARCHAR(100) NULL,
    description VARCHAR(500) NULL,
    status      ENUM('draft','posted') DEFAULT 'draft',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
    INDEX idx_user_date   (user_id, entry_date),
    INDEX idx_document_id (document_id),
    INDEX idx_status      (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. journal_lines ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS journal_lines (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id INT NOT NULL,
    account_id       INT NOT NULL,
    description      VARCHAR(500) NULL,
    debit            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    credit           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency         VARCHAR(10) DEFAULT 'USD',
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id)       REFERENCES accounts(id)        ON DELETE RESTRICT,
    INDEX idx_journal_entry_id (journal_entry_id),
    INDEX idx_account_id       (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. bank_accounts ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bank_accounts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    bank_name       VARCHAR(150) NULL,
    account_number  VARCHAR(50)  NULL,
    currency        VARCHAR(10)  DEFAULT 'USD',
    opening_balance DECIMAL(12,2) DEFAULT 0.00,
    current_balance DECIMAL(12,2) DEFAULT 0.00,
    is_active       TINYINT(1)   DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. bank_transactions ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS bank_transactions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id  INT NOT NULL,
    txn_date         DATE NOT NULL,
    description      VARCHAR(500) NOT NULL,
    amount           DECIMAL(12,2) NOT NULL,       -- positive = money in, negative = money out
    type             ENUM('credit','debit') NOT NULL,
    reference        VARCHAR(100) NULL,
    is_reconciled    TINYINT(1) DEFAULT 0,
    journal_line_id  INT NULL,                     -- linked journal line after reconciliation
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (journal_line_id) REFERENCES journal_lines(id) ON DELETE SET NULL,
    INDEX idx_bank_account_id (bank_account_id),
    INDEX idx_txn_date        (txn_date),
    INDEX idx_is_reconciled   (is_reconciled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════
-- DEFAULT CHART OF ACCOUNTS SEED (shared, user_id = NULL)
-- These are available to all users. Users may add their own.
-- ════════════════════════════════════════════════════════════

INSERT IGNORE INTO accounts (user_id, code, name, type, sub_type, is_system) VALUES
-- ── Assets ─────────────────────────────────────────────────
(NULL, '1001', 'Cash on Hand',         'Asset', 'Current Asset', 1),
(NULL, '1002', 'Bank Account',         'Asset', 'Current Asset', 1),
(NULL, '1100', 'Accounts Receivable',  'Asset', 'Current Asset', 1),
(NULL, '1200', 'Prepaid Expenses',     'Asset', 'Current Asset', 0),
(NULL, '1500', 'Equipment',            'Asset', 'Fixed Asset',   0),

-- ── Liabilities ─────────────────────────────────────────────
(NULL, '2001', 'Accounts Payable',     'Liability', 'Current Liability', 1),
(NULL, '2100', 'Credit Card Payable',  'Liability', 'Current Liability', 0),
(NULL, '2200', 'Sales Tax Payable',    'Liability', 'Current Liability', 0),
(NULL, '2500', 'Loans Payable',        'Liability', 'Long-term Liability', 0),

-- ── Equity ──────────────────────────────────────────────────
(NULL, '3001', 'Owner Equity',         'Equity', 'Equity', 1),
(NULL, '3002', 'Retained Earnings',    'Equity', 'Equity', 1),
(NULL, '3100', 'Owner Drawings',       'Equity', 'Equity', 0),

-- ── Income ──────────────────────────────────────────────────
(NULL, '4001', 'Sales Revenue',        'Income', 'Operating Revenue', 1),
(NULL, '4002', 'Service Revenue',      'Income', 'Operating Revenue', 0),
(NULL, '4900', 'Other Income',         'Income', 'Other Income',      0),

-- ── Expenses — one per expense category ─────────────────────
(NULL, '5001', 'Meals & Entertainment',    'Expense', 'Operating Expense', 1),
(NULL, '5002', 'Travel & Transport',       'Expense', 'Operating Expense', 1),
(NULL, '5003', 'Software & Subscriptions', 'Expense', 'Operating Expense', 1),
(NULL, '5004', 'Advertising & Marketing',  'Expense', 'Operating Expense', 0),
(NULL, '5005', 'Office Supplies',          'Expense', 'Operating Expense', 1),
(NULL, '5006', 'Utilities',                'Expense', 'Operating Expense', 0),
(NULL, '5007', 'Professional Services',    'Expense', 'Operating Expense', 0),
(NULL, '5008', 'Equipment & Hardware',     'Expense', 'Operating Expense', 0),
(NULL, '5009', 'Rent & Facilities',        'Expense', 'Operating Expense', 0),
(NULL, '5010', 'Other Expenses',           'Expense', 'Operating Expense', 1);
