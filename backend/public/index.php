<?php
declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────────────
$root = dirname(__DIR__);

require_once $root . '/src/Config/Config.php';
require_once $root . '/src/Config/Database.php';
require_once $root . '/src/Helpers/Response.php';
require_once $root . '/src/Helpers/Validator.php';
require_once $root . '/src/Services/JWTService.php';
require_once $root . '/src/Services/FileService.php';
require_once $root . '/src/Services/DocumentAIService.php';
require_once $root . '/src/Services/ExportService.php';
require_once $root . '/src/Services/JournalService.php';
require_once $root . '/src/Middleware/AuthMiddleware.php';
require_once $root . '/src/Controllers/AuthController.php';
require_once $root . '/src/Controllers/DocumentController.php';
require_once $root . '/src/Controllers/AdminController.php';
require_once $root . '/src/Controllers/AccountController.php';
require_once $root . '/src/Controllers/JournalController.php';
require_once $root . '/src/Controllers/BankController.php';
require_once $root . '/src/Controllers/ReportController.php';

use App\Config\Config;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Controllers\AuthController;
use App\Controllers\DocumentController;
use App\Controllers\AdminController;
use App\Controllers\AccountController;
use App\Controllers\JournalController;
use App\Controllers\BankController;
use App\Controllers\ReportController;

Config::load($root . '/.env');

// ── CORS ───────────────────────────────────────────────────────────────────────
$allowedOrigins = ['http://localhost', 'http://127.0.0.1', 'http://localhost:3000'];
$origin         = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true) || str_contains($origin, 'localhost')) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: *");
}
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Route parsing ──────────────────────────────────────────────────────────────
$method  = $_SERVER['REQUEST_METHOD'];
$uri     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Strip base prefix (works both on WAMP sub-path and direct vhost / Docker)
foreach (['/Accounts360tech/backend/public', '/backend/public'] as $base) {
    if (str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
        break;
    }
}
$uri     = '/' . trim($uri, '/');

// ── Router ─────────────────────────────────────────────────────────────────────
// Auth routes (public)
if ($uri === '/api/v1/auth/register'       && $method === 'POST') { AuthController::register(); }
if ($uri === '/api/v1/auth/login'          && $method === 'POST') { AuthController::login(); }
if ($uri === '/api/v1/auth/refresh'        && $method === 'POST') { AuthController::refresh(); }
if ($uri === '/api/v1/auth/forgot-password'&& $method === 'POST') { AuthController::forgotPassword(); }
if ($uri === '/api/v1/auth/reset-password' && $method === 'POST') { AuthController::resetPassword(); }

// Auth routes (protected)
if ($uri === '/api/v1/auth/logout'         && $method === 'POST') {
    $auth = AuthMiddleware::handle();
    AuthController::logout($auth);
}

// Document routes (protected)
if ($uri === '/api/v1/documents/export'    && $method === 'GET') {
    $auth = AuthMiddleware::handle();
    DocumentController::export($auth);
}
if ($uri === '/api/v1/documents'           && $method === 'GET') {
    $auth = AuthMiddleware::handle();
    DocumentController::index($auth);
}
if ($uri === '/api/v1/documents/upload'    && $method === 'POST') {
    $auth = AuthMiddleware::handle();
    DocumentController::upload($auth);
}

// Dynamic document routes
if (preg_match('#^/api/v1/documents/(\d+)$#', $uri, $m)) {
    $auth = AuthMiddleware::handle();
    $id   = (int) $m[1];
    match ($method) {
        'GET'    => DocumentController::show($id, $auth),
        'PATCH'  => DocumentController::update($id, $auth),
        'DELETE' => DocumentController::destroy($id, $auth),
        default  => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405),
    };
}
if (preg_match('#^/api/v1/documents/(\d+)/approve$#', $uri, $m) && $method === 'POST') {
    $auth = AuthMiddleware::handle();
    DocumentController::approve((int)$m[1], $auth);
}
if (preg_match('#^/api/v1/documents/(\d+)/archive$#', $uri, $m) && $method === 'POST') {
    $auth = AuthMiddleware::handle();
    DocumentController::archive((int)$m[1], $auth);
}
if (preg_match('#^/api/v1/documents/(\d+)/download$#', $uri, $m) && $method === 'GET') {
    $auth = AuthMiddleware::handle();
    DocumentController::download((int)$m[1], $auth);
}

