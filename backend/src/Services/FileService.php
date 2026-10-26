<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Config;

class FileService
{
    private const ALLOWED_MIME = [
        'application/pdf'   => 'pdf',
        'application/x-pdf' => 'pdf',
        'image/jpeg'        => 'jpg',
        'image/jpg'         => 'jpg',
        'image/pjpeg'       => 'jpg',
        'image/png'         => 'png',
        'image/webp'        => 'webp',
        'image/gif'         => 'gif',
    ];

    private const MAX_SIZE = 20 * 1024 * 1024; // 20 MB

    public static function store(array $file, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(self::uploadError((int) $file['error']));
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('The file did not reach the server. Use a PDF or picture under 20 MB.');
        }

        $userDir = self::storageRoot() . DIRECTORY_SEPARATOR . $userId;
        if (!is_dir($userDir) && !mkdir($userDir, 0775, true) && !is_dir($userDir)) {
            throw new \RuntimeException('The invoice folder could not be created on the server.');
        }
        if (!is_writable($userDir)) {
            throw new \RuntimeException('The invoice folder is not writable on the server.');
        }

        if ($file['size'] > self::MAX_SIZE) {
            throw new \InvalidArgumentException('File exceeds 20 MB limit.');
        }

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? (finfo_file($finfo, $file['tmp_name']) ?: '') : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $ext = self::ALLOWED_MIME[$mimeType] ?? self::extensionFallback($file['name'], $mimeType);
        if ($ext === null) {
            throw new \InvalidArgumentException('Use a PDF, PNG, JPEG, WEBP, or GIF.');
        }
        if (!array_key_exists($mimeType, self::ALLOWED_MIME)) {
            $mimeType = match ($ext) {
                'pdf' => 'application/pdf',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                default => 'image/jpeg',
            };
        }
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

    /** Folder for invoice files. A Windows path copied into the Linux server is ignored. */
    private static function storageRoot(): string
    {
        $configured = trim((string) (getenv('STORAGE_PATH') ?: Config::get('STORAGE_PATH', '')));
        $fallback   = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'documents';
        if ($configured !== '' && PHP_OS_FAMILY !== 'Windows' && preg_match('/^[A-Za-z]:[\\\\\\/]/', $configured)) {
            $configured = '';
        }
        $root = $configured !== '' ? $configured : $fallback;
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('The invoice folder could not be created on the server.');
        }
        if (!is_writable($root)) {
            throw new \RuntimeException('The invoice folder is not writable on the server.');
        }

        return $root;
    }

    private static function extensionFallback(string $name, string $mimeType): ?string
    {
        if ($mimeType !== '' && $mimeType !== 'application/octet-stream') {
            return null;
        }
        $map = [
            'pdf' => 'pdf', 'png' => 'png', 'jpg' => 'jpg', 'jpeg' => 'jpg',
            'webp' => 'webp', 'gif' => 'gif',
        ];

        return $map[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds 20 MB limit.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was selected.',
            default => 'The file could not be uploaded.',
        };
    }

    private static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
