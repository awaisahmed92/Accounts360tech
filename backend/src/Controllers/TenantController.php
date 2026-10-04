<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Config\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Services\JWTService;
use App\Services\SignupCaptcha;

/**
 * TenantController
 *
 * Manages organization provisioning in hr360_master and
 * creation of the corresponding tenant database.
 *
 * Public routes (no auth required):
 *   POST /api/v1/tenants/register         Provision new org + admin user
 *   GET  /api/v1/tenants/check-subdomain  Check subdomain availability
 */
class TenantController
{
    // ── POST /api/v1/tenants/register ────────────────────────────────────────

    public static function register(): never
    {
        $body = self::json();

        if (!SignupCaptcha::check($body['captcha_token'] ?? '', $body['captcha_answer'] ?? '')) {
            Response::error('CAPTCHA_FAILED', 'The verification code is incorrect. Request a new code and try again.', 422);
        }

        // Accept both Accounts payload keys and HR-style signup keys.
        $orgNameRaw     = $body['org_name'] ?? $body['company_name'] ?? '';
        $subdomainRaw   = $body['subdomain'] ?? $body['company_code'] ?? '';
        $nameRaw        = $body['name'] ?? $body['user_name'] ?? '';
        $designationRaw = $body['designation'] ?? '';
        $industryRaw    = $body['industry'] ?? '';
        $countryRaw     = $body['country'] ?? '';
        $phoneRaw       = $body['phone'] ?? '';

        // Validate input
        $v = (new Validator())
            ->required('org_name',   (string) $orgNameRaw)
            ->required('subdomain',  (string) $subdomainRaw)
            ->required('name',       (string) $nameRaw)
            ->required('designation',(string) $designationRaw)
            ->required('industry',   (string) $industryRaw)
            ->required('country',    (string) $countryRaw)
            ->required('email',      $body['email']      ?? '')
            ->email('email',         $body['email']      ?? '')
            ->required('password',   $body['password']   ?? '')
            ->password('password',   $body['password']   ?? '')
            ->maxLength('org_name',  (string) $orgNameRaw, 191)
            ->maxLength('subdomain', (string) $subdomainRaw, 100)
            ->maxLength('name',      (string) $nameRaw, 100)
            ->maxLength('designation', (string) $designationRaw, 191)
            ->maxLength('industry',  (string) $industryRaw, 120)
            ->maxLength('country',   (string) $countryRaw, 120)
            ->maxLength('phone',     (string) $phoneRaw, 60);

        if ($v->fails()) {
            Response::error('VALIDATION_ERROR', 'Validation failed.', 422, $v->errors());
        }

        $orgName   = trim((string) $orgNameRaw);
        $subdomain = strtolower(preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string) $subdomainRaw))));
        $adminName = trim((string) $nameRaw);
        $adminEmail = strtolower(trim($body['email']));
        $password   = $body['password'];
        $designation = trim((string) $designationRaw);
        $industry = trim((string) $industryRaw);
        $country = trim((string) $countryRaw);
        $phone = trim((string) $phoneRaw);

        if ($subdomain === '') {
            Response::error('VALIDATION_ERROR', 'Subdomain may only contain lowercase letters, digits, and hyphens.', 422);
        }

        $tenantDbName = 'accounts360_' . $subdomain;

        // ── 1. Same hr360_master client as HR and POS ────────────────────────
        $master = Database::connectMaster();
        $existing = self::locateTenant($master, $subdomain, $orgName);
        if ($existing && (int) $existing['accounts_app'] === 1) {
            Response::error('SUBDOMAIN_TAKEN', 'That company already has Accounts360tech. Sign in with the existing company code.', 409);
        }
        if ($existing) {
            $canonical = self::matchKey((string) ($existing['company_code'] ?: $existing['subdomain']));
            if (strlen($canonical) >= 3) {
                $subdomain = $canonical;
                $tenantDbName = 'accounts360_' . $subdomain;
            }
        }

        $dbHost = Config::get('DB_HOST', '127.0.0.1');
        $dbUser = Config::get('DB_USER', 'root');
        $dbPass = Config::get('DB_PASS', '');
        $attached = $existing !== false;

        // ── 2. Insert a new client, or turn Accounts on for the existing one ─
        if ($attached) {
            $tenantId = (int) $existing['id'];
            $master->prepare(
                "UPDATE tenants SET
                    accounts_app = 1,
                    accounts_db_name = ?,
                    company_code = COALESCE(NULLIF(company_code, ''), ?),
                    industry = COALESCE(NULLIF(industry, ''), ?),
                    country = COALESCE(NULLIF(country, ''), ?),
                    contact_name = COALESCE(NULLIF(contact_name, ''), ?),
                    contact_designation = COALESCE(NULLIF(contact_designation, ''), ?),
                    contact_email = COALESCE(NULLIF(contact_email, ''), ?),
                    contact_phone = COALESCE(contact_phone, ?)
                 WHERE id = ?"
            )->execute([
                $tenantDbName,
                $subdomain,
                $industry,
                $country,
                $adminName,
                $designation,
                $adminEmail,
                $phone !== '' ? $phone : null,
                $tenantId,
            ]);
        } else {
            $stmt = $master->prepare(
                "INSERT INTO tenants
                    (name, subdomain, db_host, db_name, accounts_db_name, db_user, db_password,
                     status, hr_app, accounts_app, company_code, industry, country,
                     contact_name, contact_designation, contact_email, contact_phone, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, 1, ?, ?, ?, ?, ?, ?, ?, 'self_signup')"
            );
            $stmt->execute([
                $orgName,
                $subdomain,
                $dbHost,
                $tenantDbName,       // db_name (HR placeholder — no HR DB)
                $tenantDbName,       // accounts_db_name
                $dbUser,
                $dbPass,
                $subdomain,
                $industry,
                $country,
                $adminName,
                $designation,
                $adminEmail,
                $phone !== '' ? $phone : null,
            ]);
            $tenantId = (int) $master->lastInsertId();
        }

        // ── 3. Create tenant database ────────────────────────────────────────
        try {
            $rootDsn = "mysql:host=$dbHost;port=" . Config::get('DB_PORT', '3306') . ";charset=utf8mb4";
            $rootPdo = new \PDO($rootDsn, $dbUser, $dbPass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $rootPdo->exec(
                "CREATE DATABASE IF NOT EXISTS `$tenantDbName`
                 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        } catch (\Exception $e) {
            if ($attached) {
                $master->prepare("UPDATE tenants SET accounts_app = 0, accounts_db_name = NULL WHERE id = ?")->execute([$tenantId]);
            } else {
                $master->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tenantId]);
            }
            Response::error('DB_CREATION_FAILED', 'Could not create tenant database.', 500);
        }

        // ── 4. Apply migrations to new tenant DB ─────────────────────────────
        $sqlDir   = dirname(__DIR__, 2) . '/migrations/tenant';
        $sqlFiles = glob($sqlDir . '/*.sql');
        sort($sqlFiles);

        $tenantDsn = "mysql:host=$dbHost;port=" . Config::get('DB_PORT', '3306')
                   . ";dbname=$tenantDbName;charset=utf8mb4";
        $tenantPdo = new \PDO($tenantDsn, $dbUser, $dbPass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        // _schema_migrations bootstrap
        $tenantPdo->exec("CREATE TABLE IF NOT EXISTS _schema_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            checksum VARCHAR(64) NOT NULL,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ($sqlFiles as $filePath) {
            $filename = basename($filePath);
            $sql      = str_replace('{{TENANT_DB}}', $tenantDbName, file_get_contents($filePath));
            $checksum = hash('sha256', file_get_contents($filePath));

            foreach (self::splitSql($sql) as $statement) {
                if (trim($statement) !== '') {
                    $tenantPdo->exec($statement);
                }
            }

            $tenantPdo->prepare(
                "INSERT INTO _schema_migrations (filename, checksum) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE checksum=VALUES(checksum), applied_at=NOW()"
            )->execute([$filename, $checksum]);
        }

        // ── 5. Create admin user in tenant DB ────────────────────────────────
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $tenantPdo->prepare(
            "INSERT INTO users (name, email, password_hash, role, is_active) VALUES (?,?,?,'admin',1)"
        )->execute([$adminName, $adminEmail, $hash]);
        $userId = (int) $tenantPdo->lastInsertId();

        // ── 6. Record admin in master tenant_admins ──────────────────────────
        $master->prepare(
            "INSERT INTO tenant_admins (tenant_id, name, email, user_name) VALUES (?,?,?,?)"
        )->execute([$tenantId, $adminName, $adminEmail, $adminEmail]);

        // ── 7. Issue JWT with tenant context ─────────────────────────────────
        $_SERVER['ACCOUNTS_TENANT_ID'] = $tenantId;
        $_SERVER['ACCOUNTS_TENANT_DB'] = $tenantDbName;

        $token = JWTService::generate([
            'sub'       => $userId,
            'role'      => 'admin',
            'name'      => $adminName,
            'tenant_id' => $tenantId,
            'tenant_db' => $tenantDbName,
        ]);

        Response::json([
            'message'     => 'Organization registered successfully.',
            'tenant'      => ['id' => $tenantId, 'subdomain' => $subdomain, 'org_name' => $orgName],
            'token'       => $token,
            'expires_in'  => (int) Config::get('JWT_EXPIRY', 86400),
            'user'        => ['id' => $userId, 'name' => $adminName, 'email' => $adminEmail, 'role' => 'admin'],
        ], 201);
    }

    // ── GET /api/v1/tenants/check-subdomain?s=demo001 ────────────────────────

    public static function checkSubdomain(): never
    {
        $s = strtolower(trim($_GET['s'] ?? ''));
        if ($s === '') {
            Response::error('VALIDATION_ERROR', 'Query param ?s= is required.', 422);
        }

        $master = Database::connectMaster();
        $row = self::locateTenant($master, $s, trim((string) ($_GET['name'] ?? '')));
        $taken = $row && (int) $row['accounts_app'] === 1;
        $existing = $row && !$taken;

        Response::json([
            'subdomain' => $s,
            'available' => !$taken,
            'existing_client' => $existing,
            'message' => $taken
                ? 'That company already has Accounts360tech. Sign in with the existing company code.'
                : ($existing
                    ? 'This company is already on 360tech. Accounts will open on the same company record.'
                    : 'Available.'),
        ]);
    }

    /**
     * Find the one master client by company code or company name.
     * A company created in HR or POS is reused instead of inserting another row.
     *
     * @return array<string, mixed>|false
     */
    private static function locateTenant(\PDO $master, string $code, string $companyName): array|false
    {
        $codeKey = self::matchKey($code);
        $nameKey = self::matchKey($companyName);
        $rows = $master->query(
            'SELECT id, name, subdomain, company_code, accounts_app FROM tenants'
        )->fetchAll();
        $byCode = null;
        $byName = null;
        foreach ($rows ?: [] as $row) {
            $codes = array_filter([
                self::matchKey((string) ($row['company_code'] ?? '')),
                self::matchKey((string) ($row['subdomain'] ?? '')),
            ]);
            if ($codeKey !== '' && in_array($codeKey, $codes, true)) {
                $byCode = $row;
            }
            if ($nameKey !== '' && self::matchKey((string) ($row['name'] ?? '')) === $nameKey) {
                $byName ??= $row;
            }
        }
        if ($byCode && $byName && (int) $byCode['id'] !== (int) $byName['id']) {
            Response::error(
                'COMPANY_CONFLICT',
                'That company name is already registered under a different company code.',
                409
            );
        }

        return $byCode ?? $byName ?? false;
    }

    private static function matchKey(string $raw): string
    {
        $key = strtolower(trim($raw));
        $key = preg_replace('/[^a-z0-9]+/', '', $key) ?? '';

        return substr($key, 0, 40);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function json(): array
    {
        $raw = file_get_contents('php://input');
        return $raw ? (json_decode($raw, true) ?? []) : [];
    }

    /**
     * Minimal multi-statement SQL splitter that handles DELIMITER changes.
     * Mirrors the same helper in bootstrap.php.
     */
    private static function splitSql(string $sql): array
    {
        $statements = [];
        $delimiter  = ';';
        $current    = '';

        foreach (explode("\n", $sql) as $line) {
            if (preg_match('/^DELIMITER\s+(\S+)/i', trim($line), $m)) {
                $delimiter = $m[1];
                continue;
            }
            $current .= $line . "\n";
            if (str_ends_with(rtrim($line), $delimiter)) {
                $stmt = substr(rtrim($current), 0, -strlen($delimiter));
                if (trim($stmt) !== '') $statements[] = $stmt;
                $current = '';
            }
        }

        if (trim($current) !== '') $statements[] = $current;
        return $statements;
    }
}
