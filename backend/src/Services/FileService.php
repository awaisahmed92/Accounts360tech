<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Config;

class FileService
{
    private const ALLOWED_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];

    private const MAX_SIZE = 20 * 1024 * 1024; // 20 MB

    public static function store(array $file, int $userId): array
    {
        $storagePath = Config::get('STORAGE_PATH', __DIR__ . '/../../storage/documents');
        $userDir     = $storagePath . DIRECTORY_SEPARATOR . $userId;

        if (!is_dir($userDir)) {
            mkdir($userDir, 0755, true);
        }

        // Validate size
        if ($file['size'] > self::MAX_SIZE) {
            throw new \InvalidArgumentException("File exceeds 20 MB limit.");
        }

        // Validate MIME via finfo
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($mimeType, self::ALLOWED_MIME)) {
            throw new \InvalidArgumentException("Unsupported file type: $mimeType");
        }

        $ext      = self::ALLOWED_MIME[$mimeType];
        $uuid     = self::uuid4();
        $fileName = "$uuid.$ext";
        $fullPath = $userDir . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            throw new \RuntimeException("Failed to move uploaded file.");
        }

        return [
            'original_file_name' => $file['name'],
            'stored_file_name'   => $fileName,
            'file_path'          => $fullPath,
            'file_type'          => $mimeType,
            'file_size'          => $file['size'],
        ];
    }

    public static function delete(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    public static function stream(string $filePath, string $originalName, string $mimeType): never
    {
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => ['code' => 'FILE_NOT_FOUND', 'message' => 'File not found.']]);
            exit;
        }

        header("Content-Type: $mimeType");
        header('Content-Disposition: inline; filename="' . addslashes($originalName) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, no-cache');
        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        exit;
    }

    private static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
