<?php
/**
 * Accounts360tech — Background Document Processing Worker
 *
 * Run via cron (every 5 seconds is too frequent for cron; use a loop):
 *   php worker.php &
 *
 * Or register as a Windows Scheduled Task every minute:
 *   C:\wamp64\bin\php\php8.2.29\php.exe C:\wamp64\www\Accounts360tech\backend\worker\worker.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Config.php';
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/DocumentAIService.php';

use App\Config\Config;
use App\Config\Database;
use App\Services\DocumentAIService;

Config::load(__DIR__ . '/../.env');

$db          = Database::connect();
$maxRetries  = 3;
$backoffSecs = 30;

echo "[" . date('Y-m-d H:i:s') . "] Worker started.\n";

while (true) {
    try {
        processNextItem($db, $maxRetries, $backoffSecs);
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] FATAL: " . $e->getMessage() . "\n";
    }
    sleep(5);
}

function processNextItem(\PDO $db, int $maxRetries, int $backoffSecs): void
{
    // Claim one pending item (skip items in back-off)
    $stmt = $db->prepare(
        "SELECT pq.id AS queue_id, pq.document_id, pq.retry_count,
                d.file_path, d.file_type, d.user_id
         FROM processing_queue pq
         JOIN documents d ON d.id = pq.document_id
         WHERE pq.status = 'pending'
           AND (pq.claimed_at IS NULL OR pq.claimed_at < DATE_SUB(NOW(), INTERVAL ? SECOND))
         ORDER BY pq.created_at ASC
         LIMIT 1
         FOR UPDATE SKIP LOCKED"
    );
    $stmt->execute([$backoffSecs]);
    $item = $stmt->fetch();

    if (!$item) return; // nothing to do

    $queueId    = $item['queue_id'];
    $documentId = $item['document_id'];

    // Mark as processing
    $db->prepare("UPDATE processing_queue SET status='processing', claimed_at=NOW() WHERE id=?")
       ->execute([$queueId]);
    $db->prepare("UPDATE documents SET status='processing' WHERE id=?")
       ->execute([$documentId]);

    $startedAt = date('Y-m-d H:i:s');
    $startMs   = microtime(true);

    echo "[" . date('Y-m-d H:i:s') . "] Processing document #$documentId...\n";

    try {
        $result    = DocumentAIService::process($item['file_path'], $item['file_type']);
        $latencyMs = (int)((microtime(true) - $startMs) * 1000);

        // Upsert extracted_data
        $db->prepare(
            "INSERT INTO extracted_data
                (document_id, supplier_name, document_date, total_amount, tax_amount, currency, category, confidence_score)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                supplier_name=VALUES(supplier_name), document_date=VALUES(document_date),
                total_amount=VALUES(total_amount), tax_amount=VALUES(tax_amount),
                currency=VALUES(currency), category=VALUES(category),
                confidence_score=VALUES(confidence_score)"
        )->execute([
            $documentId,
            $result['supplier_name']    ?? null,
            $result['document_date']    ?? null,
            $result['total_amount']     ?? null,
            $result['tax_amount']       ?? null,
            $result['currency']         ?? 'USD',
            $result['category']         ?? 'Other / Uncategorized',
            $result['confidence_score'] ?? null,
        ]);

        // Get extracted_data ID
        $edId = (int) $db->prepare("SELECT id FROM extracted_data WHERE document_id=?")->execute([$documentId])
            ? $db->query("SELECT id FROM extracted_data WHERE document_id=$documentId")->fetchColumn()
            : null;

        // Insert line items
        if ($edId && !empty($result['line_items'])) {
            $db->prepare("DELETE FROM line_items WHERE extracted_data_id=?")->execute([$edId]);
            $liStmt = $db->prepare("INSERT INTO line_items (extracted_data_id, description, quantity, unit_price, line_total, sort_order) VALUES (?,?,?,?,?,?)");
            foreach ($result['line_items'] as $i => $li) {
                $liStmt->execute([$edId, $li['description'] ?? null, $li['quantity'] ?? null, $li['unit_price'] ?? null, $li['line_total'] ?? null, $i]);
            }
        }

        // Update statuses
        $db->prepare("UPDATE documents SET status='ready' WHERE id=?")->execute([$documentId]);
        $db->prepare("UPDATE processing_queue SET status='done', completed_at=NOW() WHERE id=?")->execute([$queueId]);

        // Log success
        $db->prepare("INSERT INTO processing_logs (document_id, attempt_number, ai_http_status, ai_response_ms, started_at, finished_at) VALUES (?,?,200,?,?,NOW())")
           ->execute([$documentId, $item['retry_count'] + 1, $latencyMs, $startedAt]);

        echo "[" . date('Y-m-d H:i:s') . "] Document #$documentId done in {$latencyMs}ms.\n";

    } catch (\Throwable $e) {
        $latencyMs   = (int)((microtime(true) - $startMs) * 1000);
        $retryCount  = (int)$item['retry_count'] + 1;
        $httpStatus  = $e->getCode() ?: 0;

        $db->prepare("INSERT INTO processing_logs (document_id, attempt_number, ai_http_status, ai_response_ms, error_message, started_at, finished_at) VALUES (?,?,?,?,?,?,NOW())")
           ->execute([$documentId, $retryCount, $httpStatus ?: null, $latencyMs, $e->getMessage(), $startedAt]);

        if ($retryCount >= $maxRetries) {
            $db->prepare("UPDATE documents SET status='error' WHERE id=?")->execute([$documentId]);
            $db->prepare("UPDATE processing_queue SET status='failed', retry_count=?, last_error=?, completed_at=NOW() WHERE id=?")
               ->execute([$retryCount, $e->getMessage(), $queueId]);
            echo "[" . date('Y-m-d H:i:s') . "] Document #$documentId FAILED after $retryCount attempts: " . $e->getMessage() . "\n";
        } else {
            $db->prepare("UPDATE processing_queue SET status='pending', retry_count=?, last_error=?, claimed_at=NULL WHERE id=?")
               ->execute([$retryCount, $e->getMessage(), $queueId]);
            echo "[" . date('Y-m-d H:i:s') . "] Document #$documentId error (attempt $retryCount): " . $e->getMessage() . "\n";
        }
    }
}
