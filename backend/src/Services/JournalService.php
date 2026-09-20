<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

class JournalService
{
    // Expense category → account code mapping (matches bookkeeping.sql seed)
    private const CATEGORY_ACCOUNT_MAP = [
        'Meals & Entertainment'    => '5001',
        'Travel & Transport'       => '5002',
        'Software & Subscriptions' => '5003',
        'Advertising & Marketing'  => '5004',
        'Office Supplies'          => '5005',
        'Utilities'                => '5006',
        'Professional Services'    => '5007',
        'Equipment & Hardware'     => '5008',
        'Rent & Facilities'        => '5009',
        'Other / Uncategorized'    => '5010',
    ];

    /**
     * Auto-generate a posted journal entry when a document is approved.
     * Debit: Expense account (from category)
     * Credit: Accounts Payable (2001)
     */
    public static function autoGenerate(int $documentId, int $userId): int
    {
        $db = Database::connect();

        // Load extracted data
        $stmt = $db->prepare(
            "SELECT e.*, d.original_file_name
             FROM extracted_data e
             JOIN documents d ON d.id = e.document_id
             WHERE e.document_id = ?"
        );
        $stmt->execute([$documentId]);
        $data = $stmt->fetch();

        if (!$data || !$data['total_amount']) return 0;

        $amount   = (float) $data['total_amount'];
        $date     = $data['document_date'] ?? date('Y-m-d');
        $currency = $data['currency'] ?? 'USD';
        $category = $data['category'] ?? 'Other / Uncategorized';
        $supplier = $data['supplier_name'] ?? 'Unknown Supplier';

        // Resolve expense account
        $expenseCode = self::CATEGORY_ACCOUNT_MAP[$category] ?? '5010';
        $expenseAcct = self::resolveAccount($db, $expenseCode, $userId);
        $apAcct      = self::resolveAccount($db, '2001', $userId); // Accounts Payable

        if (!$expenseAcct || !$apAcct) return 0;

        // Check no journal entry already exists for this document
        $exists = $db->prepare("SELECT id FROM journal_entries WHERE document_id=?")->execute([$documentId]);
        $exists = $db->prepare("SELECT id FROM journal_entries WHERE document_id=?");
        $exists->execute([$documentId]);
        if ($exists->fetch()) return 0; // already generated

        $db->beginTransaction();
        try {
            // Insert journal entry header
            $ref  = 'DOC-' . str_pad((string)$documentId, 6, '0', STR_PAD_LEFT);
            $desc = "Expense: $supplier — {$data['original_file_name']}";

            $stmt = $db->prepare(
                "INSERT INTO journal_entries (user_id, document_id, entry_date, reference, description, status)
                 VALUES (?,?,?,?,?,'posted')"
            );
            $stmt->execute([$userId, $documentId, $date, $ref, $desc]);
            $entryId = (int) $db->lastInsertId();

            // Debit expense account
            $db->prepare(
                "INSERT INTO journal_lines (journal_entry_id, account_id, description, debit, credit, currency)
                 VALUES (?,?,?,?,0,?)"
            )->execute([$entryId, $expenseAcct['id'], $category, $amount, $currency]);

            // Credit Accounts Payable
            $db->prepare(
                "INSERT INTO journal_lines (journal_entry_id, account_id, description, debit, credit, currency)
                 VALUES (?,?,?,0,?,?)"
            )->execute([$entryId, $apAcct['id'], "Payable to $supplier", $amount, $currency]);

            // If tax amount exists, add tax line (Credit Sales Tax Payable 2200)
            $taxAmount = (float)($data['tax_amount'] ?? 0);
            if ($taxAmount > 0) {
                $taxAcct = self::resolveAccount($db, '2200', $userId);
                if ($taxAcct) {
                    // Adjust expense debit: net amount
                    $netAmount = $amount - $taxAmount;
                    // Update expense debit line
                    $db->prepare("UPDATE journal_lines SET debit=? WHERE journal_entry_id=? AND account_id=?")
                       ->execute([$netAmount, $entryId, $expenseAcct['id']]);

                    // Add tax debit line
                    $db->prepare(
                        "INSERT INTO journal_lines (journal_entry_id, account_id, description, debit, credit, currency)
                         VALUES (?,?,?,?,0,?)"
                    )->execute([$entryId, $taxAcct['id'], 'Tax / VAT', $taxAmount, $currency]);
                }
            }

            $db->commit();
            return $entryId;
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("JournalService::autoGenerate failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Validate that debit total == credit total before inserting a manual journal.
     */
    public static function validateBalance(array $lines): bool
    {
        $totalDebit  = 0.0;
        $totalCredit = 0.0;
        foreach ($lines as $line) {
            $totalDebit  += (float)($line['debit']  ?? 0);
            $totalCredit += (float)($line['credit'] ?? 0);
        }
        // Allow 0.01 floating-point tolerance
        return abs($totalDebit - $totalCredit) < 0.01 && $totalDebit > 0;
    }

    /**
     * Resolve account by code, preferring user-specific over shared.
     */
    public static function resolveAccount(\PDO $db, string $code, int $userId): ?array
    {
        // Prefer user-specific account first
        $stmt = $db->prepare("SELECT * FROM accounts WHERE code=? AND user_id=? AND is_active=1 LIMIT 1");
        $stmt->execute([$code, $userId]);
        $row = $stmt->fetch();
        if ($row) return $row;

        // Fall back to shared (system) account
        $stmt = $db->prepare("SELECT * FROM accounts WHERE code=? AND user_id IS NULL AND is_active=1 LIMIT 1");
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }
}
