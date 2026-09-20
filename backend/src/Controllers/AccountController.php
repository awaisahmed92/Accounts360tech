<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Helpers\Validator;

class AccountController
{
    // GET /accounts
    public static function index(array $auth): never
    {
        $db     = Database::connect();
        $userId = $auth['sub'];
        $type   = $_GET['type'] ?? '';

        $where  = "(user_id = ? OR user_id IS NULL) AND is_active = 1";
        $params = [$userId];
        if ($type && in_array($type, ['Asset','Liability','Equity','Income','Expense'])) {
            $where  .= " AND type = ?";
            $params[] = $type;
        }

        $stmt = $db->prepare(
            "SELECT id, code, name, type, sub_type, description, parent_id, is_active, is_system,
                    (user_id IS NULL) AS is_default
             FROM accounts
             WHERE $where
             ORDER BY code ASC"
        );
        $stmt->execute($params);
        $accounts = $stmt->fetchAll();

        // Group by type for structured response
        $grouped = [];
        foreach ($accounts as $a) {
            $grouped[$a['type']][] = $a;
        }

        Response::json(['accounts' => $accounts, 'grouped' => $grouped]);
    }

    // POST /accounts
    public static function store(array $auth): never
    {
        $body = self::json();
        $v    = (new Validator())
            ->required('code', $body['code'] ?? '')
            ->required('name', $body['name'] ?? '')
            ->required('type', $body['type'] ?? '')
            ->maxLength('code', $body['code'] ?? '', 20)
            ->maxLength('name', $body['name'] ?? '', 150);

        if ($v->fails()) Response::error('VALIDATION_ERROR', 'Validation failed.', 422, $v->errors());

        $type = $body['type'] ?? '';
        if (!in_array($type, ['Asset','Liability','Equity','Income','Expense'])) {
            Response::error('INVALID_TYPE', 'Account type must be Asset, Liability, Equity, Income, or Expense.', 422);
        }

        $db = Database::connect();

        // Check code uniqueness for this user
        $stmt = $db->prepare("SELECT id FROM accounts WHERE code = ? AND (user_id = ? OR user_id IS NULL)");
        $stmt->execute([$body['code'], $auth['sub']]);
        if ($stmt->fetch()) Response::error('CODE_TAKEN', 'Account code already exists.', 409);

        $stmt = $db->prepare(
            "INSERT INTO accounts (user_id, code, name, type, sub_type, description, parent_id)
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $auth['sub'],
            trim($body['code']),
            trim($body['name']),
            $type,
            $body['sub_type']   ?? null,
            $body['description']?? null,
            $body['parent_id']  ?? null,
        ]);

        $id   = (int) $db->lastInsertId();
        $row  = $db->prepare("SELECT * FROM accounts WHERE id=?")->execute([$id]);
        $acct = $db->query("SELECT * FROM accounts WHERE id=$id")->fetch();
        Response::json(['account' => $acct], 201);
    }

    // PATCH /accounts/{id}
    public static function update(int $id, array $auth): never
    {
        $db   = Database::connect();
        $acct = self::findAccount($db, $id, $auth);

        if ($acct['is_system']) {
            Response::error('SYSTEM_ACCOUNT', 'System accounts cannot be modified.', 403);
        }

        $body   = self::json();
        $fields = ['name','sub_type','description','parent_id','is_active'];
        $set    = []; $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) { $set[] = "$f=?"; $params[] = $body[$f]; }
        }
        if (empty($set)) Response::error('NO_FIELDS', 'No fields to update.', 422);

        $params[] = $id;
        $db->prepare("UPDATE accounts SET " . implode(',', $set) . " WHERE id=?")->execute($params);
        Response::json(['message' => 'Account updated.']);
    }

    // DELETE /accounts/{id}  (soft-deactivate)
    public static function destroy(int $id, array $auth): never
    {
        $db   = Database::connect();
        $acct = self::findAccount($db, $id, $auth);

        if ($acct['is_system']) Response::error('SYSTEM_ACCOUNT', 'System accounts cannot be deleted.', 403);

        // Check if account has journal lines
        $stmt = $db->prepare("SELECT COUNT(*) FROM journal_lines WHERE account_id=?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            Response::error('ACCOUNT_IN_USE', 'Cannot delete account with existing journal entries.', 409);
        }

        $db->prepare("UPDATE accounts SET is_active=0 WHERE id=?")->execute([$id]);
        Response::json(['message' => 'Account deactivated.']);
    }

    private static function findAccount(\PDO $db, int $id, array $auth): array
    {
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id=? AND (user_id=? OR user_id IS NULL) AND is_active=1");
        $stmt->execute([$id, $auth['sub']]);
        $a = $stmt->fetch();
        if (!$a) Response::error('NOT_FOUND', 'Account not found.', 404);
        return $a;
    }

    private static function json(): array
    {
        $raw = file_get_contents('php://input');
        return $raw ? (json_decode($raw, true) ?? []) : [];
    }
}
