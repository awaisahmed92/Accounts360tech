<?php
declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

/**
 * Database connection factory.
 *
 * connectMaster() → hr360_master  (shared tenant registry, never tenant data)
 * connectTenant() → current request's tenant DB (resolved by TenantMiddleware)
 * connect()       → alias for connectTenant() — keeps backward compatibility
 *                   with controllers written before multi-tenancy.
 */
class Database
{
    /** @var PDO|null Cached master DB connection */
    private static ?PDO $master = null;

    /** @var array<string, PDO> Per-DB connection cache */
    private static array $tenants = [];

    // ── Master DB (hr360_master) ─────────────────────────────────────────────

    public static function connectMaster(): PDO
    {
        if (self::$master !== null) return self::$master;

        $host = Config::get('MASTER_HOST', Config::get('DB_HOST', '127.0.0.1'));
        $port = Config::get('MASTER_PORT', Config::get('DB_PORT', '3306'));
        $name = Config::get('MASTER_DB',   'hr360_master');
        $user = Config::get('MASTER_USER', Config::get('DB_USER', 'root'));
        $pass = Config::get('MASTER_PASS', Config::get('DB_PASS', ''));

        self::$master = self::makePdo($host, $port, $name, $user, $pass);
        return self::$master;
    }

    // ── Tenant DB (resolved per request) ────────────────────────────────────

    /**
     * Returns a PDO connection for the current request's tenant database.
     * Requires TenantMiddleware to have stored the DB name in $_SERVER.
     *
     * Falls back to the DB_NAME env var for local single-tenant development.
     */
    public static function connectTenant(?string $dbName = null): PDO
    {
        $dbName = $dbName
            ?? $_SERVER['ACCOUNTS_TENANT_DB']
            ?? Config::get('DB_NAME', 'accounts360tech');

        if (isset(self::$tenants[$dbName])) return self::$tenants[$dbName];

        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::get('DB_PORT', '3306');
        $user = Config::get('DB_USER', 'root');
        $pass = Config::get('DB_PASS', '');

        self::$tenants[$dbName] = self::makePdo($host, $port, $dbName, $user, $pass);
        return self::$tenants[$dbName];
    }

    /**
     * Backward-compatible alias — all existing controllers call Database::connect().
     * Now routes through connectTenant() so they automatically use the
     * per-request tenant DB once TenantMiddleware is active.
     */
    public static function connect(): PDO
    {
        return self::connectTenant();
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private static function makePdo(
        string $host, string $port, string $name, string $user, string $pass
    ): PDO {
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error'   => ['code' => 'DB_ERROR', 'message' => 'Database connection failed.'],
            ]);
            exit;
        }
    }
}