// Admin routes (admin only)
if ($uri === '/api/v1/admin/users'         && $method === 'GET') {
    AuthMiddleware::handle(true);
    AdminController::users();
}
if (preg_match('#^/api/v1/admin/users/(\d+)$#', $uri, $m) && $method === 'PATCH') {
    AuthMiddleware::handle(true);
    AdminController::updateUser((int)$m[1]);
}
if ($uri === '/api/v1/admin/logs'          && $method === 'GET') {
    AuthMiddleware::handle(true);
    AdminController::logs();
}
if ($uri === '/api/v1/admin/stats'         && $method === 'GET') {
    AuthMiddleware::handle(true);
    AdminController::stats();
}

// ── Accounts (Chart of Accounts) ──────────────────────────────────────────────
if ($uri === '/api/v1/accounts' && $method === 'GET') {
    $auth = AuthMiddleware::handle();
    AccountController::index($auth);
}
if ($uri === '/api/v1/accounts' && $method === 'POST') {
    $auth = AuthMiddleware::handle();
    AccountController::store($auth);
}
if (preg_match('#^/api/v1/accounts/(\d+)$#', $uri, $m)) {
    $auth = AuthMiddleware::handle();
    match ($method) {
        'PATCH'  => AccountController::update((int)$m[1], $auth),
        'DELETE' => AccountController::destroy((int)$m[1], $auth),
        default  => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405),
    };
}

// ── Journal Entries ────────────────────────────────────────────────────────────
if ($uri === '/api/v1/journal' && $method === 'GET') {
    $auth = AuthMiddleware::handle();
    JournalController::index($auth);
}
if ($uri === '/api/v1/journal' && $method === 'POST') {
    $auth = AuthMiddleware::handle();
    JournalController::store($auth);
}
if (preg_match('#^/api/v1/journal/(\d+)$#', $uri, $m)) {
    $auth = AuthMiddleware::handle();
    match ($method) {
        'GET'    => JournalController::show((int)$m[1], $auth),
        'PATCH'  => JournalController::update((int)$m[1], $auth),
        'DELETE' => JournalController::destroy((int)$m[1], $auth),
        default  => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405),
    };
}
if (preg_match('#^/api/v1/journal/(\d+)/post$#', $uri, $m) && $method === 'POST') {
    $auth = AuthMiddleware::handle();
    JournalController::post((int)$m[1], $auth);
}

// ── Bank Accounts ──────────────────────────────────────────────────────────────
if ($uri === '/api/v1/banks' && $method === 'GET') {
    $auth = AuthMiddleware::handle();
    BankController::index($auth);
}
if ($uri === '/api/v1/banks' && $method === 'POST') {
    $auth = AuthMiddleware::handle();
    BankController::store($auth);
}
if (preg_match('#^/api/v1/banks/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    $auth = AuthMiddleware::handle();
    BankController::destroy((int)$m[1], $auth);
}
if (preg_match('#^/api/v1/banks/(\d+)/transactions$#', $uri, $m) && $method === 'GET') {
    $auth = AuthMiddleware::handle();
    BankController::transactions((int)$m[1], $auth);
}
if (preg_match('#^/api/v1/banks/(\d+)/import$#', $uri, $m) && $method === 'POST') {
    $auth = AuthMiddleware::handle();
    BankController::importCsv((int)$m[1], $auth);
}
if (preg_match('#^/api/v1/banks/(\d+)/reconcile$#', $uri, $m) && $method === 'POST') {
    $auth = AuthMiddleware::handle();
    BankController::reconcile((int)$m[1], $auth);
}

// ── Reports ────────────────────────────────────────────────────────────────────
if ($uri === '/api/v1/reports/profit-loss'   && $method === 'GET') {
    $auth = AuthMiddleware::handle();
    ReportController::profitLoss($auth);
}
if ($uri === '/api/v1/reports/balance-sheet' && $method === 'GET') {
    $auth = AuthMiddleware::handle();
    ReportController::balanceSheet($auth);
}
if ($uri === '/api/v1/reports/trial-balance' && $method === 'GET') {
    $auth = AuthMiddleware::handle();
    ReportController::trialBalance($auth);
}

// Health check
if ($uri === '/api/v1/health' || $uri === '/') {
    Response::json(['status' => 'ok', 'app' => 'Accounts360tech API', 'version' => '1.1.0']);
}

// 404
Response::error('NOT_FOUND', "Route $method $uri not found.", 404);
