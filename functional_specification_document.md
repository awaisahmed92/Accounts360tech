# Functional Specification Document (FSD)
## Project: Dext Clone (Web-Upload & Pre-Accounting Automation MVP)

| Field | Value |
| :--- | :--- |
| **Tech Stack** | Flutter Web (Frontend), PHP 8.2 Backend (API), MySQL 8.0 Database, Hostinger VPS (Ubuntu 22.04) |
| **OCR / AI Provider** | **Google Document AI** — Expense Parser processor (chosen for structured receipt/invoice parsing, pay-per-page pricing, and JSON output schema) |
| **File Storage** | **Local VPS filesystem** (`/var/app/storage/documents/`, outside web root) — S3-compatible migration path reserved for post-MVP scaling |
| **Async Queue** | **Database-backed polling queue** — PHP worker script polls a `processing_queue` table every 5 seconds; Flutter Web polls `GET /documents/{id}` every 3 seconds until status is `ready` or `error` |
| **Version** | 1.2.0 |
| **Last Updated** | September 21, 2026 |

---

## 1. Document Overview & Objective

This Functional Specification Document defines the complete requirements, user workflows, data structures, API contracts, security model, and development roadmap for the **MVP** of the Dext replica application. The system enables business owners and bookkeepers to upload financial documents (receipts and invoices) via a web browser, have their data extracted automatically by AI, review and correct the results, and manage a searchable transaction ledger.

**Scope of this MVP:**
- Web-based document upload (PDF, PNG, JPEG)
- Automated OCR/AI extraction via Google Document AI
- Human review, correction, and approval workflow
- Transaction ledger with search, filter, and CSV export
- JWT-authenticated REST API hosted on Hostinger VPS

**Out of scope for MVP:** Mobile app, bank feed integration, accounting software sync (QuickBooks/Xero), multi-tenant organisation management.

---

## 2. System Architecture & Component Mapping

```
┌─────────────────────────────────────────────────────────┐
│                  Flutter Web (Browser)                  │
│  Upload UI │ Review Dashboard │ Ledger Table │ Auth UI  │
└────────────────────┬────────────────────────────────────┘
                     │  HTTPS (Multipart POST / JSON / JWT)
                     ▼
┌─────────────────────────────────────────────────────────┐
│              Nginx Reverse Proxy (Port 443)             │
│         SSL Termination via Let's Encrypt               │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│              PHP 8.2 API Engine (/api/v1/)              │
│  Auth Module │ Upload Handler │ Document Controller     │
│  Export Controller │ Admin Controller                   │
└──────┬───────────────────────────┬──────────────────────┘
       │                           │
       ▼                           ▼
┌─────────────────┐     ┌──────────────────────────────┐
│   MySQL 8.0     │     │   PHP Background Worker      │
│  (Persistent    │◄────│   (polls processing_queue    │
│   Storage)      │     │    every 5 s via cron/CLI)   │
└─────────────────┘     └──────────────┬───────────────┘
                                       │  HTTPS POST
                                       ▼
                         ┌─────────────────────────────┐
                         │   Google Document AI API    │
                         │   (Expense Parser processor)│
                         └─────────────────────────────┘

File Storage: /var/app/storage/documents/{user_id}/{uuid}.{ext}
(served via token-authenticated PHP download route, never directly)
```

---

## 3. User Roles & Permissions

| Permission | Standard User (Business Owner) | Administrator |
| :--- | :---: | :---: |
| Register / Login | ✅ | ✅ |
| Upload documents | ✅ | ✅ |
| View own documents | ✅ | ✅ |
| Edit / correct extracted data | ✅ | ✅ |
| Approve / archive documents | ✅ | ✅ |
| Delete own documents | ✅ | ✅ |
| Export own data (CSV) | ✅ | ✅ |
| View **all** users' documents | ❌ | ✅ |
| Manage user accounts | ❌ | ✅ |
| View processing logs | ❌ | ✅ |
| Configure global settings | ❌ | ✅ |

