<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;
use App\Services\JWTService;

class AuthMiddleware
{
    public static function handle(bool $requireAdmin = false): array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($authHeader, 'Bearer ')) {
            Response::error('UNAUTHORIZED', 'Authentication required.', 401);
        }

        $token   = substr($authHeader, 7);
        $payload = JWTService::verify($token);

        if (!$payload) {
            Response::error('TOKEN_EXPIRED', 'Token is invalid or expired.', 401);
        }

        if ($requireAdmin && ($payload['role'] ?? '') !== 'admin') {
            Response::error('FORBIDDEN', 'Admin access required.', 403);
        }

        return $payload;
    }
}
