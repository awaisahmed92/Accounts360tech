<?php
declare(strict_types=1);

namespace App\Config;

class Config
{
    private static array $data = [];
    private static bool  $loaded = false;

    public static function load(string $envPath): void
    {
        if (self::$loaded) return;
        if (!file_exists($envPath)) {
            throw new \RuntimeException(".env file not found at: $envPath");
        }
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            self::$data[$key] = $value;
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }
}
