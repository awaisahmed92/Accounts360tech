<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Services\FileService;
use App\Services\ExportService;
use App\Services\JournalService;

class DocumentController
{
    // POST /documents/upload
    public static function upload(array $auth): never
    {
        if (empty($_FILES['files'])) {
            Response::error('NO_FILES', 'No files uploaded.', 422);
        }

        $files = self::normalizeFiles($_FILES['files']);
        if (count($files) > 10) {
            Response::error('TOO_MANY_FILES', 'Maximum 10 files per request.', 422);
        }

        $db       = Database::connect();
        $uploaded = [];
        $errors   = [];

        foreach ($files as $file) {
            try {
                $stored = FileService::store($file, $auth['sub']);

                $db->beginTransaction();
                $stmt = $db->prepare("INSERT INTO documents (user_id, original_file_name, stored_file_name, file_path, file_type, file_size) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$auth['sub'], $stored['original_file_name'], $stored['stored_file_name'], $stored['file_path'], $stored['file_type'], $stored['file_size']]);
                $docId = (int) $db->lastInsertId();

                // Create queue entry
                $db->prepare("INSERT INTO processing_queue (document_id) VALUES (?)")->execute([$docId]);
                $db->commit();

                $uploaded[] = ['id' => $docId, 'file_name' => $stored['original_file_name'], 'status' => 'pending'];
            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $errors[] = ['file' => $file['name'], 'error' => $e->getMessage()];
            }
        }

        Response::json(['uploaded' => count($uploaded), 'documents' => $uploaded, 'errors' => $errors], 201);
    }

    // GET /documents
    public static function index(array $auth): never
    {
        $db = Database::connect();

        $page     = max(1, (int)($_GET['page']     ?? 1));
        $perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
        $status   = $_GET['status']    ?? '';
        $search   = $_GET['search']    ?? '';
        $category = $_GET['category']  ?? '';
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo   = $_GET['date_to']   ?? '';
        $currency = $_GET['currency']  ?? '';
        $sortBy   = in_array($_GET['sort_by'] ?? '', ['created_at','document_date','total_amount','supplier_name']) ? $_GET['sort_by'] : 'created_at';
        $sortDir  = strtoupper($_GET['sort_dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $isAdmin = ($auth['role'] ?? '') === 'admin';

        $where  = ["d.deleted_at IS NULL"];
        $params = [];

        if (!$isAdmin) {
            $where[]  = "d.user_id = ?";
            $params[] = $auth['sub'];
        }
        if ($status)   { $where[] = "d.status = ?";                    $params[] = $status; }
        if ($search)   { $where[] = "e.supplier_name LIKE ?";          $params[] = "%$search%"; }
        if ($category) { $where[] = "e.category = ?";                  $params[] = $category; }
        if ($dateFrom) { $where[] = "e.document_date >= ?";            $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = "e.document_date <= ?";            $params[] = $dateTo; }
        if ($currency) { $where[] = "e.currency = ?";                  $params[] = $currency; }

        $whereClause = implode(' AND ', $where);
        $orderColumn = $sortBy === 'supplier_name' || $sortBy === 'document_date' || $sortBy === 'total_amount'
            ? "e.$sortBy" : "d.$sortBy";

        $countSql = "SELECT COUNT(*) FROM documents d LEFT JOIN extracted_data e ON e.document_id = d.id WHERE $whereClause";
        $total    = (int) $db->prepare($countSql)->execute($params) ? $db->prepare($countSql)->execute($params) : 0;
        $cStmt    = $db->prepare($countSql);
        $cStmt->execute($params);
        $total    = (int) $cStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql    = "SELECT d.id, d.original_file_name AS file_name, d.status, d.file_type, d.created_at,
                          e.supplier_name, e.document_date, e.total_amount, e.tax_amount, e.currency,
                          e.category, e.confidence_score, e.is_approved, e.approved_at
                   FROM documents d
                   LEFT JOIN extracted_data e ON e.document_id = d.id
                   WHERE $whereClause
                   ORDER BY $orderColumn $sortDir
                   LIMIT $perPage OFFSET $offset";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $docs = $stmt->fetchAll();

        Response::json([
            'documents'  => $docs,
            'pagination' => [
                'total_count'  => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'total_pages'  => (int) ceil($total / $perPage),
            ],
        ]);
    }

    // GET /documents/{id}
    public static function show(int $id, array $auth): never
    {
        $db   = Database::connect();
        $doc  = self::findDocument($db, $id, $auth);

        $stmt = $db->prepare("SELECT * FROM extracted_data WHERE document_id = ?");
        $stmt->execute([$id]);
        $extracted = $stmt->fetch();

        $liStmt = $db->prepare("SELECT id, description, quantity, unit_price, line_total FROM line_items WHERE extracted_data_id = ? ORDER BY sort_order");
        $lineItems = [];
        if ($extracted) {
            $liStmt->execute([$extracted['id']]);
            $lineItems = $liStmt->fetchAll();
        }

        // Duplicate check
        $dupWarning = null;
        if ($extracted && $extracted['supplier_name'] && $extracted['total_amount']) {
            $dupStmt = $db->prepare(
                "SELECT d.id, d.original_file_name FROM extracted_data e
                 JOIN documents d ON d.id = e.document_id
                 WHERE e.supplier_name = ? AND e.document_date = ? AND e.total_amount = ?
                   AND d.id != ? AND d.deleted_at IS NULL AND d.user_id = ?"
            );
            $dupStmt->execute([$extracted['supplier_name'], $extracted['document_date'], $extracted['total_amount'], $id, $auth['sub']]);
            $dup = $dupStmt->fetch();
            if ($dup) $dupWarning = ['document_id' => $dup['id'], 'file_name' => $dup['original_file_name']];
        }

        Response::json([
            'document'         => $doc,
            'extracted'        => $extracted,
            'line_items'       => $lineItems,
            'duplicate_warning'=> $dupWarning,
        ]);
    }

    // PATCH /documents/{id}
    public static function update(int $id, array $auth): never
    {
        $db   = Database::connect();
        self::findDocument($db, $id, $auth);

        $body   = self::json();
        $fields = ['supplier_name', 'document_date', 'total_amount', 'tax_amount', 'currency', 'category'];
        $set    = [];
        $params = [];

        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) {
                $set[]    = "$f = ?";
                $params[] = $body[$f];
            }
        }

        if (empty($set)) Response::error('NO_FIELDS', 'No updatable fields provided.', 422);

        $params[] = $id;
        $stmt = $db->prepare("UPDATE extracted_data SET " . implode(', ', $set) . " WHERE document_id = ?");
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            // extracted_data row may not exist yet — insert minimal row
            $db->prepare("INSERT IGNORE INTO extracted_data (document_id) VALUES (?)")->execute([$id]);
            $stmt = $db->prepare("UPDATE extracted_data SET " . implode(', ', array_slice($set, 0, count($set))) . " WHERE document_id = ?");
            $stmt->execute($params);
        }

        Response::json(['message' => 'Document updated.']);
    }

