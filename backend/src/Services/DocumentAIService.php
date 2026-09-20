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
        $apiKey = Config::get('DOCAI_API_KEY', '');

        if (empty($apiKey) || !file_exists($apiKey)) {
            return self::mockExtract($filePath);
        }

        return self::callDocumentAI($filePath, $mimeType);
    }

    // ── Live Google Document AI call ──────────────────────────
    private static function callDocumentAI(string $filePath, string $mimeType): array
    {
        $projectId   = Config::get('DOCAI_PROJECT_ID', '');
        $processorId = Config::get('DOCAI_PROCESSOR_ID', '');
        $location    = Config::get('DOCAI_LOCATION', 'us');

        $keyJson     = json_decode(file_get_contents(Config::get('DOCAI_API_KEY')), true);
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

    // ── Mock extraction for local dev ─────────────────────────
    private static function mockExtract(string $filePath): array
    {
        $suppliers = [
            ['name' => 'Starbucks Coffee', 'cat' => 'Meals & Entertainment',    'total' => 12.50, 'tax' => 1.50],
            ['name' => 'Amazon Web Services', 'cat' => 'Software & Subscriptions', 'total' => 245.00, 'tax' => 0.00],
            ['name' => 'Uber Technologies', 'cat' => 'Travel & Transport',        'total' => 28.75, 'tax' => 2.50],
            ['name' => 'Office Depot',      'cat' => 'Office Supplies',           'total' => 67.30, 'tax' => 5.20],
            ['name' => 'Google Workspace',  'cat' => 'Software & Subscriptions',  'total' => 18.00, 'tax' => 0.00],
            ['name' => 'Delta Airlines',    'cat' => 'Travel & Transport',        'total' => 420.00, 'tax' => 35.00],
        ];
        $pick = $suppliers[array_rand($suppliers)];

        return [
            'supplier_name'    => $pick['name'],
            'document_date'    => date('Y-m-d', strtotime('-' . rand(1, 30) . ' days')),
            'total_amount'     => $pick['total'],
            'tax_amount'       => $pick['tax'],
            'currency'         => 'USD',
            'category'         => $pick['cat'],
            'confidence_score' => round(rand(7500, 9900) / 100, 2),
            'latency_ms'       => rand(800, 2500),
            'line_items'       => [
                ['description' => 'Item 1', 'quantity' => 1, 'unit_price' => round($pick['total'] * 0.6, 2), 'line_total' => round($pick['total'] * 0.6, 2)],
                ['description' => 'Item 2', 'quantity' => 1, 'unit_price' => round($pick['total'] * 0.4, 2), 'line_total' => round($pick['total'] * 0.4, 2)],
            ],
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
