<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Helpers\Validator;

class BankController
{
    // GET /banks
    public static function index(array $auth): never
    {
        $db   = Database::connect();
        $stmt = $db->prepare(
            "SELECT ba.*,
                    (SELECT COUNT(*) FROM bank_transactions bt WHERE bt.bank_account_id=ba.id) AS txn_count,
                    (SELECT COUNT(*) FROM bank_transactions bt WHERE bt.bank_account_id=ba.id AND bt.is_reconciled=0) AS unreconciled_count
             FROM bank_accounts ba
             WHERE ba.user_id=? AND ba.is_active=1
             ORDER BY ba.created_at DESC"
        );
        $stmt->execute([$auth['sub']]);
        Response::json(['bank_accounts' => $stmt->fetchAll()]);
    }

    // POST /banks
    public static function store(array $auth): never
    {
        $body = self::json();
        $v    = (new Validator())
            ->required('name', $body['name'] ?? '')
            ->maxLength('name', $body['name'] ?? '', 150);
        if ($v->fails()) Response::error('VALIDATION_ERROR', 'Validation failed.', 422, $v->errors());

        $db   = Database::connect();
        $stmt = $db->prepare(
            "INSERT INTO bank_accounts (user_id, name, bank_name, account_number, currency, opening_balance, current_balance)
             VALUES (?,?,?,?,?,?,?)"
        );
        $opening = round((float)($body['opening_balance'] ?? 0), 2);
        $stmt->execute([
            $auth['sub'],
            trim($body['name']),
            $body['bank_name']       ?? null,
            $body['account_number']  ?? null,
            $body['currency']        ?? 'USD',
            $opening,
            $opening,
        ]);
        $id  = (int)$db->lastInsertId();
        $row = $db->query("SELECT * FROM bank_accounts WHERE id=$id")->fetch();
        Response::json(['bank_account' => $row], 201);
    }

