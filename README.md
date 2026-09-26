# Accounts360tech — Dext Clone MVP

Pre-accounting automation: upload receipts & invoices → AI extracts data → review & approve → bookkeeping → export.

> **Full project plan:** See [`PROJECT_PLAN.md`](PROJECT_PLAN.md) for architecture, DB schema, API map, and sprint status.

---

## Architecture Overview

```
hr360_master (shared)          accounts360_demo001 (per-org tenant DB)
└── tenants                    └── 16 tables: users, documents, accounts,
    accounts_app = 1                journal_entries, bank_accounts, ...
    accounts_db_name = "..."
```

`hr360_master` is the **shared registry** for all 360tech applications.  
Accounts360tech reads from it to resolve which tenant DB to use per request.

---

## Quick Start (WAMP / Local)

### 1. Bootstrap the master (HR project owns this)

```powershell
# Create hr360_master and seed it
mysql -u root < ..\HR360techx\database\01_master.sql

# Extend tenants table for multi-app support
mysql -u root hr360_master < ..\HR360techx\database\31_multi_app_registry.sql
```

### 2. Provision the Accounts tenant database

```powershell
php backend\bootstrap.php
# Creates accounts360_demo001, applies all migrations, seeds Chart of Accounts
```

**Options:**

```powershell
php backend\bootstrap.php --check           # verify schema only, never write
php backend\bootstrap.php --tenant=demo001  # target one subdomain
php backend\bootstrap.php --force           # re-apply every migration file
```

### 3. Configure environment

Edit `backend/.env`:

```env
# ── Master DB (shared across all 360tech projects) ─────────────────────────
MASTER_DB=hr360_master
MASTER_HOST=127.0.0.1
MASTER_USER=root
MASTER_PASS=

# ── Tenant DB (fallback for local single-tenant dev) ───────────────────────
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=accounts360_demo001
DB_USER=root
DB_PASS=

# ── Optional: pin to a subdomain for local dev (no subdomain in Host) ──────
TENANT_SUBDOMAIN=demo001

# ── JWT ────────────────────────────────────────────────────────────────────
JWT_SECRET=CHANGE_THIS_TO_A_LONG_RANDOM_STRING_AT_LEAST_32_CHARS
JWT_EXPIRY=86400
REFRESH_TOKEN_EXPIRY=2592000

# ── File storage (outside web root) ────────────────────────────────────────
STORAGE_PATH=C:\wamp64\www\Accounts360tech\backend\storage\documents

# ── Google Document AI (leave blank → mock mode for development) ───────────
DOCAI_PROJECT_ID=
DOCAI_PROCESSOR_ID=
DOCAI_LOCATION=us
DOCAI_API_KEY=
```

### 4. Enable Apache mod_rewrite

1. WAMP Manager → Apache → Apache Modules → enable **rewrite_module**
2. In `httpd.conf`, set `AllowOverride All` for your www directory.

### 5. Open the app

| URL | Description |
|---|---|
| `http://localhost/Accounts360tech/frontend/` | SPA frontend |
| `http://localhost/Accounts360tech/backend/public/api/v1/health` | API health check |

### 6. Run the AI worker

```powershell
php backend\worker\worker.php
```

> Without `DOCAI_*` credentials, the worker uses **mock data** automatically —  
> all features work end-to-end for development.

---

## Register a New Organization

```http
POST /api/v1/tenants/register
Content-Type: application/json

{
  "org_name":  "Acme Corp",
  "subdomain": "acme",
  "name":      "Jane Smith",
  "email":     "jane@acme.com",
  "password":  "Secret123"
}
```

This single call:
1. Inserts a row into `hr360_master.tenants` (`accounts_app=1`)
2. Creates `accounts360_acme` database
3. Applies all tenant migrations + seeds the Chart of Accounts
4. Creates the admin user
5. Returns a JWT with `tenant_id` + `tenant_db` embedded

---

## API Overview

All endpoints live under `/Accounts360tech/backend/public/api/v1/`.

### Tenant (public)
| Method | Endpoint | Description |
|---|---|---|
| POST | `/tenants/register` | Provision new org |
| GET | `/tenants/check-subdomain?s=demo001` | Availability check |

