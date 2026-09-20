<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Services\JournalService;

class JournalController
{
    // GET /journal
    public static function index(array $auth): never
    {
        $db      = Database::connect();
        $page    = max(1, (int)($_GET['page']    ?? 1));
        $perPage = min(100,(int)($_GET['per_page']?? 25));
        $offset  = ($page - 1) * $perPage;
        $search  = $_GET['search'] ?? '';
        $status  = $_GET['status'] ?? '';
        $dateFrom= $_GET['date_from'] ?? '';
        $dateTo  = $_GET['date_to']   ?? '';
        $isAdmin = ($auth['role'] ?? '') === 'admin';

        $where  = []; $params = [];
        if (!$isAdmin) { $where[] = "je.user_id=?"; $params[] = $auth['sub']; }
        if ($status)   { $where[] = "je.status=?";  $params[] = $status; }
        if ($search)   { $where[] = "(je.reference LIKE ? OR je.description LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
        if ($dateFrom) { $where[] = "je.entry_date>=?"; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = "je.entry_date<=?"; $params[] = $dateTo; }

        $wSql = $where ? "WHERE " . implode(' AND ', $where) : '';

        $cStmt = $db->prepare("SELECT COUNT(*) FROM journal_entries je $wSql");
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT je.*, u.name AS user_name,
                    (SELECT SUM(jl.debit) FROM journal_lines jl WHERE jl.journal_entry_id=je.id) AS total_debit,
                    (SELECT COUNT(*) FROM journal_lines jl WHERE jl.journal_entry_id=je.id) AS line_count
             FROM journal_entries je
             JOIN users u ON u.id=je.user_id
             $wSql
             ORDER BY je.entry_date DESC, je.id DESC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $entries = $stmt->fetchAll();

        Response::json([
            'entries'    => $entries,
            'pagination' => ['total_count'=>$total,'page'=>$page,'per_page'=>$perPage,'total_pages'=>(int)ceil($total/$perPage)],
        ]);
    }

    // GET /journal/{id}
    public static function show(int $id, array $auth): never
    {
        $db    = Database::connect();
        $entry = self::findEntry($db, $id, $auth);

        $stmt = $db->prepare(
            "SELECT jl.*, a.code, a.name AS account_name, a.type AS account_type
             FROM journal_lines jl
             JOIN accounts a ON a.id=jl.account_id
             WHERE jl.journal_entry_id=?
             ORDER BY jl.id ASC"
        );
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll();

        $totalDebit  = array_sum(array_column($lines, 'debit'));
        $totalCredit = array_sum(array_column($lines, 'credit'));

        Response::json([
            'entry'        => $entry,
            'lines'        => $lines,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced'  => abs($totalDebit - $totalCredit) < 0.01,
        ]);
    }

    // POST /journal
    public static function store(array $auth): never
    {
        $body = self::json();
        $v    = (new Validator())
            ->required('entry_date', $body['entry_date'] ?? '')
            ->required('description', $body['description'] ?? '');
        if ($v->fails()) Response::error('VALIDATION_ERROR', 'Validation failed.', 422, $v->errors());

        $lines = $body['lines'] ?? [];
        if (count($lines) < 2) Response::error('INSUFFICIENT_LINES', 'A journal entry requires at least 2 lines.', 422);
        if (!JournalService::validateBalance($lines)) {
            Response::error('UNBALANCED', 'Journal entry is not balanced: total debits must equal total credits.', 422);
        }

        $db = Database::connect();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                "INSERT INTO journal_entries (user_id, entry_date, reference, description, status)
                 VALUES (?,?,?,?,?)"
            );
            $status = ($body['status'] ?? 'draft') === 'posted' ? 'posted' : 'draft';
            $stmt->execute([$auth['sub'], $body['entry_date'], $body['reference'] ?? null, $body['description'], $status]);
            $entryId = (int)$db->lastInsertId();

            self::insertLines($db, $entryId, $lines);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }

