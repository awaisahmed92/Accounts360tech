<?php

/**
 * Accounts360tech — Schema Bootstrap Installer
 *
 * Reads hr360_master.tenants WHERE accounts_app = 1 and provisions (or verifies)
 * each tenant's dedicated database using the SQL files in migrations/tenant/.
 *
 * Modelled on HR360techx/backend/bootstrap-hr.php.
 *
 * Usage (CLI):
 *   php bootstrap.php                    Apply pending/changed files, then verify
 *   php bootstrap.php --check            Verify only — never write (doctor mode)
 *   php bootstrap.php --force            Re-apply every file to every tenant DB
 *   php bootstrap.php --tenant=demo001   Target one specific subdomain only
 *   php bootstrap.php --provision=accounts360_acme  Create a brand-new tenant DB
 *
 * Exit code 0 = all tenant schemas complete
 * Exit code 1 = one or more tenants have missing tables/columns
 */

declare(strict_types=1);

// ── CLI option parsing ────────────────────────────────────────────────────────
$args        = array_slice($argv ?? [], 1);
$optCheck    = in_array('--check',  $args, true);
$optForce    = in_array('--force',  $args, true);
$optTenant   = null;
$optProvision = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--tenant='))    $optTenant    = substr($arg, 9);
    if (str_starts_with($arg, '--provision=')) $optProvision = substr($arg, 12);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function say(string $line): void  { echo '[accounts360-schema] ' . $line . "\n"; }
function shout(string $line): void { fwrite(STDERR, '[accounts360-schema] ' . $line . "\n"); }

// ── Load .env ─────────────────────────────────────────────────────────────────
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $_ENV[$k] = $v;
    }
}

function env(string $key, string $default = ''): string
{
    return $_ENV[$key] ?? $default;
}

// ── Master DB connection ──────────────────────────────────────────────────────
$masterDb   = env('MASTER_DB',   'hr360_master');
$masterHost = env('MASTER_HOST', env('DB_HOST', '127.0.0.1'));
$masterPort = env('MASTER_PORT', env('DB_PORT', '3306'));
$masterUser = env('MASTER_USER', env('DB_USER', 'root'));
$masterPass = env('MASTER_PASS', env('DB_PASS', ''));