---

## 4. Core Functional Modules & User Stories

### Module 1: Authentication & User Management

**User Story:** As a new user, I want to register, log in, and stay logged in securely so that my data is protected and accessible only to me.

**Functional Requirements:**
- Registration form: `name`, `email`, `password` (min 8 chars, 1 uppercase, 1 number).
- Server-side email uniqueness validation with a clear error response.
- Passwords stored as `bcrypt` hashes (cost factor 12).
- Login returns a signed **JWT** (HS256, 24-hour expiry) and sets a **refresh token cookie** (random 64-char hex persisted in `refresh_tokens` table, 30-day expiry).
- Flutter stores JWT in memory. The refresh token is stored in an **HttpOnly, Secure, SameSite=Strict cookie** set by the API (never exposed to JavaScript).
- Silent token refresh: Flutter calls `POST /auth/refresh` when a 401 is received; on success, replaces the in-memory JWT.
- Logout: Flutter calls `POST /auth/logout`, server deletes the refresh token row and expires the refresh cookie; Flutter clears the in-memory JWT.
- Password reset: `POST /auth/forgot-password` sends a time-limited (1-hour) reset link to the user's email.

---

### Module 2: Web Ingestion & Document Upload

**User Story:** As a user, I want to securely drag and drop multiple financial documents into the web dashboard so that they can be parsed automatically.

**Functional Requirements:**
- Drag-and-drop zone and standard OS file picker, both supported.
- Accepted formats: `.pdf`, `.png`, `.jpg`, `.jpeg`.
- Max file size: **20 MB** per file. Max **10 files** per batch upload request.
- Client-side validation for type and size **before** submission (no wasted network requests).
- Real-time upload progress bar per file (using `XMLHttpRequest.upload.onprogress`).
- On successful upload, the API returns a list of `document_id` values. Flutter immediately begins polling `GET /documents/{id}` every 3 seconds to reflect live status changes.
- If a file fails server-side validation, the UI displays the specific rejection reason (type, size, or scan failure) without blocking the rest of the batch.

---

### Module 3: Document Processing & AI Extraction Engine

**User Story:** As a system, I want to send uploaded files to Google Document AI and store the extracted transaction details automatically.

**Functional Requirements:**
- PHP upload handler saves the raw file to `/var/app/storage/documents/{user_id}/{uuid}.{ext}` and inserts a row into `documents` with `status = 'pending'`.
- A `processing_queue` row is inserted atomically with the document row (same DB transaction).
- A **PHP CLI worker** (`worker.php`), scheduled via cron every 5 seconds, polls `processing_queue` for `status = 'pending'` rows and claims work **atomically** (single transaction using row locking or equivalent atomic update), then calls the Google Document AI Expense Parser, writes results to `extracted_data` and `line_items`, and sets `documents.status = 'ready'` (or `'error'` on failure).
- Google Document AI Expense Parser extracts:

  | Field | Target Column |
  | :--- | :--- |
  | Supplier / Merchant Name | `extracted_data.supplier_name` |
  | Document Date | `extracted_data.document_date` |
  | Total Amount | `extracted_data.total_amount` |
  | Currency | `extracted_data.currency` |
  | Tax / VAT Amount | `extracted_data.tax_amount` |
  | Line Items (description, qty, unit price) | `line_items` table |
  | AI Confidence Score | `extracted_data.confidence_score` |

- On extraction error: `documents.status` is set to `'error'`, and the error message and HTTP status code from Document AI are written to `processing_logs`. The worker retries up to **3 times** with a 30-second back-off before marking as `'error'`.
- Document statuses: `pending` → `processing` → `ready` | `error`.

---

### Module 4: Review Queue & Verification Dashboard

**User Story:** As a bookkeeper, I want to review extracted data in a split-screen view, correct any misread values, and approve them for the ledger.

