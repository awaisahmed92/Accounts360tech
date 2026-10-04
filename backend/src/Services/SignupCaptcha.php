<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Response;
use RuntimeException;

/** Short-lived image code for the public sign-up form. */
class SignupCaptcha
{
    public static function issueJson(): never
    {
        Response::json(self::issue());
    }

    public static function issue(): array
    {
        $code = self::code();
        $token = bin2hex(random_bytes(16));
        self::write($token, hash('sha256', $code), time() + 600);

        return [
            'token' => $token,
            'image' => 'data:image/png;base64,' . base64_encode(self::png($code)),
        ];
    }

    public static function check(?string $token, ?string $answer): bool
    {
        $token = trim((string) $token);
        $answer = strtoupper(preg_replace('/\s+/', '', (string) $answer) ?? '');
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token) || $answer === '') {
            return false;
        }

        $row = self::read($token);
        if ($row === null || ($row['expires'] ?? 0) < time()) {
            return false;
        }
        if (!hash_equals((string) $row['hash'], hash('sha256', $answer))) {
            return false;
        }

        self::forget($token);

        return true;
    }

    private static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/captcha';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not store the verification code.');
        }

        return $dir;
    }

    private static function write(string $token, string $hash, int $expires): void
    {
        self::sweep();
        $path = self::dir() . '/' . $token . '.json';
        file_put_contents($path, json_encode(['hash' => $hash, 'expires' => $expires]));
    }

    private static function read(string $token): ?array
    {
        $path = self::dir() . '/' . $token . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private static function forget(string $token): void
    {
        $path = self::dir() . '/' . $token . '.json';
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function sweep(): void
    {
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            if (filemtime($file) < time() - 900) {
                unlink($file);
            }
        }
    }

    private static function code(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    private static function png(string $code): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('The captcha image library is not available.');
        }

        $image = imagecreatetruecolor(168, 56);
        $background = imagecolorallocate($image, 234, 243, 251);
        $ink = imagecolorallocate($image, 22, 51, 92);
        $line = imagecolorallocate($image, 140, 170, 210);
        imagefilledrectangle($image, 0, 0, 168, 56, $background);

        for ($i = 0; $i < 8; $i++) {
            imageline($image, random_int(0, 168), random_int(0, 56), random_int(0, 168), random_int(0, 56), $line);
        }
        for ($i = 0; $i < 40; $i++) {
            imagesetpixel($image, random_int(0, 167), random_int(0, 55), $line);
        }

        $x = 14;
        foreach (str_split($code) as $character) {
            imagestring($image, 5, $x, random_int(14, 26), $character, $ink);
            $x += 30;
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
