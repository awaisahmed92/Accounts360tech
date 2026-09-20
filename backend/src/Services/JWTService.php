<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Config;

class JWTService
{
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    public static function generate(array $payload): string
    {
        $secret  = Config::get('JWT_SECRET', 'changeme');
        $expiry  = (int) Config::get('JWT_EXPIRY', 86400);

        $header  = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = array_merge($payload, ['iat' => time(), 'exp' => time() + $expiry]);
        $payload = self::base64UrlEncode(json_encode($payload));

        $signature = self::base64UrlEncode(hash_hmac('sha256', "$header.$payload", $secret, true));
        return "$header.$payload.$signature";
    }

    public static function verify(string $token): array|false
    {
        $secret = Config::get('JWT_SECRET', 'changeme');
        $parts  = explode('.', $token);
        if (count($parts) !== 3) return false;

        [$header, $payload, $signature] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', "$header.$payload", $secret, true));

        if (!hash_equals($expected, $signature)) return false;

        $data = json_decode(self::base64UrlDecode($payload), true);
        if (!$data || $data['exp'] < time()) return false;

        return $data;
    }

    public static function generateRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