**Functional Requirements:**
- Split-screen layout: **left panel** shows the original document (PDF rendered via `pdf.js`, images displayed natively). **Right panel** shows editable form fields populated from `extracted_data`.
- Inline-editable fields: `supplier_name`, `document_date`, `total_amount`, `tax_amount`, `currency`, `category`.
- **Category** is selected from a predefined dropdown taxonomy (see Section 4.1 below).
- **Duplicate detection:** On loading the review panel, the API checks for an existing `extracted_data` row with the same `supplier_name` + `document_date` + `total_amount`. If found, a dismissible warning banner is shown with a link to the potential duplicate.
- Manual save (`PATCH /documents/{id}`) persists corrections without approving.
- **Approve** action (`POST /documents/{id}/approve`) sets `is_approved = TRUE` and `approved_by = {current_user_id}`.
- **Archive** action moves the document to an archived state (`documents.status = 'archived'`) without deleting.
- **Delete** action (`DELETE /documents/{id}`) performs a soft-delete (`deleted_at` timestamp) rather than a hard delete; records are retained for audit purposes.
- Line items table below the main form: read-only in MVP (manual editing of line items is post-MVP).

#### 4.1 Category Taxonomy (Fixed List — MVP)

| Category | Category |
| :--- | :--- |
| Meals & Entertainment | Office Supplies |
| Travel & Transport | Utilities |
| Software & Subscriptions | Professional Services |
| Advertising & Marketing | Equipment & Hardware |
| Rent & Facilities | Other / Uncategorized |

The AI worker will attempt to auto-assign a category based on the merchant name using a keyword-matching map. Users can override via the review panel dropdown.

---

### Module 5: Transaction Ledger & Data Management

**User Story:** As a user, I want to search, filter, sort, and export my processed receipts in a ledger-style table.

**Functional Requirements:**
- Master data table columns: **Date**, **Supplier**, **Category**, **Currency**, **Tax**, **Total**, **Status**, **Actions**.
- Client-side column sorting for all columns.
- Server-side search: `GET /documents?search={keyword}` matches against `supplier_name`.
- Server-side filters: `status`, `category`, `date_from`, `date_to`, `currency`.
- Pagination: 25 rows per page. Response includes `total_count`, `page`, `page_size`, `total_pages`.
- **CSV Export:** `GET /documents/export?{filter_params}` streams a UTF-8 CSV file with columns: Date, Supplier, Category, Currency, Tax Amount, Total Amount, Approved By, Approved At.

---

### Module 6: Admin Panel

**User Story:** As an administrator, I want to monitor system health, manage users, and inspect processing logs.

**Functional Requirements:**
- User list: view all registered users, their document counts, and account status.
- Deactivate / reactivate user accounts (soft-ban via `users.is_active` flag).
- Processing logs table: shows document ID, worker start/end time, retry count, AI response HTTP status, and error message (if any).
- System stats card: total documents processed today, error rate (%), average AI processing time (seconds).

---

## 5. Database Schema Design (MySQL 8.0)