function connectPdo(string $host, string $port, string $dbName, string $user, string $pass): PDO
{
    $dsn = "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function connectRoot(string $host, string $port, string $user, string $pass): PDO
{
    $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

// ── SQL migration directory ───────────────────────────────────────────────────
$sqlDir = __DIR__ . '/migrations/tenant';
if (!is_dir($sqlDir)) {
    shout("Migration directory not found: $sqlDir");
    exit(1);
}

$sqlFiles = glob($sqlDir . '/*.sql');
sort($sqlFiles);

if (empty($sqlFiles)) {
    shout("No SQL files found in $sqlDir");
    exit(1);
}

// ── Fetch tenant list from hr360_master ──────────────────────────────────────
say("Connecting to master DB: $masterDb on $masterHost");
try {
    $masterPdo = connectPdo($masterHost, $masterPort, $masterDb, $masterUser, $masterPass);
} catch (Exception $e) {
    shout("Cannot connect to master DB ($masterDb): " . $e->getMessage());
    shout("Make sure hr360_master exists. Run: mysql -u root hr360_master < HR360techx/database/01_master.sql");
    exit(1);
}

$tenantsQuery = "SELECT id, subdomain, accounts_db_name, db_host, db_user, db_password
                 FROM tenants
                 WHERE accounts_app = 1 AND status = 'active'";
if ($optTenant !== null) {
    $stmt = $masterPdo->prepare($tenantsQuery . " AND subdomain = ?");
    $stmt->execute([$optTenant]);
} else {
    $stmt = $masterPdo->query($tenantsQuery);
}

$tenants = $stmt->fetchAll();

if (empty($tenants) && $optProvision === null) {
    say("No active Accounts360tech tenants found in hr360_master.tenants.");
    say("Run 31_multi_app_registry.sql first, or use --provision=<db_name> to create a new tenant.");
    exit(0);
}

// ── If --provision: just create a single new DB without master lookup ─────────
if ($optProvision !== null) {
    $tenants = [[
        'id'               => 0,
        'subdomain'        => $optProvision,
        'accounts_db_name' => $optProvision,
        'db_host'          => $masterHost,
        'db_user'          => $masterUser,
        'db_password'      => $masterPass,
    ]];
    say("Provisioning standalone DB: $optProvision");
}

// ── Process each tenant ───────────────────────────────────────────────────────
$exitCode = 0;

foreach ($tenants as $tenant) {
    $tenantDb   = $tenant['accounts_db_name'];
    $subdomain  = $tenant['subdomain'];
    $dbHost     = $tenant['db_host'] ?: $masterHost;
    $dbUser     = $tenant['db_user'] ?: $masterUser;
    $dbPass     = $tenant['db_password'] ?? $masterPass;

    if (empty($tenantDb)) {
        shout("Tenant '$subdomain' has no accounts_db_name set — skipping.");
        continue;
    }

    say("─── Tenant: $subdomain  DB: $tenantDb ─────────────────");

    // Create DB if not exists
    if (!$optCheck) {
        try {
            $rootPdo = connectRoot($dbHost, $masterPort, $dbUser, $dbPass);
            $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `$tenantDb`
                            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            say("  Database $tenantDb ready.");
        } catch (Exception $e) {
            shout("  Failed to create database $tenantDb: " . $e->getMessage());
            $exitCode = 1;
            continue;
        }
    }

    // Connect to tenant DB
    try {
        $tenantPdo = connectPdo($dbHost, $masterPort, $tenantDb, $dbUser, $dbPass);
    } catch (Exception $e) {
        shout("  Cannot connect to $tenantDb: " . $e->getMessage());
        $exitCode = 1;
        continue;
    }

    // Ensure _schema_migrations exists
    $tenantPdo->exec("CREATE TABLE IF NOT EXISTS _schema_migrations (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        filename   VARCHAR(255) NOT NULL UNIQUE,
        checksum   VARCHAR(64)  NOT NULL,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Load existing checksums
    $applied = [];
    foreach ($tenantPdo->query("SELECT filename, checksum FROM _schema_migrations") as $row) {
        $applied[$row['filename']] = $row['checksum'];
    }

    foreach ($sqlFiles as $filePath) {
        $filename = basename($filePath);
        $sql      = file_get_contents($filePath);
        $checksum = hash('sha256', $sql);

        $alreadyApplied = isset($applied[$filename]) && $applied[$filename] === $checksum;

        if ($alreadyApplied && !$optForce) {
            say("  [skip] $filename (unchanged)");
            continue;
        }

        if ($optCheck) {
            say("  [pending] $filename");
            $exitCode = 1;
            continue;
        }

        // Replace placeholder with actual DB name
        $sql = str_replace('{{TENANT_DB}}', $tenantDb, $sql);

        say("  [apply] $filename");
        try {
            // Execute statement by statement (PDO doesn't support multi-statement exec by default)
            foreach (splitSqlStatements($sql) as $stmt) {
                if (trim($stmt) !== '') {
                    $tenantPdo->exec($stmt);
                }
            }

            // Record checksum
            $tenantPdo->prepare(
                "INSERT INTO _schema_migrations (filename, checksum) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE checksum=VALUES(checksum), applied_at=NOW()"
            )->execute([$filename, $checksum]);

        } catch (Exception $e) {
            shout("  [error] $filename: " . $e->getMessage());
            $exitCode = 1;
        }
    }

    // Verify: check all expected tables exist
    $errors = verifySchema($tenantPdo, $tenantDb);
    if (empty($errors)) {
        say("  [ok] $tenantDb schema complete.");
    } else {
        foreach ($errors as $err) { shout("  [missing] $err"); }
        $exitCode = 1;
    }
}

say($exitCode === 0 ? "All tenant schemas complete." : "One or more issues found — see above.");
exit($exitCode);


// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Split a multi-statement SQL string into individual statements.
 * Handles DELIMITER changes so that stored procedure bodies are handled correctly.
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $delimiter  = ';';
    $current    = '';
    $lines      = explode("\n", $sql);

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Handle DELIMITER directive
        if (preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $m)) {
            $delimiter = $m[1];
            continue;
        }

        $current .= $line . "\n";

        if (str_ends_with(rtrim($line), $delimiter)) {
            $stmt = substr(rtrim($current), 0, -strlen($delimiter));
            if (trim($stmt) !== '') {
                $statements[] = $stmt;
            }
            $current = '';
        }
    }

    if (trim($current) !== '') {
        $statements[] = $current;
    }

    return $statements;
}

/**
 * Verify that the expected tables are present in the tenant DB.
 * Returns array of missing items (empty = all good).
 */
function verifySchema(PDO $pdo, string $dbName): array
{
    $expectedTables = [
        'users', 'refresh_tokens', 'documents', 'extracted_data', 'line_items',
        'processing_queue', 'processing_logs', 'password_resets',
        'document_audit_logs', 'admin_audit_logs', '_schema_migrations',
        'accounts', 'journal_entries', 'journal_lines',
        'bank_accounts', 'bank_transactions',
    ];

    $existing = [];
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'"
    );
    $stmt->execute([$dbName]);
    foreach ($stmt->fetchAll() as $row) {
        $existing[] = $row['TABLE_NAME'];
    }

    $missing = [];
    foreach ($expectedTables as $table) {
        if (!in_array($table, $existing, true)) {
            $missing[] = "Table missing: $table";
        }
    }

    return $missing;
}
