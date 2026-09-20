<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Config;

/**
 * Google Document AI — Expense Parser integration.
 *
 * For local development without a GCP key the service returns
 * realistic mock data so the full app flow can be tested end-to-end.
 * Set DOCAI_API_KEY in .env to a valid service-account JSON path
 * to activate the live API.
 */
class DocumentAIService
{
    private const CATEGORY_KEYWORDS = [
        'Meals & Entertainment'      => ['restaurant','cafe','coffee','starbucks','mcdonald','pizza','food','lunch','dinner','bar','pub','uber eats','doordash'],
        'Travel & Transport'         => ['uber','lyft','taxi','airline','hotel','airbnb','parking','fuel','petrol','train','bus','metro'],
        'Software & Subscriptions'   => ['google','amazon','microsoft','apple','adobe','slack','github','netflix','spotify','zoom','dropbox','atlassian'],
        'Advertising & Marketing'    => ['meta','facebook','instagram','twitter','linkedin','ads','marketing','seo','agency'],
        'Office Supplies'            => ['staples','office depot','amazon basics','paper','pen','ink','toner','printer'],
        'Utilities'                  => ['electric','water','gas','internet','broadband','phone','mobile','att','verizon'],
        'Professional Services'      => ['lawyer','accounting','consultant','audit','legal','notary','architect'],
        'Equipment & Hardware'       => ['apple','dell','lenovo','hp','logitech','bestbuy','newegg','monitor','laptop','keyboard'],
        'Rent & Facilities'          => ['rent','lease','cleaning','maintenance','repair','facility'],
    ];

    public static function process(string $filePath, string $mimeType): array
    {
        $enabled = filter_var((string)Config::get('DOCUMENT_AI_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN);
        $credentialsPath = (string) (Config::get('DOCAI_API_KEY', '') ?: Config::get('GCP_CREDENTIALS_PATH', ''));

        if (!$enabled) {
            return self::fallbackExtract();
        }
        if ($credentialsPath === '' || !file_exists($credentialsPath)) {
            throw new \RuntimeException('Document AI is enabled but credentials file is missing.');
        }

        return self::callDocumentAI($filePath, $mimeType, $credentialsPath);
    }

    // ── Live Google Document AI call ──────────────────────────
    private static function callDocumentAI(string $filePath, string $mimeType, string $credentialsPath): array
    {
        $projectId   = (string) (Config::get('DOCAI_PROJECT_ID', '') ?: Config::get('GCP_PROJECT_ID', ''));
        $processorId = (string) (Config::get('DOCAI_PROCESSOR_ID', '') ?: Config::get('GCP_PROCESSOR_ID', ''));
        $location    = (string) (Config::get('DOCAI_LOCATION', '') ?: Config::get('GCP_LOCATION', 'us'));
        if ($projectId === '' || $processorId === '') {
            throw new \RuntimeException('Document AI is enabled but project/processor ID is missing.');
        }

        $keyJson     = json_decode(file_get_contents($credentialsPath), true);
        if (!is_array($keyJson) || empty($keyJson['client_email']) || empty($keyJson['private_key'])) {
            throw new \RuntimeException('Invalid GCP service account JSON.');
        }
        $accessToken = self::getAccessToken($keyJson);

        $endpoint = "https://{$location}-documentai.googleapis.com/v1/projects/{$projectId}/locations/{$location}/processors/{$processorId}:process";

        $content  = base64_encode(file_get_contents($filePath));
        $body     = json_encode([
            'rawDocument' => ['content' => $content, 'mimeType' => $mimeType],
        ]);

        $start = microtime(true);
        $ch    = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response   = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $latencyMs  = (int)((microtime(true) - $start) * 1000);
        curl_close($ch);

        if ($httpStatus !== 200) {
            throw new \RuntimeException("Document AI HTTP $httpStatus: $response", $httpStatus);
        }

        return self::parseDocumentAIResponse(json_decode($response, true), $latencyMs);
    }

    private static function parseDocumentAIResponse(array $response, int $latencyMs): array
    {
        $entities     = $response['document']['entities'] ?? [];
        $result       = ['latency_ms' => $latencyMs, 'line_items' => []];
        $lineItemsMap = [];

        foreach ($entities as $entity) {
            $type       = $entity['type'] ?? '';
            $value      = $entity['mentionText'] ?? '';
            $confidence = (float)($entity['confidence'] ?? 0) * 100;

            switch ($type) {
                case 'supplier_name':    $result['supplier_name']    = trim($value); break;
                case 'receipt_date':
                case 'invoice_date':     $result['document_date']    = self::parseDate($value); break;
                case 'total_amount':     $result['total_amount']     = self::parseAmount($value); break;
                case 'total_tax_amount': $result['tax_amount']       = self::parseAmount($value); break;
                case 'currency':         $result['currency']         = strtoupper(trim($value)); break;
                case 'line_item':
                    $li = [];
                    foreach ($entity['properties'] ?? [] as $prop) {
                        switch ($prop['type']) {
                            case 'line_item/description': $li['description'] = trim($prop['mentionText'] ?? ''); break;
                            case 'line_item/quantity':    $li['quantity']    = (float)($prop['mentionText'] ?? 0); break;
                            case 'line_item/unit_price':  $li['unit_price']  = self::parseAmount($prop['mentionText'] ?? '0'); break;
                            case 'line_item/amount':      $li['line_total']  = self::parseAmount($prop['mentionText'] ?? '0'); break;
                        }
                    }
                    $result['line_items'][] = $li;
                    break;
            }
            $result['confidence_score'] = $confidence;
        }

        $result['category'] = self::guessCategory($result['supplier_name'] ?? '');
        return $result;
    }

    // ── Safe fallback (no fabricated values) ─────────────────
    private static function fallbackExtract(): array
    {
        return [
            'supplier_name'    => null,
            'document_date'    => null,
            'total_amount'     => null,
            'tax_amount'       => null,
            'currency'         => 'USD',
            'category'         => 'Other / Uncategorized',
            'confidence_score' => 0,
            'latency_ms'       => 0,
            'line_items'       => [],
        ];
    }

    private static function guessCategory(string $supplierName): string
    {
        $lower = strtolower($supplierName);
        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) return $category;
            }
        }
        return 'Other / Uncategorized';
    }

    private static function parseDate(string $value): string
    {
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    private static function parseAmount(string $value): float
    {
        return (float) preg_replace('/[^0-9.]/', '', $value);
    }

    private static function getAccessToken(array $keyJson): string
    {
        // Minimal service-account JWT flow for Google APIs
        $now   = time();
        $claim = [
            'iss'   => $keyJson['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $header   = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload  = base64_encode(json_encode($claim));
        $unsigned = "$header.$payload";

        $privateKey = $keyJson['private_key'];
        openssl_sign($unsigned, $sig, $privateKey, OPENSSL_ALGO_SHA256);
        $jwt  = "$unsigned." . base64_encode($sig);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $res  = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $res['access_token'] ?? '';
    }
}