### Table 1: `users`
```sql
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100)  NOT NULL,
    email           VARCHAR(150)  UNIQUE NOT NULL,
    password_hash   VARCHAR(255)  NOT NULL,
    role            ENUM('admin', 'user') DEFAULT 'user',
    is_active       BOOLEAN DEFAULT TRUE,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    failed_login_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    TIMESTAMP NULL DEFAULT NULL,
    last_login_at   TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Table 2: `refresh_tokens`
```sql
CREATE TABLE refresh_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token       VARCHAR(128) UNIQUE NOT NULL,
    expires_at  TIMESTAMP NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id)
);
```

### Table 3: `documents`
```sql
CREATE TABLE documents (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT NOT NULL,
    original_file_name VARCHAR(255) NOT NULL,
    stored_file_name  VARCHAR(255) NOT NULL,          -- UUID-based, e.g. a3f2...pdf
    file_path         VARCHAR(500) NOT NULL,           -- Absolute server path
    file_type         VARCHAR(50)  NOT NULL,           -- 'application/pdf', 'image/jpeg', etc.
    file_size         INT          NOT NULL,           -- Bytes
    status            ENUM('pending','processing','ready','error','archived') DEFAULT 'pending',
    deleted_at        TIMESTAMP NULL DEFAULT NULL,     -- Soft-delete
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status),
    INDEX idx_deleted_at (deleted_at)
);
```

### Table 4: `extracted_data`
```sql
CREATE TABLE extracted_data (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    document_id      INT  NOT NULL,
    supplier_name    VARCHAR(150),
    document_date    DATE,
    total_amount     DECIMAL(12,2),
    tax_amount       DECIMAL(12,2),
    currency         VARCHAR(10) DEFAULT 'USD',
    category         VARCHAR(100) DEFAULT 'Other / Uncategorized',
    confidence_score DECIMAL(5,2),                  -- 0.00–100.00 from Document AI
    is_approved      BOOLEAN DEFAULT FALSE,
    approved_by      INT NULL,                       -- FK to users.id
    approved_at      TIMESTAMP NULL DEFAULT NULL,
    rejection_reason VARCHAR(500) NULL,              -- If archived/rejected
    archived_at      TIMESTAMP NULL DEFAULT NULL,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_document_id (document_id),
    INDEX idx_duplicate_check (supplier_name, document_date, total_amount)
);
```

### Table 5: `line_items`
```sql
CREATE TABLE line_items (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    extracted_data_id INT NOT NULL,
    description      VARCHAR(500),
    quantity         DECIMAL(10,3),
    unit_price       DECIMAL(12,2),
    line_total       DECIMAL(12,2),
    sort_order       INT DEFAULT 0,
    FOREIGN KEY (extracted_data_id) REFERENCES extracted_data(id) ON DELETE CASCADE,
    INDEX idx_extracted_data_id (extracted_data_id)
);
```

### Table 6: `processing_queue`
```sql
CREATE TABLE processing_queue (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    document_id  INT NOT NULL UNIQUE,
    status       ENUM('pending','processing','done','failed') DEFAULT 'pending',
    retry_count  TINYINT DEFAULT 0,
    last_error   TEXT NULL,
    claimed_at   TIMESTAMP NULL DEFAULT NULL,       -- When worker picked it up
    completed_at TIMESTAMP NULL DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_status_created (status, created_at)
);
```

### Table 7: `processing_logs`
```sql
CREATE TABLE processing_logs (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    document_id    INT NOT NULL,
    attempt_number TINYINT NOT NULL DEFAULT 1,
    ai_http_status SMALLINT NULL,                  -- e.g. 200, 429, 500
    ai_response_ms INT NULL,                       -- Round-trip latency in ms
    error_message  TEXT NULL,
    started_at     TIMESTAMP NOT NULL,
    finished_at    TIMESTAMP NULL,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_document_id (document_id)
);
```

### Table 8: `document_audit_logs`
```sql
CREATE TABLE document_audit_logs (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    document_id    INT NOT NULL,
    action_type    ENUM('update','approve','archive','delete') NOT NULL,
    action_payload JSON NULL,                       -- Optional field-level diff snapshot
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_document_action_time (document_id, action_type, created_at),
    INDEX idx_user_time (user_id, created_at)
);
```

### Table 9: `admin_audit_logs`
```sql
CREATE TABLE admin_audit_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id   INT NOT NULL,
    target_user_id  INT NOT NULL,
    action_type     ENUM('activate_user','deactivate_user') NOT NULL,
    reason          VARCHAR(255) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_admin_time (admin_user_id, created_at),
    INDEX idx_target_time (target_user_id, created_at)
);
```

---

## 6. API Endpoints Specification (PHP Backend)

All responses follow a consistent JSON envelope:
```json
{ "success": true, "data": { ... } }
{ "success": false, "error": { "code": "VALIDATION_ERROR", "message": "...", "fields": { ... } } }
```

JWT must be sent as: `Authorization: Bearer <token>` on all protected routes.

### 6.1 Authentication

| Method | Endpoint | Auth | Description |
| :--- | :--- | :--- | :--- |
| POST | `/api/v1/auth/register` | Public | Register a new user |
| POST | `/api/v1/auth/login` | Public | Login and receive JWT + set refresh cookie |
| POST | `/api/v1/auth/refresh` | Public (cookie) | Exchange refresh cookie for a new JWT |
| POST | `/api/v1/auth/logout` | JWT + cookie | Invalidate the current refresh token |
| POST | `/api/v1/auth/forgot-password` | Public | Send password reset email |
| POST | `/api/v1/auth/reset-password` | Public | Reset password using token from email |

**POST `/api/v1/auth/register`**
```
Request:  { "name": "Jane Smith", "email": "jane@co.com", "password": "Secret123" }
Response: { "success": true, "data": { "user": { "id": 1, "name": "Jane Smith", "email": "...", "role": "user" } } }
```

**POST `/api/v1/auth/login`**
```
Request:  { "email": "jane@co.com", "password": "Secret123" }
Response: { "success": true, "data": {
    "token": "<jwt_24h>",
    "expires_in": 86400,
    "user": { "id": 1, "name": "Jane Smith", "role": "user" }
}}
Header:   Set-Cookie: refresh_token=<opaque>; HttpOnly; Secure; SameSite=Strict; Path=/api/v1/auth
```

**POST `/api/v1/auth/refresh`**
```
Request:  No JSON body required. Refresh token is read from HttpOnly cookie.
Response: { "success": true, "data": { "token": "<new_jwt_24h>", "expires_in": 86400 } }
```

---

### 6.2 Document Management

| Method | Endpoint | Auth | Description |
| :--- | :--- | :--- | :--- |
| POST | `/api/v1/documents/upload` | JWT | Upload 1–10 files (multipart) |
| GET | `/api/v1/documents` | JWT | List documents with filters & pagination |
| GET | `/api/v1/documents/{id}` | JWT | Fetch one document with extracted data & line items |
| PATCH | `/api/v1/documents/{id}` | JWT | Save corrections to extracted fields |
| POST | `/api/v1/documents/{id}/approve` | JWT | Approve a document |
| POST | `/api/v1/documents/{id}/archive` | JWT | Archive a document |
| DELETE | `/api/v1/documents/{id}` | JWT | Soft-delete a document |
| GET | `/api/v1/documents/{id}/download` | JWT | Stream the original file securely |
| GET | `/api/v1/documents/export` | JWT | Export filtered results as CSV |

**POST `/api/v1/documents/upload`**
```
Request:  multipart/form-data — field name: files[] (max 10 files, 20 MB each)
Response: { "success": true, "data": {
    "uploaded": 2,
    "documents": [
        { "id": 42, "file_name": "receipt_jan.pdf", "status": "pending" },
        { "id": 43, "file_name": "invoice_feb.jpg", "status": "pending" }
    ]
}}
```

**GET `/api/v1/documents`**
```
Query Params:
  page        (int, default 1)
  per_page    (int, default 25, max 100)
  status      (pending|processing|ready|error|archived)
  category    (string)
  search      (string — matches supplier_name)
  date_from   (YYYY-MM-DD)
  date_to     (YYYY-MM-DD)
  currency    (string)
  sort_by     (created_at|document_date|total_amount|supplier_name, default: created_at)
  sort_dir    (asc|desc, default: desc)

