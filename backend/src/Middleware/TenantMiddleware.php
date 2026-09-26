<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;

/**
 * TenantMiddleware
 *
 * Resolves the current organization's tenant DB from hr360_master.tenants.
 * Must run before any controller that touches the tenant database.
 *
 * Resolution order (first match wins):
 *   1. X-Tenant-Subdomain request header
 *   2. Subdomain extracted from the Host header (e.g. demo001.accounts360.tech → demo001)
 *   3. TENANT_SUBDOMAIN env var (useful for single-tenant / local dev deployments)
 *
 * On success, stores the resolved DB name in $_SERVER['ACCOUNTS_TENANT_DB']
 * and the tenant_id in $_SERVER['ACCOUNTS_TENANT_ID'] so that Database::connect()
 * and JWT generation can pick them up without a second master DB query.
 *
 * On failure, returns a JSON error and halts the request.
 */
class TenantMiddleware
{
    /**
     * Resolve tenant and inject into request context.
     *
     * @return array{id: int, subdomain: string, accounts_db_name: string}
     */
    public static function resolve(): array
    {
        $subdomain = self::detectSubdomain();

        if ($subdomain === null) {
            // Local single-tenant fallback: use DB_NAME directly
            $fallbackDb = \App\Config\Config::get('DB_NAME', 'accounts360tech');
            $_SERVER['ACCOUNTS_TENANT_DB'] = $fallbackDb;
            $_SERVER['ACCOUNTS_TENANT_ID'] = 0;
            return ['id' => 0, 'subdomain' => 'local', 'accounts_db_name' => $fallbackDb];
        }

        $master = Database::connectMaster();
        $stmt   = $master->prepare(
            "SELECT id, subdomain, accounts_db_name, status
             FROM tenants
             WHERE subdomain = ? AND accounts_app = 1
             LIMIT 1"
        );
        $stmt->execute([$subdomain]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            Response::error('TENANT_NOT_FOUND', "Organization '$subdomain' is not registered.", 404);
        }

        if ($tenant['status'] !== 'active') {
            Response::error('TENANT_INACTIVE', "Organization '$subdomain' is not active.", 403);
        }

        if (empty($tenant['accounts_db_name'])) {
            Response::error('TENANT_NOT_CONFIGURED', "Accounts database not configured for '$subdomain'.", 503);
        }

        // Inject into request context for Database::connectTenant() + JWT
        $_SERVER['ACCOUNTS_TENANT_DB'] = $tenant['accounts_db_name'];
        $_SERVER['ACCOUNTS_TENANT_ID'] = (int) $tenant['id'];

        return [
            'id'               => (int) $tenant['id'],
            'subdomain'        => $tenant['subdomain'],
            'accounts_db_name' => $tenant['accounts_db_name'],
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private static function detectSubdomain(): ?string
    {
        // 1. Explicit header (API clients / mobile / Postman testing)
        if (!empty($_SERVER['HTTP_X_TENANT_SUBDOMAIN'])) {
            return strtolower(trim($_SERVER['HTTP_X_TENANT_SUBDOMAIN']));
        }

        // 2. Parse from Host header (e.g. demo001.accounts360.tech)
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $host = strtolower(explode(':', $host)[0]); // strip port if present

        // Only treat as subdomain if there are at least 3 parts (sub.domain.tld)
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            $sub = $parts[0];
            // Exclude common non-tenant prefixes
            if (!in_array($sub, ['www', 'api', 'app', 'localhost'], true)) {
                return $sub;
            }
        }

        // 3. Env var fallback (single-tenant / WAMP local development)
        $envSub = \App\Config\Config::get('TENANT_SUBDOMAIN', '');
        if ($envSub !== '') {
            return strtolower($envSub);
        }

        return null; // triggers local single-tenant fallback
    }
}