        Response::json(['message' => 'Journal entry created.', 'id' => $entryId], 201);
    }

    // PATCH /journal/{id}
    public static function update(int $id, array $auth): never
    {
        $db    = Database::connect();
        $entry = self::findEntry($db, $id, $auth);
        if ($entry['status'] === 'posted') Response::error('LOCKED', 'Posted entries cannot be edited.', 403);

        $body = self::json();
        $set  = []; $params = [];
        foreach (['entry_date','reference','description'] as $f) {
            if (array_key_exists($f, $body)) { $set[] = "$f=?"; $params[] = $body[$f]; }
        }

        $lines = $body['lines'] ?? null;
        if ($lines !== null) {
            if (count($lines) < 2) Response::error('INSUFFICIENT_LINES', 'At least 2 lines required.', 422);
            if (!JournalService::validateBalance($lines)) Response::error('UNBALANCED', 'Debits must equal credits.', 422);
        }

        $db->beginTransaction();
        try {
            if (!empty($set)) {
                $params[] = $id;
                $db->prepare("UPDATE journal_entries SET " . implode(',', $set) . " WHERE id=?")->execute($params);
            }
            if ($lines !== null) {
                $db->prepare("DELETE FROM journal_lines WHERE journal_entry_id=?")->execute([$id]);
                self::insertLines($db, $id, $lines);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }

        Response::json(['message' => 'Journal entry updated.']);
    }

    // POST /journal/{id}/post
    public static function post(int $id, array $auth): never
    {
        $db    = Database::connect();
        $entry = self::findEntry($db, $id, $auth);
        if ($entry['status'] === 'posted') Response::error('ALREADY_POSTED', 'Entry is already posted.', 409);

        // Verify balance before posting
        $stmt = $db->prepare("SELECT SUM(debit) AS d, SUM(credit) AS c FROM journal_lines WHERE journal_entry_id=?");
        $stmt->execute([$id]);
        $sums = $stmt->fetch();
        if (abs((float)$sums['d'] - (float)$sums['c']) >= 0.01) {
            Response::error('UNBALANCED', 'Cannot post: debits do not equal credits.', 422);
        }

        $db->prepare("UPDATE journal_entries SET status='posted' WHERE id=?")->execute([$id]);
        Response::json(['message' => 'Journal entry posted.']);
    }

    // DELETE /journal/{id}
    public static function destroy(int $id, array $auth): never
    {
        $db    = Database::connect();
        $entry = self::findEntry($db, $id, $auth);
        if ($entry['status'] === 'posted') Response::error('LOCKED', 'Posted entries cannot be deleted.', 403);

        $db->prepare("DELETE FROM journal_entries WHERE id=?")->execute([$id]);
        Response::json(['message' => 'Journal entry deleted.']);
    }

    // ── Helpers ───────────────────────────────────────────────

    private static function findEntry(\PDO $db, int $id, array $auth): array
    {
        $isAdmin = ($auth['role'] ?? '') === 'admin';
        $sql     = "SELECT * FROM journal_entries WHERE id=?";
        $params  = [$id];
        if (!$isAdmin) { $sql .= " AND user_id=?"; $params[] = $auth['sub']; }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $e = $stmt->fetch();
        if (!$e) Response::error('NOT_FOUND', 'Journal entry not found.', 404);
        return $e;
    }

    private static function insertLines(\PDO $db, int $entryId, array $lines): void
    {
        $stmt = $db->prepare(
            "INSERT INTO journal_lines (journal_entry_id, account_id, description, debit, credit, currency)
             VALUES (?,?,?,?,?,?)"
        );
        foreach ($lines as $line) {
            $stmt->execute([
                $entryId,
                (int)($line['account_id']),
                $line['description'] ?? null,
                round((float)($line['debit']  ?? 0), 2),
                round((float)($line['credit'] ?? 0), 2),
                $line['currency'] ?? 'USD',
            ]);
        }
    }

    private static function json(): array
    {
        $raw = file_get_contents('php://input');
        return $raw ? (json_decode($raw, true) ?? []) : [];
    }
}
