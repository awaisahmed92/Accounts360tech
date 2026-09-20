<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class AdminController
{
    // GET /admin/users
    public static function users(): never
    {
        $db   = Database::connect();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per  = min(100, (int)($_GET['per_page'] ?? 25));
        $off  = ($page - 1) * $per;

        $total = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $stmt  = $db->prepare("SELECT id, name, email, role, is_active, last_login_at, created_at,
                                      (SELECT COUNT(*) FROM documents WHERE user_id = users.id AND deleted_at IS NULL) AS doc_count
                               FROM users ORDER BY created_at DESC LIMIT $per OFFSET $off");
        $stmt->execute();
        $users = $stmt->fetchAll();

        Response::json(['users' => $users, 'pagination' => ['total_count' => $total, 'page' => $page, 'per_page' => $per, 'total_pages' => (int)ceil($total/$per)]]);
    }

    // PATCH /admin/users/{id}
    public static function updateUser(int $id): never
    {
        $body = self::json();
        $db   = Database::connect();

        $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) Response::error('NOT_FOUND', 'User not found.', 404);

        $isActive = isset($body['is_active']) ? (int)(bool)$body['is_active'] : null;
        $role     = isset($body['role']) && in_array($body['role'], ['admin', 'user']) ? $body['role'] : null;

        if ($isActive !== null) {
            $db->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$isActive, $id]);
        }
        if ($role !== null) {
            $db->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role, $id]);
        }

        Response::json(['message' => 'User updated.']);
    }

    // GET /admin/logs
    public static function logs(): never
    {
        $db   = Database::connect();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per  = min(100, (int)($_GET['per_page'] ?? 50));
        $off  = ($page - 1) * $per;

        $total = (int) $db->query("SELECT COUNT(*) FROM processing_logs")->fetchColumn();
        $stmt  = $db->prepare("SELECT pl.*, d.original_file_name, u.name AS user_name
                               FROM processing_logs pl
                               JOIN documents d ON d.id = pl.document_id
                               JOIN users u ON u.id = d.user_id
                               ORDER BY pl.started_at DESC LIMIT $per OFFSET $off");
        $stmt->execute();
        $logs = $stmt->fetchAll();

        Response::json(['logs' => $logs, 'pagination' => ['total_count' => $total, 'page' => $page, 'per_page' => $per, 'total_pages' => (int)ceil($total/$per)]]);
    }

    // GET /admin/stats
    public static function stats(): never
    {
        $db = Database::connect();

        $today     = date('Y-m-d');
        $processed = (int) $db->prepare("SELECT COUNT(*) FROM documents WHERE DATE(created_at) = ? AND deleted_at IS NULL")->execute([$today]) ? 0 : 0;
        $stmtP     = $db->prepare("SELECT COUNT(*) FROM documents WHERE DATE(created_at) = ? AND deleted_at IS NULL");
        $stmtP->execute([$today]);
        $processed = (int) $stmtP->fetchColumn();

        $stmtE = $db->query("SELECT COUNT(*) FROM documents WHERE status='error' AND deleted_at IS NULL");
        $errors = (int) $stmtE->fetchColumn();

        $stmtT = $db->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL");
        $total  = (int) $stmtT->fetchColumn();

        $stmtA = $db->query("SELECT AVG(ai_response_ms) FROM processing_logs WHERE ai_http_status = 200");
        $avgMs  = round((float) $stmtA->fetchColumn(), 0);

        $stmtQ = $db->query("SELECT COUNT(*) FROM processing_queue WHERE status='pending'");
        $queue  = (int) $stmtQ->fetchColumn();

        $stmtU = $db->query("SELECT COUNT(*) FROM users");
        $users  = (int) $stmtU->fetchColumn();

        Response::json([
            'documents_today'     => $processed,
            'total_documents'     => $total,
            'error_count'         => $errors,
            'error_rate_pct'      => $total > 0 ? round($errors / $total * 100, 1) : 0,
            'avg_ai_latency_ms'   => $avgMs,
            'queue_pending'       => $queue,
            'total_users'         => $users,
        ]);
    }

    private static function json(): array
    {
        $raw = file_get_contents('php://input');
        return $raw ? (json_decode($raw, true) ?? []) : [];
    }
}
