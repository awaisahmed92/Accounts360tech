# Accounts360tech — Dext Clone MVP

Pre-accounting automation: upload receipts & invoices → AI extracts data → review & approve → export ledger.

---

## Quick Start (WAMP)

### 1. Run the Database Migration

Open WAMP's phpMyAdmin or run in PowerShell:

```powershell
C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe -u root -p < C:\wamp64\www\Accounts360tech\backend\migrations\schema.sql
```

Or paste the contents of `backend/migrations/schema.sql` directly into phpMyAdmin's SQL tab.

**Default admin account created:**
- Email: `admin@accounts360.tech`
- Password: `Admin@1234`

---

### 2. Configure Environment

Edit `backend/.env` (already copied from `.env.example`):

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=accounts360tech
DB_USER=root
DB_PASS=          # ← your WAMP MySQL root password (blank if none)

JWT_SECRET=CHANGE_THIS_TO_A_LONG_RANDOM_STRING_AT_LEAST_32_CHARS

STORAGE_PATH=C:\wamp64\www\Accounts360tech\backend\storage\documents
```

---

### 3. Enable Apache mod_rewrite

1. Open WAMP Manager → Apache → Apache Modules → enable **rewrite_module**
2. In `httpd.conf`, ensure `AllowOverride All` is set for your www directory.

---

### 4. Open the App

| URL | Description |
|---|---|
| `http://localhost/Accounts360tech/frontend/` | **Frontend (main app)** |
| `http://localhost/Accounts360tech/backend/public/api/v1/health` | API health check |

---

### 5. Start the AI Worker

The worker processes uploaded documents. Run in a terminal:

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe C:\wamp64\www\Accounts360tech\backend\worker\worker.php
```

Or add to Windows Task Scheduler to run at startup.

> **Without a Google Document AI API key**, the worker uses **mock data** automatically — all app features work end-to-end for development.

---

## Project Structure

```
Accounts360tech/
├── backend/
│   ├── public/               ← Apache web root for API
│   │   ├── index.php         ← Router / entry point
│   │   └── .htaccess
│   ├── src/
│   │   ├── Config/           ← Database, Config
│   │   ├── Controllers/      ← Auth, Document, Admin
│   │   ├── Middleware/       ← JWT auth middleware
│   │   ├── Services/         ← JWT, FileService, DocumentAI, Export
│   │   └── Helpers/          ← Response, Validator
│   ├── migrations/
│   │   └── schema.sql        ← All 8 MySQL tables
│   ├── storage/documents/    ← Uploaded files (not web-accessible)
│   ├── worker/
│   │   └── worker.php        ← Background AI processing loop
│   └── .env                  ← Configuration (never commit)
├── frontend/
│   ├── index.html            ← SPA shell
│   └── assets/
│       ├── css/app.css
│       └── js/
│           ├── api.js        ← API client (all fetch calls)
│           ├── auth.js       ← Login / Register / Forgot
│           ├── upload.js     ← Drag & drop upload
│           ├── review.js     ← Review queue & split-screen
│           ├── ledger.js     ← Transaction table & export
│           ├── admin.js      ← Admin panel
│           └── app.js        ← Router & bootstrap
└── functional_specification_document.md
```

---

## API Overview

All endpoints live under `/Accounts360tech/backend/public/api/v1/`.

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/auth/register` | — | Create account |
| POST | `/auth/login` | — | Login → JWT + refresh token |
| POST | `/auth/refresh` | — | Renew JWT |
| POST | `/auth/logout` | JWT | Invalidate refresh token |
| POST | `/documents/upload` | JWT | Upload 1–10 files |
| GET | `/documents` | JWT | List with filters & pagination |
| GET | `/documents/{id}` | JWT | Document + extracted data + line items |
| PATCH | `/documents/{id}` | JWT | Update extracted fields |
| POST | `/documents/{id}/approve` | JWT | Approve |
| POST | `/documents/{id}/archive` | JWT | Archive |
| DELETE | `/documents/{id}` | JWT | Soft-delete |
| GET | `/documents/{id}/download` | JWT | Secure file stream |
| GET | `/documents/export` | JWT | CSV export |
| GET | `/admin/users` | JWT (admin) | All users |
| PATCH | `/admin/users/{id}` | JWT (admin) | Toggle active/role |
| GET | `/admin/logs` | JWT (admin) | Processing logs |
| GET | `/admin/stats` | JWT (admin) | System stats |

---

## Google Document AI Setup (Production)

1. Create a GCP project and enable the **Document AI API**
2. Create an **Expense Parser** processor in the Document AI console
3. Create a service account with `Document AI API User` role, download the JSON key
4. In `.env`:
   ```
   DOCAI_PROJECT_ID=your-project-id
   DOCAI_PROCESSOR_ID=your-processor-id
   DOCAI_LOCATION=us
   DOCAI_API_KEY=C:\path\to\service-account-key.json
   ```
