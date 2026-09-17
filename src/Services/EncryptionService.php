<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Encrypts sensitive values at rest (e.g. user Gemini API keys).
 */
class EncryptionService
{
    private string $key;

    public function __construct()
    {
        $raw = env('APP_ENCRYPTION_KEY', '') ?: env('JWT_SECRET', '');
        if ($raw === '') {
            throw new \RuntimeException('APP_ENCRYPTION_KEY or JWT_SECRET required for encryption');
        }
        $this->key = hash('sha256', $raw, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < 28) {
            throw new \RuntimeException('Invalid encrypted payload');
        }

        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $plaintext;
    }

    public static function keyHint(string $apiKey): string
    {
        $trimmed = trim($apiKey);
        if (strlen($trimmed) <= 4) {
            return '****';
        }

        return '…' . substr($trimmed, -4);
    }
}
