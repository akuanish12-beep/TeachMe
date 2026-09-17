<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class OtpService
{
    private PDO $db;
    private EmailService $emailService;
    private int $ttlMinutes;
    private int $maxAttempts;

    public function __construct(PDO $db, EmailService $emailService)
    {
        $this->db = $db;
        $this->emailService = $emailService;
        $this->ttlMinutes = (int) env('OTP_TTL_MINUTES', 10);
        $this->maxAttempts = (int) env('OTP_MAX_ATTEMPTS', 5);
    }

    /**
     * @param array{fullName: string, password_hash: string} $payload
     */
    public function sendSignupCode(string $email, string $fullName, array $payload): void
    {
        $code = $this->generateCode();
        $codeHash = password_hash($code, PASSWORD_BCRYPT);
        $expiresAt = (new \DateTimeImmutable("+{$this->ttlMinutes} minutes"))->format('Y-m-d H:i:s');

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare(
                "DELETE FROM email_otp_codes WHERE email = ? AND purpose = 'signup'"
            );
            $delete->execute([$email]);

            $insert = $this->db->prepare(
                "INSERT INTO email_otp_codes (email, purpose, code_hash, payload, expires_at)
                 VALUES (?, 'signup', ?, ?, ?)"
            );
            $insert->execute([$email, $codeHash, $payloadJson, $expiresAt]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->emailService->sendOtpEmail($email, $fullName, $code);
    }

    /**
     * @return array{fullName: string, password_hash: string}
     */
    public function verifySignupCode(string $email, string $code): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, code_hash, payload, expires_at, attempts
             FROM email_otp_codes
             WHERE email = ? AND purpose = 'signup'
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new OtpVerificationException('No verification code found. Please request a new one.', 'OTP_NOT_FOUND');
        }

        if (strtotime($row['expires_at']) < time()) {
            $this->deleteCodesForEmail($email);
            throw new OtpVerificationException('Verification code has expired. Please request a new one.', 'OTP_EXPIRED');
        }

        if ((int) $row['attempts'] >= $this->maxAttempts) {
            $this->deleteCodesForEmail($email);
            throw new OtpVerificationException('Too many attempts. Please request a new code.', 'OTP_MAX_ATTEMPTS');
        }

        if (!password_verify($code, $row['code_hash'])) {
            $update = $this->db->prepare(
                "UPDATE email_otp_codes SET attempts = attempts + 1 WHERE id = ?"
            );
            $update->execute([$row['id']]);
            throw new OtpVerificationException('Invalid verification code.', 'OTP_INVALID');
        }

        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->deleteCodesForEmail($email);

        return $payload;
    }

    private function deleteCodesForEmail(string $email): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM email_otp_codes WHERE email = ? AND purpose = 'signup'"
        );
        $stmt->execute([$email]);
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
