# Accounts360tech — Project Plan Document

| Field | Value |
|---|---|
| **Project** | Accounts360tech (Dext Clone MVP + Bookkeeping Extension) |
| **Version** | 2.0.0 |
| **Last Updated** | September 26, 2026 |
| **Tech Stack** | Vanilla JS SPA · PHP 8.2 REST API · MySQL 8.0 · Hostinger VPS (Ubuntu 22.04) |
| **AI Provider** | Google Document AI — Expense Parser |
| **Deployment** | Docker + Nginx reverse proxy + Let's Encrypt SSL |

---

## 1. Project Overview

Accounts360tech is a pre-accounting automation platform ("Dext Clone") that lets business owners and bookkeepers:

1. **Upload** financial documents (receipts / invoices — PDF, PNG, JPEG)
2. **Auto-extract** data via Google Document AI (Expense Parser)
3. **Review & approve** extracted fields in a split-screen dashboard
4. **Post journal entries** against a double-entry Chart of Accounts
5. **Reconcile** bank transactions and generate P&L / Balance Sheet reports
6. **Export** a searchable transaction ledger as CSV

---

## 2. System Architecture

```
                 ┌──────────────────────────────────────┐
                 │   Vanilla JS SPA (Browser)           │
                 │   auth · upload · review · ledger    │
                 │   accounts · journal · bank · reports│
                 └─────────────────┬────────────────────┘
                                   │ HTTPS / JWT
                                   ▼
                 ┌──────────────────────────────────────┐
                 │   Nginx Reverse Proxy (Port 443)     │
                 │   SSL via Let's Encrypt              │
                 └─────────────────┬────────────────────┘
                                   │
                                   ▼
                 ┌──────────────────────────────────────┐
                 │   PHP 8.2 API  /api/v1/              │
                 │   TenantMiddleware (resolves org)    │
                 │   AuthMiddleware  (validates JWT)    │
                 │   Controllers → Services             │
                 └──────┬──────────────────┬───────────┘
                        │                  │
               ┌────────▼────┐    ┌────────▼──────────┐
               │ hr360_master│    │ accounts360_<sub>  │
               │ (shared)    │    │ (per-org tenant DB)│
               └─────────────┘    └────────────────────┘
                                           │
                              ┌────────────▼───────────┐
                              │  PHP Worker (worker.php)│
                              │  polls processing_queue │
                              └────────────┬───────────┘
                                           │ HTTPS
                                           ▼
                              ┌────────────────────────┐
                              │  Google Document AI    │
                              │  Expense Parser        │
                              └────────────────────────┘
```

---

## 3. Master / Tenant Database Architecture

### Rule: One master DB for the entire 360tech platform

`hr360_master` is the **shared tenant registry** owned by HR360techx.  
All 360tech applications plug into it — no app creates its own master DB.

```
hr360_master                    (shared, managed by HR360techx)
└── tenants                     one row per client organization
    ├── hr_app       = 1/0      HR360techx enabled?
    ├── accounts_app = 1/0      Accounts360tech enabled?
    ├── pos_app      = 1/0      Pos360tech enabled? (future)
    ├── school_app   = 1/0      School360tech enabled? (future)
    ├── db_name                 HR tenant DB name
    ├── accounts_db_name        Accounts tenant DB name
    ├── pos_db_name             POS tenant DB name (future)
    └── school_db_name          School tenant DB name (future)

Per-app tenant databases:
  hr360_demo001          HR360techx tenant (subdomain: demo001)
  accounts360_demo001    Accounts360tech tenant (subdomain: demo001)
```

### Patch File

`HR360techx/database/31_multi_app_registry.sql` extends `hr360_master.tenants`  
with the per-app flag and DB name columns. Run this once after `01_master.sql`.

### Production DB Mapping (Hostinger)

| Purpose | DB Name | Location |
|---|---|---|
| Shared master | `hr360_master` | `tenants.id` |
| Accounts first tenant | `u819151619_demo001` | `tenants.accounts_db_name` |

---

## 4. Database Schema — Full Reference

### Master DB (`hr360_master`) — 2 tables

| Table | Purpose |
|---|---|
| `tenants` | Org registry: subdomain, per-app flags, per-app DB names, contact info |
| `tenant_admins` | Org owner cross-reference (shared across all 360tech apps) |

### Tenant DB (`accounts360_<subdomain>`) — 16 tables per org

**Core** — `migrations/tenant/01_schema.sql`