    // GET /banks/{id}/transactions
    public static function transactions(int $bankId, array $auth): never
    {
        $db   = Database::connect();
        self::findBank($db, $bankId, $auth);

        $page     = max(1,(int)($_GET['page']    ?? 1));
        $perPage  = min(200,(int)($_GET['per_page']?? 50));
        $offset   = ($page-1)*$perPage;
        $reconciled = $_GET['reconciled'] ?? '';
        $dateFrom   = $_GET['date_from']  ?? '';
        $dateTo     = $_GET['date_to']    ?? '';

        $where  = ["bt.bank_account_id=?"]; $params = [$bankId];
        if ($reconciled !== '') { $where[] = "bt.is_reconciled=?"; $params[] = (int)$reconciled; }
        if ($dateFrom)          { $where[] = "bt.txn_date>=?";     $params[] = $dateFrom; }
        if ($dateTo)            { $where[] = "bt.txn_date<=?";     $params[] = $dateTo; }

        $wSql = "WHERE " . implode(' AND ', $where);
        $cStmt = $db->prepare("SELECT COUNT(*) FROM bank_transactions bt $wSql");
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $stmt = $db->prepare("SELECT * FROM bank_transactions bt $wSql ORDER BY bt.txn_date DESC, bt.id DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $txns = $stmt->fetchAll();

        Response::json([
            'transactions' => $txns,
            'pagination'   => ['total_count'=>$total,'page'=>$page,'per_page'=>$perPage,'total_pages'=>(int)ceil($total/$perPage)],
        ]);
    }

    // POST /banks/{id}/import  — CSV bank statement upload
    public static function importCsv(int $bankId, array $auth): never
    {
        $db = Database::connect();
        self::findBank($db, $bankId, $auth);

        if (empty($_FILES['file']['tmp_name'])) {
            Response::error('NO_FILE', 'CSV file is required.', 422);
        }

        $file = $_FILES['file']['tmp_name'];
        $fh   = fopen($file, 'r');
        if (!$fh) Response::error('FILE_ERROR', 'Cannot read uploaded file.', 500);

        $header   = null;
        $imported = 0;
        $errors   = [];
        $rowNum   = 0;

        $stmt = $db->prepare(
            "INSERT INTO bank_transactions (bank_account_id, txn_date, description, amount, type, reference)
             VALUES (?,?,?,?,?,?)"
        );

        while (($row = fgetcsv($fh)) !== false) {
            $rowNum++;
            if ($rowNum === 1) {
                // Normalize header names: date, description, debit, credit, amount, reference
                $header = array_map(fn($h) => strtolower(trim($h)), $row);
                continue;
            }
            if (!$header || empty(array_filter($row))) continue;

            $data = array_combine($header, array_pad($row, count($header), ''));

            // Support two CSV formats:
            // Format A: date, description, debit, credit
            // Format B: date, description, amount (positive=credit, negative=debit)
            $date = self::parseDate($data['date'] ?? '');
            if (!$date) { $errors[] = "Row $rowNum: invalid date."; continue; }

            $desc   = trim($data['description'] ?? $data['details'] ?? $data['narration'] ?? 'Bank transaction');
            $ref    = $data['reference'] ?? $data['ref'] ?? null;
            $amount = 0.0;
            $type   = 'debit';

            if (isset($data['debit']) || isset($data['credit'])) {
                $debit  = abs((float)preg_replace('/[^0-9.-]/', '', $data['debit']  ?? '0'));
                $credit = abs((float)preg_replace('/[^0-9.-]/', '', $data['credit'] ?? '0'));
                if ($credit > 0) { $amount = $credit; $type = 'credit'; }
                elseif ($debit > 0) { $amount = $debit; $type = 'debit'; }
                else continue;
            } elseif (isset($data['amount'])) {
                $raw = (float)preg_replace('/[^0-9.-]/', '', $data['amount']);
                $amount = abs($raw);
                $type   = $raw >= 0 ? 'credit' : 'debit';
            } else {
                $errors[] = "Row $rowNum: no amount column found.";
                continue;
            }

            try {
                $stmt->execute([$bankId, $date, $desc, $amount, $type, $ref]);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row $rowNum: " . $e->getMessage();
            }
        }
        fclose($fh);

        // Recalculate current_balance
        self::recalcBalance($db, $bankId);

        Response::json(['imported' => $imported, 'errors' => $errors], 201);
    }

    // POST /banks/{id}/reconcile
    public static function reconcile(int $bankId, array $auth): never
    {
        $db = Database::connect();
        self::findBank($db, $bankId, $auth);

        $body   = self::json();
        $ids    = array_map('intval', $body['transaction_ids'] ?? []);
        if (empty($ids)) Response::error('NO_IDS', 'transaction_ids is required.', 422);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "UPDATE bank_transactions
             SET is_reconciled=1
             WHERE id IN ($placeholders) AND bank_account_id=?"
        );
        $stmt->execute([...$ids, $bankId]);

        Response::json(['message' => 'Transactions reconciled.', 'count' => $stmt->rowCount()]);
    }

    // DELETE /banks/{id}
    public static function destroy(int $bankId, array $auth): never
    {
        $db = Database::connect();
        self::findBank($db, $bankId, $auth);
        $db->prepare("UPDATE bank_accounts SET is_active=0 WHERE id=?")->execute([$bankId]);
        Response::json(['message' => 'Bank account deactivated.']);
    }

    // ── Helpers ───────────────────────────────────────────────

    private static function findBank(\PDO $db, int $id, array $auth): array
    {
        $stmt = $db->prepare("SELECT * FROM bank_accounts WHERE id=? AND user_id=? AND is_active=1");
        $stmt->execute([$id, $auth['sub']]);
        $b = $stmt->fetch();
        if (!$b) Response::error('NOT_FOUND', 'Bank account not found.', 404);
        return $b;
    }

    private static function recalcBalance(\PDO $db, int $bankId): void
    {
        $stmt = $db->prepare(
            "SELECT
                (SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE bank_account_id=? AND type='credit') -
                (SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE bank_account_id=? AND type='debit') AS net"
        );
        $stmt->execute([$bankId, $bankId]);
        $net = (float)$stmt->fetchColumn();

        $opening = (float)$db->query("SELECT opening_balance FROM bank_accounts WHERE id=$bankId")->fetchColumn();
        $db->prepare("UPDATE bank_accounts SET current_balance=? WHERE id=?")->execute([$opening + $net, $bankId]);
    }

    private static function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (!$raw) return null;
        foreach (['Y-m-d','d/m/Y','m/d/Y','d-m-Y','d M Y','Y/m/d'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $raw);
            if ($dt) return $dt->format('Y-m-d');
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private static function json(): array
    {
        $raw = file_get_contents('php://input');
        return $raw ? (json_decode($raw, true) ?? []) : [];
    }
}