### Auth
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/auth/register` | — | Create user in tenant |
| POST | `/auth/login` | — | Login → JWT |
| POST | `/auth/refresh` | — | Renew JWT |
| POST | `/auth/logout` | JWT | Invalidate refresh token |
| POST | `/auth/forgot-password` | — | Send reset email |
| POST | `/auth/reset-password` | — | Reset password |

### Documents
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/documents/upload` | JWT | Upload 1–10 files |
| GET | `/documents` | JWT | List with filters & pagination |
| GET | `/documents/{id}` | JWT | Detail + extracted data |
| PATCH | `/documents/{id}` | JWT | Save corrections |
| POST | `/documents/{id}/approve` | JWT | Approve |
| POST | `/documents/{id}/archive` | JWT | Archive |
| DELETE | `/documents/{id}` | JWT | Soft-delete |
| GET | `/documents/{id}/download` | JWT | Secure file stream |
| GET | `/documents/export` | JWT | CSV export |

### Admin
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/admin/users` | JWT (admin) | All users |
| PATCH | `/admin/users/{id}` | JWT (admin) | Toggle active/role |
| GET | `/admin/logs` | JWT (admin) | Processing logs |
| GET | `/admin/stats` | JWT (admin) | System stats |

### Bookkeeping
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET/POST | `/accounts` | JWT | Chart of Accounts |
| PATCH/DELETE | `/accounts/{id}` | JWT | Update / delete |
| GET/POST | `/journal` | JWT | Journal entries |
| GET/PATCH/DELETE | `/journal/{id}` | JWT | Entry detail |
| POST | `/journal/{id}/post` | JWT | Post to ledger |
| GET/POST | `/banks` | JWT | Bank accounts |
| GET | `/banks/{id}/transactions` | JWT | Transaction list |
| POST | `/banks/{id}/import` | JWT | Import CSV |
| POST | `/banks/{id}/reconcile` | JWT | Reconcile |
| GET | `/reports/profit-loss` | JWT | P&L report |
| GET | `/reports/balance-sheet` | JWT | Balance sheet |
| GET | `/reports/trial-balance` | JWT | Trial balance |

---

## Schema Dump Utility

```powershell
# Dump the shared master
php C:\Users\N TECH\Desktop\schema_dump.php --db=hr360_master > master.tsv

# Dump local tenant
php C:\Users\N TECH\Desktop\schema_dump.php --db=accounts360_demo001 > local.tsv

# Dump production tenant (Hostinger)
php C:\Users\N TECH\Desktop\schema_dump.php --db=u819151619_demo001 > prod.tsv

# Find schema drift
diff local.tsv prod.tsv
```

---

## Project Structure

```
Accounts360tech/
├── PROJECT_PLAN.md               Full project plan & architecture docs
├── README.md                     This file
├── functional_specification_document.md
├── backend/
│   ├── bootstrap.php             Schema installer (reads hr360_master)
│   ├── public/index.php          Router + entry point
│   ├── src/
│   │   ├── Config/
│   │   │   ├── Config.php        .env loader
│   │   │   └── Database.php      connectMaster() · connectTenant() · connect()
│   │   ├── Middleware/
│   │   │   ├── TenantMiddleware.php  Resolves org from hr360_master
│   │   │   └── AuthMiddleware.php   Validates JWT, restores tenant context
│   │   ├── Controllers/
│   │   │   ├── TenantController.php
│   │   │   ├── AuthController.php
│   │   │   ├── DocumentController.php
│   │   │   ├── AdminController.php
│   │   │   ├── AccountController.php
│   │   │   ├── JournalController.php
│   │   │   ├── BankController.php
│   │   │   └── ReportController.php
│   │   ├── Services/
│   │   │   ├── JWTService.php       (embeds tenant_id + tenant_db)
│   │   │   ├── DocumentAIService.php
│   │   │   ├── FileService.php
│   │   │   ├── ExportService.php
│   │   │   └── JournalService.php
│   │   └── Helpers/
│   │       ├── Response.php
│   │       └── Validator.php
│   ├── migrations/
│   │   └── tenant/
│   │       ├── 01_schema.sql        11 core tables
│   │       └── 02_bookkeeping.sql   5 bookkeeping tables + CoA seed
│   ├── storage/documents/           Uploaded files (not web-accessible)
│   └── worker/worker.php            Background AI processing loop
└── frontend/
    ├── index.html
    └── assets/
        ├── css/app.css
        └── js/
            ├── api.js · auth.js · app.js
            ├── upload.js · review.js · ledger.js
            ├── accounts.js · journal.js · bank.js · reports.js
            └── admin.js
```

---

## Google Document AI Setup (Production)

1. Create a GCP project, enable the **Document AI API**
2. Create an **Expense Parser** processor in the Document AI console
3. Create a service account with `Document AI API User` role, download the JSON key
4. Set in `backend/.env`:
   ```env
   DOCAI_PROJECT_ID=your-project-id
   DOCAI_PROCESSOR_ID=your-processor-id
   DOCAI_LOCATION=us
   DOCAI_API_KEY=C:\path\to\service-account-key.json
   ```