| Table | Purpose |
|---|---|
| `users` | Auth, roles (`admin`/`user`), lockout fields |
| `refresh_tokens` | HttpOnly secure cookie tokens (30-day expiry) |
| `documents` | File metadata, status lifecycle (`pending→processing→ready\|error\|archived`) |
| `extracted_data` | AI-parsed fields, approval state, duplicate-check composite index |
| `line_items` | Receipt line items (read-only in MVP) |
| `processing_queue` | Async AI worker queue with row-locking atomic claim |
| `processing_logs` | AI call audit (attempts, latency, HTTP status, error) |
| `password_resets` | Single-use reset tokens (1-hour expiry, stored hashed) |
| `document_audit_logs` | User action trail (update / approve / archive / delete) |
| `admin_audit_logs` | Admin account management trail |
| `_schema_migrations` | Bootstrap checksum registry (idempotent re-run guard) |

**Bookkeeping Extension** — `migrations/tenant/02_bookkeeping.sql`

| Table | Purpose |
|---|---|
| `accounts` | Chart of Accounts — hierarchical, 25 system accounts seeded |
| `journal_entries` | Double-entry header (draft / posted), optionally linked to document |
| `journal_lines` | Debit/credit lines linked to CoA accounts |
| `bank_accounts` | Tenant bank accounts with opening/current balance |
| `bank_transactions` | Imported transactions, reconciliation flag, linked journal line |

---

## 5. Migration & Bootstrap

### Migrations folder structure

```
backend/migrations/
  tenant/
    01_schema.sql        11 core tables + _schema_migrations
    02_bookkeeping.sql   5 bookkeeping tables + 25 CoA account seed
```

The `{{TENANT_DB}}` placeholder in each file is replaced at runtime by
`bootstrap.php` and `TenantController` with the actual MySQL database name.

### Bootstrap installer

```powershell
# Local: provision all active Accounts tenants from hr360_master
php Accounts360tech/backend/bootstrap.php

# Verify only (never writes)
php backend/bootstrap.php --check

# Target one tenant
php backend/bootstrap.php --tenant=demo001

# Provision a brand-new DB without master lookup
php backend/bootstrap.php --provision=accounts360_acme
```

---

## 6. Backend Component Map

```
backend/
  bootstrap.php                   Schema installer (CLI)
  public/index.php                Router + entry point
  src/
    Config/
      Config.php                  .env loader
      Database.php                connectMaster() · connectTenant() · connect()
    Middleware/
      TenantMiddleware.php        Resolves org from hr360_master per request
      AuthMiddleware.php          Validates JWT, restores tenant context
    Controllers/
      TenantController.php        POST /tenants/register · GET /tenants/check-subdomain
      AuthController.php          register · login · refresh · logout · forgot/reset
      DocumentController.php      upload · list · get · patch · approve · archive · delete · download · export
      AdminController.php         users · updateUser · logs · stats
      AccountController.php       Chart of Accounts CRUD
      JournalController.php       Journal entries CRUD + post
      BankController.php          Bank accounts · transactions · import · reconcile
      ReportController.php        profit-loss · balance-sheet · trial-balance
    Services/
      JWTService.php              generate (embeds tenant_id + tenant_db) · verify · generateRefreshToken
      DocumentAIService.php       Google Document AI Expense Parser integration
      FileService.php             Upload validation, UUID rename, secure storage
      ExportService.php           CSV stream builder
      JournalService.php          Double-entry balance validation
    Helpers/
      Response.php                JSON envelope helper
      Validator.php               Server-side input validation
  migrations/
    tenant/
      01_schema.sql
      02_bookkeeping.sql
  worker/
    worker.php                    Background AI processing loop (mock mode for dev)
```

---

## 7. JWT Payload (Updated)

```json
{
  "sub":       7,
  "role":      "admin",
  "name":      "Jane Smith",
  "tenant_id": 1,
  "tenant_db": "accounts360_demo001",
  "iat":       1790000000,
  "exp":       1790086400
}
```

`AuthMiddleware` restores `tenant_id` and `tenant_db` into `$_SERVER` on every  
authenticated request so `Database::connect()` routes to the correct tenant DB.

---

## 8. Request Flow

```
Browser
  │  POST /api/v1/auth/login
  │  X-Tenant-Subdomain: demo001        (or subdomain from Host header)
  ▼
TenantMiddleware::resolve()
  │  SELECT accounts_db_name FROM hr360_master.tenants WHERE subdomain='demo001'
  │  → $_SERVER['ACCOUNTS_TENANT_DB'] = 'accounts360_demo001'
  ▼
AuthController::login()
  │  Database::connect() → accounts360_demo001
  │  JWTService::generate([..., tenant_id=1, tenant_db='accounts360_demo001'])
  ▼
Client stores JWT (in-memory)

Subsequent requests:
  AuthMiddleware::handle()
  │  Verifies JWT → restores $_SERVER['ACCOUNTS_TENANT_DB']
  ▼
Any Controller → Database::connect() → accounts360_demo001
```