Response: { "success": true, "data": {
    "documents": [ { "id": 42, "file_name": "...", "status": "ready",
                     "supplier_name": "Starbucks", "document_date": "2026-09-10",
                     "total_amount": "12.50", "currency": "USD", "category": "Meals & Entertainment",
                     "is_approved": false, "created_at": "2026-09-20T14:00:00Z" }, ... ],
    "pagination": { "total_count": 134, "page": 1, "per_page": 25, "total_pages": 6 }
}}
```

**GET `/api/v1/documents/{id}`**
```
Response: { "success": true, "data": {
    "document": { "id": 42, "file_name": "receipt_jan.pdf", "status": "ready",
                  "download_url": "/api/v1/documents/42/download", "created_at": "..." },
    "extracted": { "supplier_name": "Starbucks", "document_date": "2026-09-10",
                   "total_amount": "12.50", "tax_amount": "1.50", "currency": "USD",
                   "category": "Meals & Entertainment", "confidence_score": "94.20",
                   "is_approved": false, "approved_by": null, "approved_at": null },
    "line_items": [
        { "id": 1, "description": "Latte", "quantity": "2.000", "unit_price": "5.25", "line_total": "10.50" },
        { "id": 2, "description": "Muffin", "quantity": "1.000", "unit_price": "2.00", "line_total": "2.00" }
    ],
    "duplicate_warning": null
}}
```

**PATCH `/api/v1/documents/{id}`**
```
Request:  { "supplier_name": "Starbucks Corp", "total_amount": "12.50", "category": "Meals & Entertainment" }
Response: { "success": true, "data": { "message": "Document updated." } }
```

**POST `/api/v1/documents/{id}/approve`**
```
Response: { "success": true, "data": { "message": "Document approved.", "approved_at": "2026-09-20T15:00:00Z" } }
```

**GET `/api/v1/documents/{id}/download`**
```
Validates JWT and document ownership.
Returns: File stream with Content-Type and Content-Disposition headers.
Error:    { "success": false, "error": { "code": "FORBIDDEN", "message": "Access denied." } }
```

**GET `/api/v1/documents/export`**
```
Accepts the same filter query params as GET /documents (no pagination).
Returns: text/csv stream — attachment filename: documents_export_YYYYMMDD.csv
Columns: Date, Supplier, Category, Currency, Tax Amount, Total Amount, Status, Approved By, Approved At
```

---

### 6.3 Admin Endpoints

| Method | Endpoint | Auth | Description |
| :--- | :--- | :--- | :--- |
| GET | `/api/v1/admin/users` | JWT (admin) | List all users |
| PATCH | `/api/v1/admin/users/{id}` | JWT (admin) | Activate / deactivate a user |
| GET | `/api/v1/admin/logs` | JWT (admin) | View processing logs |
| GET | `/api/v1/admin/stats` | JWT (admin) | System health stats |

---

## 7. Non-Functional Requirements & Security

### 7.1 Transport & Encryption
- Enforce **HTTPS** via Let's Encrypt SSL on Nginx. All HTTP requests redirect to HTTPS (301).
- JWT signed with HS256 using a 256-bit secret stored in a server-side `.env` file (never in version control).

### 7.2 File Storage Security
- Raw files stored at `/var/app/storage/documents/{user_id}/{uuid}.{ext}` — completely outside the Nginx web root.
- Files are **never served directly** by Nginx. All downloads go through `GET /documents/{id}/download`, which validates the JWT, checks document ownership, and streams the file via `readfile()`.
- Uploaded filenames are replaced with a UUID; the original filename is stored in `documents.original_file_name` only for display purposes.
- Files are scanned via `finfo_file()` MIME detection. Any file whose detected MIME type does not match the allowed list (`application/pdf`, `image/jpeg`, `image/png`) is rejected.

### 7.3 Input Sanitization & Injection Prevention
- All database queries use **PDO prepared statements**. No raw string interpolation in SQL.
- All user-supplied string inputs are trimmed and validated server-side (length, type, allowed characters).
- JSON responses use `json_encode()` with `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP` flags to prevent XSS via JSON.

### 7.4 Rate Limiting
- `POST /auth/login` and `POST /auth/register`: **10 requests per minute per IP** — enforced at the Nginx level (`limit_req_zone`).
- `POST /documents/upload`: **5 requests per minute per authenticated user**.
- `GET /documents/export`: **2 requests per minute per authenticated user**.

### 7.5 CSRF & Request Validation
- All state-changing API endpoints (`POST`, `PATCH`, `DELETE`) require a valid JWT in the `Authorization` header.
- Refresh-token cookie endpoints (`POST /auth/refresh`, `POST /auth/logout`) require CSRF protection via one of: `Origin`/`Referer` validation with strict allowlist, or a double-submit CSRF token header.
- Content-Type header is validated for JSON endpoints; multipart endpoints check `$_FILES` integrity.

### 7.6 Audit Logging
- `processing_logs` is reserved for AI worker processing events only (attempts, latency, provider status, errors).
- All `PATCH`, `POST /approve`, `POST /archive`, and `DELETE` actions by any user are logged to `document_audit_logs` with `user_id`, `document_id`, action type, optional payload, and timestamp.
- Admin account management actions (activate/deactivate) are logged to `admin_audit_logs`.

### 7.7 Password & Account Security
- Passwords hashed with `password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12])`.
- Password reset tokens are single-use, 64-char random hex, expire after **1 hour**, stored hashed in the DB.
- Accounts are locked after **10 failed consecutive login attempts** within 15 minutes (tracked in `users.failed_login_count` and `users.locked_until`).

---

## 8. Async Processing Pipeline — Detail

```
[Upload Handler]
     │
     ├─ 1. Validate & store file  →  /var/app/storage/...
     ├─ 2. INSERT documents (status='pending')
     └─ 3. INSERT processing_queue (status='pending')
                    │
                    │  (cron every 5s)
                    ▼
          [PHP worker.php]
                    │
          ┌─────────▼──────────────────────────────────────┐
          │  ATOMIC CLAIM                                  │
          │  BEGIN TX                                      │
          │  SELECT ... FOR UPDATE SKIP LOCKED            │
          │  UPDATE status='processing', claimed_at=NOW() │
          │  COMMIT                                        │
          └─────────┬──────────────────────────────────────┘
                    │
          ┌─────────▼────────────────────┐
          │  POST to Google Document AI  │  (Expense Parser processor)
          └─────────┬────────────────────┘
                    │
          ┌─────────▼─────────┐
          │  Parse JSON        │  Map fields → extracted_data + line_items
          │  response          │  INSERT / UPDATE rows
          └─────────┬─────────┘
                    │
          ┌─────────▼──────────────────┐
          │  Update documents.status   │  'ready' on success, 'error' after 3 retries
          │  Update processing_queue   │  'done' or 'failed'
          │  INSERT processing_logs    │  Latency, HTTP status, error if any
          └────────────────────────────┘

