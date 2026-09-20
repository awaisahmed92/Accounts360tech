<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class ReportController
{
    /**
     * GET /reports/profit-loss
     * Query params: date_from, date_to, currency (default USD)
     *
     * Logic: Sum of posted journal lines grouped by account (Income & Expense types)
     */
    public static function profitLoss(array $auth): never
    {
        $db       = Database::connect();
        $userId   = $auth['sub'];
        $dateFrom = $_GET['date_from'] ?? date('Y-01-01');
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');

        $rows = self::getAccountBalances($db, $userId, ['Income','Expense'], $dateFrom, $dateTo);

        $income   = [];
        $expenses = [];
        $totalIncome   = 0.0;
        $totalExpenses = 0.0;

        foreach ($rows as $row) {
            // For Income: net = credit - debit (credits increase income)
            // For Expense: net = debit - credit (debits increase expense)
            $net = $row['type'] === 'Income'
                ? (float)$row['total_credit'] - (float)$row['total_debit']
                : (float)$row['total_debit']  - (float)$row['total_credit'];

            $row['net_amount'] = $net;

            if ($row['type'] === 'Income') {
                $income[]     = $row;
                $totalIncome += $net;
            } else {
                $expenses[]     = $row;
                $totalExpenses += $net;
            }
        }

        Response::json([
            'period'          => ['from' => $dateFrom, 'to' => $dateTo],
            'income'          => $income,
            'expenses'        => $expenses,
            'total_income'    => round($totalIncome,   2),
            'total_expenses'  => round($totalExpenses, 2),
            'net_profit'      => round($totalIncome - $totalExpenses, 2),
        ]);
    }

    /**
     * GET /reports/balance-sheet
     * Query params: as_of (date, default today)
     *
     * Logic: Running balance of all posted entries up to as_of date
     * Assets = Liabilities + Equity
     */
    public static function balanceSheet(array $auth): never
    {
        $db     = Database::connect();
        $userId = $auth['sub'];
        $asOf   = $_GET['as_of'] ?? date('Y-m-d');

        $rows = self::getAccountBalances($db, $userId, ['Asset','Liability','Equity'], null, $asOf);

        $sections = ['Asset' => [], 'Liability' => [], 'Equity' => []];
        $totals   = ['Asset' => 0.0, 'Liability' => 0.0, 'Equity' => 0.0];

        foreach ($rows as $row) {
            $type = $row['type'];
            // Assets: debit increases balance; Liabilities/Equity: credit increases
            $net = $type === 'Asset'
                ? (float)$row['total_debit']  - (float)$row['total_credit']
                : (float)$row['total_credit'] - (float)$row['total_debit'];

            $row['net_amount']    = $net;
            $sections[$type][]    = $row;
            $totals[$type]       += $net;
        }

        // Add retained earnings: net income up to as_of date
        $retainedEarnings = self::calcRetainedEarnings($db, $userId, $asOf);
        $totals['Equity'] += $retainedEarnings;

        Response::json([
            'as_of'              => $asOf,
            'assets'             => $sections['Asset'],
            'liabilities'        => $sections['Liability'],
            'equity'             => $sections['Equity'],
            'retained_earnings'  => round($retainedEarnings, 2),
            'total_assets'       => round($totals['Asset'], 2),
            'total_liabilities'  => round($totals['Liability'], 2),
            'total_equity'       => round($totals['Equity'], 2),
            'is_balanced'        => abs($totals['Asset'] - ($totals['Liability'] + $totals['Equity'])) < 0.02,
        ]);
    }

    /**
     * GET /reports/trial-balance
     * Query params: date_from, date_to
     *
     * All accounts with debit and credit totals; last column checks balance.
     */
    public static function trialBalance(array $auth): never
    {
        $db       = Database::connect();
        $userId   = $auth['sub'];
        $dateFrom = $_GET['date_from'] ?? date('Y-01-01');
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');

        $rows = self::getAccountBalances(
            $db, $userId,
            ['Asset','Liability','Equity','Income','Expense'],
            $dateFrom, $dateTo
        );

        // Only include accounts with activity
        $rows = array_filter($rows, fn($r) => (float)$r['total_debit'] > 0 || (float)$r['total_credit'] > 0);

        $grandDebit  = array_sum(array_column($rows, 'total_debit'));
        $grandCredit = array_sum(array_column($rows, 'total_credit'));

        Response::json([
            'period'       => ['from' => $dateFrom, 'to' => $dateTo],
            'accounts'     => array_values($rows),
            'grand_debit'  => round($grandDebit, 2),
            'grand_credit' => round($grandCredit, 2),
            'is_balanced'  => abs($grandDebit - $grandCredit) < 0.02,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────

    private static function getAccountBalances(
        \PDO   $db,
        int    $userId,
        array  $types,
        ?string $dateFrom,
        ?string $dateTo
    ): array {
        $typePlaceholders = implode(',', array_fill(0, count($types), '?'));

        $dateWhere = '';
        $dateParams = [];
        if ($dateFrom) { $dateWhere .= " AND je.entry_date >= ?"; $dateParams[] = $dateFrom; }
        if ($dateTo)   { $dateWhere .= " AND je.entry_date <= ?"; $dateParams[] = $dateTo; }

        $sql = "
            SELECT
                a.id, a.code, a.name, a.type, a.sub_type,
                COALESCE(SUM(jl.debit),  0) AS total_debit,
                COALESCE(SUM(jl.credit), 0) AS total_credit
            FROM accounts a
            LEFT JOIN journal_lines jl ON jl.account_id = a.id
            LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
                AND je.status = 'posted'
                AND (je.user_id = ? OR je.user_id IS NULL)
                $dateWhere
            WHERE a.type IN ($typePlaceholders)
              AND (a.user_id = ? OR a.user_id IS NULL)
              AND a.is_active = 1
            GROUP BY a.id, a.code, a.name, a.type, a.sub_type
            ORDER BY a.code ASC
        ";

        $params = array_merge([$userId], $dateParams, $types, [$userId]);
        $stmt   = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private static function calcRetainedEarnings(\PDO $db, int $userId, string $asOf): float
    {
        // Net income = sum of Income credits - sum of Expense debits (posted, up to asOf)
        $sql = "
            SELECT
                SUM(CASE WHEN a.type='Income'  THEN jl.credit - jl.debit  ELSE 0 END) -
                SUM(CASE WHEN a.type='Expense' THEN jl.debit  - jl.credit ELSE 0 END) AS net
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_entry_id
            JOIN accounts a ON a.id = jl.account_id
            WHERE je.status = 'posted'
              AND je.entry_date <= ?
              AND (je.user_id = ? OR je.user_id IS NULL)
              AND a.type IN ('Income','Expense')
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$asOf, $userId]);
        return (float)($stmt->fetchColumn() ?? 0);
    }
}
