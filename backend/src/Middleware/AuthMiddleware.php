<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;
use App\Services\JWTService;

/**
 * AuthMiddleware
 *
 * Validates the Bearer JWT and restores tenant context into the request
 * environment so that Database::connect() routes to the correct tenant DB
 * even on routes where TenantMiddleware was not run first.
 *
 * Returns the full JWT payload array on success.
 *
 * JWT payload shape:
 *   sub        int    user_id in tenant DB
 *   role       string 'admin' | 'user'
 *   name       string display name
 *   tenant_id  int    hr360_master.tenants.id (0 = local fallback)
 *   tenant_db  string accounts_db_name (MySQL database)
 *   iat / exp  int    issued-at / expiry timestamps
 */
class AuthMiddleware
{
    public static function handle(bool $requireAdmin = false): array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = null;

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        } elseif (!empty($_GET['token']) && is_string($_GET['token'])) {
            // Fallback for preview/download URLs opened via iframe/img src.
            $token = trim($_GET['token']);
        }

        if (!$token) {
            Response::error('UNAUTHORIZED', 'Authentication required.', 401);
        }

        $payload = JWTService::verify($token);

        if (!$payload) {
            Response::error('TOKEN_EXPIRED', 'Token is invalid or expired.', 401);
        }

        // Restore tenant context into request environment.
        // This allows Database::connect() to pick the correct tenant DB
        // without requiring TenantMiddleware on every route.
        if (!empty($payload['tenant_db'])) {
            $_SERVER['ACCOUNTS_TENANT_DB'] = $payload['tenant_db'];
        }
        if (isset($payload['tenant_id'])) {
            $_SERVER['ACCOUNTS_TENANT_ID'] = (int) $payload['tenant_id'];
        }

        if ($requireAdmin && ($payload['role'] ?? '') !== 'admin') {
            Response::error('FORBIDDEN', 'Admin access required.', 403);
        }

        return $payload;
    }
}
