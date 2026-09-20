<?php
declare(strict_types=1);

namespace App\Helpers;

class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => true, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
        exit;
    }

    public static function error(string $code, string $message, int $status = 400, array $fields = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $error = ['code' => $code, 'message' => $message];
        if (!empty($fields)) $error['fields'] = $fields;
        echo json_encode(
            ['success' => false, 'error' => $error],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
        exit;
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }
}