Flutter Web polls GET /documents/{id} every 3 seconds until status ≠ 'pending'/'processing'.
```

---

## 9. Development Roadmap & Next Steps

| Sprint | Tasks |
| :--- | :--- |
| **Sprint 1 — Foundation** | VPS setup, MySQL schema migration (all 9 tables), Nginx + SSL config, PHP project scaffold with PDO connection, `.env` config file |
| **Sprint 2 — Auth** | `POST /auth/register`, `POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`, JWT middleware, rate limiting rules |
| **Sprint 3 — Upload & Storage** | `POST /documents/upload` handler, file validation, UUID renaming, storage path, `processing_queue` insert, Flutter upload UI with progress bar |
| **Sprint 4 — AI Worker** | `worker.php` CLI script, Google Document AI integration, `extracted_data` + `line_items` insert, retry logic, `processing_logs` write, cron job setup |
| **Sprint 5 — Review Dashboard** | `GET /documents/{id}`, Flutter split-screen review UI, `PATCH /documents/{id}`, `POST /approve`, `POST /archive`, `DELETE`, duplicate detection banner |
| **Sprint 6 — Ledger & Export** | `GET /documents` with full filter/sort/pagination, Flutter ledger table with client-side sorting, `GET /documents/export` CSV stream |
| **Sprint 7 — Admin & Security** | Admin endpoints, `GET /documents/{id}/download` secure stream, account lockout, audit logging, password reset flow |
| **Sprint 8 — QA & Launch** | End-to-end testing, performance testing (upload load, AI latency), SSL/security audit, production deployment checklist |