---

## 9. Full API Endpoint Map (27 endpoints)

### Tenant (2, public)
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/v1/tenants/register` | Provision org in `hr360_master` + create tenant DB |
| GET | `/api/v1/tenants/check-subdomain?s=demo001` | Subdomain availability check |

### Auth (6)
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/auth/register` | — | Create user in tenant DB |
| POST | `/api/v1/auth/login` | — | Login → JWT + refresh token |
| POST | `/api/v1/auth/refresh` | — | Renew JWT |
| POST | `/api/v1/auth/logout` | JWT | Invalidate refresh token |
| POST | `/api/v1/auth/forgot-password` | — | Send reset email |
| POST | `/api/v1/auth/reset-password` | — | Reset password via token |

### Documents (9)
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/documents/upload` | JWT | Upload 1–10 files (multipart) |
| GET | `/api/v1/documents` | JWT | List with filters & pagination |
| GET | `/api/v1/documents/{id}` | JWT | Document + extracted data + line items |
| PATCH | `/api/v1/documents/{id}` | JWT | Save corrections |
| POST | `/api/v1/documents/{id}/approve` | JWT | Approve document |
| POST | `/api/v1/documents/{id}/archive` | JWT | Archive document |
| DELETE | `/api/v1/documents/{id}` | JWT | Soft-delete |
| GET | `/api/v1/documents/{id}/download` | JWT | Secure file stream |
| GET | `/api/v1/documents/export` | JWT | CSV export |

### Admin (4)
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/admin/users` | JWT (admin) | All users in tenant |
| PATCH | `/api/v1/admin/users/{id}` | JWT (admin) | Toggle active/role |
| GET | `/api/v1/admin/logs` | JWT (admin) | Processing logs |
| GET | `/api/v1/admin/stats` | JWT (admin) | System stats |

### Bookkeeping (6 route groups)
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET/POST | `/api/v1/accounts` | JWT | Chart of Accounts list / create |
| PATCH/DELETE | `/api/v1/accounts/{id}` | JWT | Update / delete account |
| GET/POST | `/api/v1/journal` | JWT | Journal entries list / create |
| GET/PATCH/DELETE | `/api/v1/journal/{id}` | JWT | Journal entry detail / update / delete |
| POST | `/api/v1/journal/{id}/post` | JWT | Post draft entry to ledger |
| GET/POST | `/api/v1/banks` | JWT | Bank accounts list / create |
| GET | `/api/v1/banks/{id}/transactions` | JWT | Transaction list |
| POST | `/api/v1/banks/{id}/import` | JWT | Import CSV transactions |
| POST | `/api/v1/banks/{id}/reconcile` | JWT | Reconcile transaction → journal line |
| GET | `/api/v1/reports/profit-loss` | JWT | P&L report |
| GET | `/api/v1/reports/balance-sheet` | JWT | Balance sheet |
| GET | `/api/v1/reports/trial-balance` | JWT | Trial balance |

---

## 10. Schema Dump Utility

`schema_dump.php` (Desktop) — extracts column metadata via `information_schema.COLUMNS`.

```powershell
# Dump the shared master
php schema_dump.php --db=hr360_master > master_schema.tsv

# Dump local tenant DB
php schema_dump.php --db=accounts360_demo001 > local_tenant.tsv

# Dump production tenant DB (Hostinger)
php schema_dump.php --db=u819151619_demo001 > prod_tenant.tsv

# Diff local vs production to find schema drift
diff local_tenant.tsv prod_tenant.tsv
```

Output columns: `TABLE_NAME · COLUMN_NAME · COLUMN_TYPE · IS_NULLABLE · COLUMN_KEY · COLUMN_DEFAULT · EXTRA`

---

## 11. Module Inventory

| Module | Controller | Frontend JS | Status |
|---|---|---|---|
| Tenant Registration | TenantController.php | — | Done |
| Auth | AuthController.php (194L) | auth.js | Done |
| Document Upload | DocumentController.php (329L) | upload.js | Done |
| Review Dashboard | DocumentController.php | review.js | Done |
| Transaction Ledger | DocumentController.php | ledger.js | Done |
| Admin Panel | AdminController.php (114L) | admin.js | Done |
| Chart of Accounts | AccountController.php (146L) | accounts.js | Done |
| Journal Entries | JournalController.php (228L) | journal.js | Done |
| Bank Reconciliation | BankController.php (238L) | bank.js | Done |
| Financial Reports | ReportController.php (199L) | reports.js | Done |
| AI Worker | worker/worker.php | — | Done (mock mode for dev) |
| Tenant Middleware | TenantMiddleware.php | — | Done |
| Multi-app Master Patch | 31_multi_app_registry.sql (HR repo) | — | Done |

