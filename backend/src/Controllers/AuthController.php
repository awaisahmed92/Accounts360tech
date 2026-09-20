<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\Config;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Services\JWTService;

class AuthController
{
    // POST /auth/register
    public static function register(): never
    {
        $body = self::json();
        $v    = (new Validator())
            ->required('name',     $body['name']     ?? '')
            ->required('email',    $body['email']    ?? '')
            ->email('email',       $body['email']    ?? '')
            ->required('password', $body['password'] ?? '')
            ->password('password', $body['password'] ?? '')
            ->maxLength('name',    $body['name']     ?? '', 100);

        if ($v->fails()) {
            Response::error('VALIDATION_ERROR', 'Validation failed.', 422, $v->errors());
        }

        $db   = Database::connect();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([strtolower(trim($body['email']))]);
        if ($stmt->fetch()) {
            Response::error('EMAIL_TAKEN', 'This email address is already registered.', 409);
        }

        $hash = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $db->prepare("INSERT INTO users (name, email, password_hash) VALUES (?,?,?)");
        $stmt->execute([trim($body['name']), strtolower(trim($body['email'])), $hash]);
        $userId = (int) $db->lastInsertId();

        $user = ['id' => $userId, 'name' => trim($body['name']), 'email' => strtolower(trim($body['email'])), 'role' => 'user'];
        Response::json(['user' => $user], 201);
    }

    // POST /auth/login
    public static function login(): never
    {
        $body = self::json();
        $v    = (new Validator())
            ->required('email',    $body['email']    ?? '')
            ->required('password', $body['password'] ?? '');
        if ($v->fails()) {
            Response::error('VALIDATION_ERROR', 'Email and password are required.', 422, $v->errors());
        }

        $db   = Database::connect();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([strtolower(trim($body['email']))]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active']) {
            Response::error('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        }

        // Account lock check
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            Response::error('ACCOUNT_LOCKED', 'Account is temporarily locked. Try again later.', 429);
        }

        if (!password_verify($body['password'], $user['password_hash'])) {
            $count = (int)$user['failed_login_count'] + 1;
            $lock  = $count >= 10 ? date('Y-m-d H:i:s', time() + 900) : null;
            $db->prepare("UPDATE users SET failed_login_count=?, locked_until=? WHERE id=?")
               ->execute([$count, $lock, $user['id']]);
            Response::error('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        }

        // Reset fail count + update last login
        $db->prepare("UPDATE users SET failed_login_count=0, locked_until=NULL, last_login_at=NOW() WHERE id=?")
           ->execute([$user['id']]);

        $token        = JWTService::generate(['sub' => $user['id'], 'role' => $user['role'], 'name' => $user['name']]);
        $refreshToken = JWTService::generateRefreshToken();
        $expiry       = (int) Config::get('REFRESH_TOKEN_EXPIRY', 2592000);

        $db->prepare("INSERT INTO refresh_tokens (user_id, token, expires_at) VALUES (?,?,?)")
           ->execute([$user['id'], $refreshToken, date('Y-m-d H:i:s', time() + $expiry)]);

        Response::json([
            'token'         => $token,
            'refresh_token' => $refreshToken,
            'expires_in'    => (int) Config::get('JWT_EXPIRY', 86400),
            'user'          => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']],
        ]);
    }

    // POST /auth/refresh
    public static function refresh(): never
    {
        $body  = self::json();
        $token = $body['refresh_token'] ?? '';

        if (empty($token)) {
            Response::error('VALIDATION_ERROR', 'refresh_token is required.', 422);
        }

        $db   = Database::connect();
        $stmt = $db->prepare("SELECT rt.*, u.role, u.name, u.is_active FROM refresh_tokens rt JOIN users u ON u.id = rt.user_id WHERE rt.token = ?");
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row || strtotime($row['expires_at']) < time() || !$row['is_active']) {
            Response::error('INVALID_REFRESH_TOKEN', 'Refresh token is invalid or expired.', 401);
        }

        $newToken = JWTService::generate(['sub' => $row['user_id'], 'role' => $row['role'], 'name' => $row['name']]);
        Response::json(['token' => $newToken, 'expires_in' => (int) Config::get('JWT_EXPIRY', 86400)]);
    }

    // POST /auth/logout
    public static function logout(array $auth): never
    {
        $body  = self::json();
        $token = $body['refresh_token'] ?? '';

        $db = Database::connect();
        if (!empty($token)) {
            $db->prepare("DELETE FROM refresh_tokens WHERE token = ? AND user_id = ?")
               ->execute([$token, $auth['sub']]);
        }
        Response::json(['message' => 'Logged out successfully.']);
    }

    // POST /auth/forgot-password
    public static function forgotPassword(): never
    {
        $body  = self::json();
        $email = strtolower(trim($body['email'] ?? ''));

        if (empty($email)) {
            Response::error('VALIDATION_ERROR', 'Email is required.', 422);
        }

        $db   = Database::connect();
        $stmt = $db->prepare("SELECT id, name FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always return success to prevent email enumeration
        if ($user) {
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $db->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)")
               ->execute([$user['id'], $tokenHash, date('Y-m-d H:i:s', time() + 3600)]);
            // In production: send email with reset link containing $rawToken
            // For dev: log the token
            error_log("Password reset token for $email: $rawToken");
        }

        Response::json(['message' => 'If that email exists, a reset link has been sent.']);
    }

    // POST /auth/reset-password
    public static function resetPassword(): never
    {
        $body  = self::json();
        $token = $body['token']    ?? '';
        $pass  = $body['password'] ?? '';

        $v = (new Validator())->required('token', $token)->required('password', $pass)->password('password', $pass);
        if ($v->fails()) Response::error('VALIDATION_ERROR', 'Validation failed.', 422, $v->errors());

        $tokenHash = hash('sha256', $token);
        $db        = Database::connect();
        $stmt      = $db->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()");
        $stmt->execute([$tokenHash]);
        $reset = $stmt->fetch();

        if (!$reset) Response::error('INVALID_TOKEN', 'Reset token is invalid or expired.', 400);

        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("UPDATE users SET password_hash = ?, failed_login_count = 0, locked_until = NULL WHERE id = ?")->execute([$hash, $reset['user_id']]);
        $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")->execute([$reset['id']]);

        Response::json(['message' => 'Password has been reset. Please log in.']);
    }

    private static function json(): array
    {
        $raw = file_get_contents('php://input');
        return $raw ? (json_decode($raw, true) ?? []) : [];
    }
}