    // POST /documents/{id}/approve
    public static function approve(int $id, array $auth): never
    {
        $db  = Database::connect();
        $doc = self::findDocument($db, $id, $auth);

        if ($doc['status'] !== 'ready') {
            Response::error('NOT_READY', 'Only documents with status "ready" can be approved.', 422);
        }

        $db->prepare("UPDATE documents SET status='ready' WHERE id=?")->execute([$id]);
        $now = date('Y-m-d H:i:s');
        $db->prepare("UPDATE extracted_data SET is_approved=1, approved_by=?, approved_at=? WHERE document_id=?")
           ->execute([$auth['sub'], $now, $id]);

        // Auto-generate bookkeeping journal entry
        $journalId = JournalService::autoGenerate($id, $auth['sub']);

        Response::json([
            'message'    => 'Document approved.',
            'approved_at'=> $now,
            'journal_id' => $journalId ?: null,
        ]);
    }

    // POST /documents/{id}/archive
    public static function archive(int $id, array $auth): never
    {
        $db = Database::connect();
        self::findDocument($db, $id, $auth);

        $body   = self::json();
        $reason = $body['reason'] ?? null;
        $now    = date('Y-m-d H:i:s');

        $db->prepare("UPDATE documents SET status='archived' WHERE id=?")->execute([$id]);
        $db->prepare("UPDATE extracted_data SET archived_at=?, rejection_reason=? WHERE document_id=?")
           ->execute([$now, $reason, $id]);

        Response::json(['message' => 'Document archived.']);
    }