---

## 12. Sprint Delivery Status

| Sprint | Focus | Status |
|---|---|---|
| S1 — Foundation | VPS, Nginx, PHP scaffold, MySQL | Done |
| S2 — Auth | JWT, refresh tokens, rate limiting | Done |
| S3 — Upload & Storage | File handler, queue, Vanilla JS SPA | Done |
| S4 — AI Worker | worker.php, Document AI, retry logic | Done |
| S5 — Review Dashboard | Split-screen, approve, archive | Done |
| S6 — Ledger & Export | Filter, sort, pagination, CSV | Done |
| S7 — Admin & Security | Admin panel, audit logs, lockout | Done |
| S8 — Bookkeeping Extension | Accounts, Journal, Bank, Reports | Done |
| **S9 — Shared Master Integration** | `hr360_master`, TenantMiddleware, JWT, bootstrap | **Done** |
| S10 — Schema Sync & QA | `schema_dump.php` audit, E2E test, security review | Pending |
| S11 — Production Deploy | Docker, subdomain routing, Hostinger | Pending |

---

## 13. Remaining Work

### S10 — Schema Sync & QA
- Run `php backend/bootstrap.php --check` to verify local tenant DB completeness
- Run `schema_dump.php --db=u819151619_demo001` to audit production schema drift
- Configure real Google Document AI credentials in production `.env`
- Set up Windows Task Scheduler / cron for `worker.php` on VPS
- End-to-end test: register org → upload → AI extract → review → approve → journal → P&L
- SSL/security audit (rate limiting, CORS, Content-Security-Policy headers)

### S11 — Production Deploy
- Ensure `hr360_master` exists on Hostinger; run `01_master.sql` + `31_multi_app_registry.sql`
- Register `u819151619_demo001` as first Accounts tenant (`accounts_app=1`)
- Run `php bootstrap.php` inside container to provision tenant DB
- Configure Nginx subdomain routing for `*.accounts360.tech`
- Set `TENANT_SUBDOMAIN=demo001` in `.env` for single-subdomain Hostinger deployments
- Smoke test all 27 endpoints against production

---

## 14. Local Development Quick Start

```powershell
# 1. Bootstrap master (HR project owns this)
mysql -u root < HR360techx/database/01_master.sql
mysql -u root hr360_master < HR360techx/database/31_multi_app_registry.sql

# 2. Provision the Accounts tenant DB
php Accounts360tech/backend/bootstrap.php

# 3. Configure .env
#    Set: MASTER_DB=hr360_master
#    Leave DOCAI_* blank → mock mode (all features work)

# 4. Enable Apache mod_rewrite + AllowOverride All

# 5. Open the app
#    http://localhost/Accounts360tech/frontend/

# 6. Login with seeded admin
#    Email:    admin@accounts360.tech
#    Password: Admin@1234

# 7. Run the AI worker (keeps polling in background)
php Accounts360tech/backend/worker/worker.php
```

---

## 15. Environment Variables Reference

| Variable | Default | Purpose |
|---|---|---|
| `MASTER_DB` | `hr360_master` | Shared master DB name |
| `MASTER_HOST` | `DB_HOST` | Master DB host (fallback to DB_HOST) |
| `MASTER_USER` | `DB_USER` | Master DB user |
| `MASTER_PASS` | `DB_PASS` | Master DB password |
| `DB_HOST` | `127.0.0.1` | Tenant DB host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `accounts360tech` | Fallback tenant DB for local dev |
| `DB_USER` | `root` | Tenant DB user |
| `DB_PASS` | `` | Tenant DB password |
| `TENANT_SUBDOMAIN` | `` | Override for single-subdomain deployments |
| `JWT_SECRET` | — | HS256 signing secret (min 32 chars) |
| `JWT_EXPIRY` | `86400` | JWT lifetime in seconds (24 h) |
| `REFRESH_TOKEN_EXPIRY` | `2592000` | Refresh token lifetime (30 days) |
| `STORAGE_PATH` | — | Absolute path to file storage (outside web root) |
| `DOCAI_PROJECT_ID` | — | GCP project ID (blank = mock mode) |
| `DOCAI_PROCESSOR_ID` | — | Document AI processor ID |
| `DOCAI_LOCATION` | `us` | Document AI region |
| `DOCAI_API_KEY` | — | Path to service account JSON key file |