    // DELETE /documents/{id}
    public static function destroy(int $id, array $auth): never
    {
        $db = Database::connect();
        self::findDocument($db, $id, $auth);

        $db->prepare("UPDATE documents SET deleted_at=NOW() WHERE id=?")->execute([$id]);
        Response::json(['message' => 'Document deleted.']);
    }

    // GET /documents/{id}/download
    public static function download(int $id, array $auth): never
    {
        $db  = Database::connect();
        $doc = self::findDocument($db, $id, $auth);
        FileService::stream($doc['file_path'], $doc['original_file_name'], $doc['file_type']);
    }

    // GET /documents/export
    public static function export(array $auth): never
    {
        $db = Database::connect();

        $where  = ["d.deleted_at IS NULL"];
        $params = [];
        $isAdmin = ($auth['role'] ?? '') === 'admin';

        if (!$isAdmin) { $where[] = "d.user_id = ?"; $params[] = $auth['sub']; }

        $filters = ['status' => 'd.status', 'category' => 'e.category', 'currency' => 'e.currency'];
        foreach ($filters as $qp => $col) {
            if (!empty($_GET[$qp])) { $where[] = "$col = ?"; $params[] = $_GET[$qp]; }
        }
        if (!empty($_GET['date_from'])) { $where[] = "e.document_date >= ?"; $params[] = $_GET['date_from']; }
        if (!empty($_GET['date_to']))   { $where[] = "e.document_date <= ?"; $params[] = $_GET['date_to'];   }
        if (!empty($_GET['search']))    { $where[] = "e.supplier_name LIKE ?"; $params[] = '%' . $_GET['search'] . '%'; }

        $sql = "SELECT d.original_file_name, d.status, d.created_at,
                       e.supplier_name, e.document_date, e.total_amount, e.tax_amount,
                       e.currency, e.category, e.is_approved, e.approved_at,
                       u.name AS approved_by_name
                FROM documents d
                LEFT JOIN extracted_data e ON e.document_id = d.id
                LEFT JOIN users u ON u.id = e.approved_by
                WHERE " . implode(' AND ', $where) . "
                ORDER BY d.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $filename = 'documents_export_' . date('Ymd') . '.csv';
        ExportService::streamCsv($rows, $filename);
    }

    // ── Helpers ───────────────────────────────────────────────

    private static function findDocument(\PDO $db, int $id, array $auth): array
    {
        $isAdmin = ($auth['role'] ?? '') === 'admin';
        $sql     = "SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL";
        $params  = [$id];
        if (!$isAdmin) { $sql .= " AND user_id = ?"; $params[] = $auth['sub']; }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $doc  = $stmt->fetch();

        if (!$doc) Response::error('NOT_FOUND', 'Document not found.', 404);
        return $doc;
    }

    private static function normalizeFiles(array $rawFiles): array
    {
        // Normalize $_FILES['files'] to an array of individual file arrays
        if (!is_array($rawFiles['name'])) return [$rawFiles];
        $files = [];
        foreach ($rawFiles['name'] as $i => $name) {
            $files[] = [
                'name'     => $name,
                'type'     => $rawFiles['type'][$i],
                'tmp_name' => $rawFiles['tmp_name'][$i],
                'error'    => $rawFiles['error'][$i],
                'size'     => $rawFiles['size'][$i],
            ];
        }
        return array_filter($files, fn($f) => $f['error'] === UPLOAD_ERR_OK);
    }

    private static function json(): array
    {
        $raw = file_get_contents('php://input');
        return $raw ? (json_decode($raw, true) ?? []) : [];
    }
}
