<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Helpers\JsonResponse;
use App\Application\Helpers\Validator;
use App\Services\EmailService;
use App\Services\OtpService;
use App\Services\OtpVerificationException;
use Firebase\JWT\JWT;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class SignupVerifyOtpAction
{
    private PDO $db;
    private OtpService $otpService;
    private EmailService $emailService;
    private ?LoggerInterface $logger;

    public function __construct(
        PDO $db,
        OtpService $otpService,
        EmailService $emailService,
        ?LoggerInterface $logger = null
    ) {
        $this->db = $db;
        $this->otpService = $otpService;
        $this->emailService = $emailService;
        $this->logger = $logger;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required($data, ['email', 'code'])
            ->email($data, 'email');

        if ($validator->fails()) {
            return JsonResponse::validationError($response, $validator->errors());
        }

        $email = strtolower(trim($data['email']));
        $code = preg_replace('/\D/', '', (string) $data['code']);

        if (strlen($code) !== 6) {
            return JsonResponse::error($response, 'Verification code must be 6 digits', 422, 'INVALID_CODE');
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return JsonResponse::error($response, 'Email already exists', 409, 'EMAIL_EXISTS');
        }

        try {
            $payload = $this->otpService->verifySignupCode($email, $code);
        } catch (OtpVerificationException $e) {
            return JsonResponse::error($response, $e->getMessage(), 400, $e->getErrorCode());
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                'INSERT INTO users (full_name, email, password_hash) VALUES (?, ?, ?)'
            );
            $stmt->execute([
                $payload['fullName'],
                $email,
                $payload['password_hash'],
            ]);

            $userId = (int) $this->db->lastInsertId();

            $stmt = $this->db->prepare(
                "INSERT INTO subscriptions (user_id, status) VALUES (?, 'none')"
            );
            $stmt->execute([$userId]);

            $this->db->commit();

            $token = $this->generateJWT($userId, $email);

            $this->log('info', 'User registered via OTP', ['user_id' => $userId, 'email' => $email]);

            try {
                $this->emailService->sendWelcomeEmail($email, $payload['fullName']);
                $this->log('info', 'Welcome email sent', ['user_id' => $userId, 'email' => $email]);
            } catch (\Throwable $e) {
                $this->log('warning', 'Welcome email failed', [
                    'user_id' => $userId,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }

            return JsonResponse::success($response, [
                'token' => $token,
                'user' => [
                    'id' => $userId,
                    'fullName' => $payload['fullName'],
                    'email' => $email,
                ],
            ], 201);
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->log('error', 'Failed to create user after OTP verify', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return JsonResponse::error($response, 'Failed to create account', 500, 'INTERNAL_ERROR');
        }
    }

    private function generateJWT(int $userId, string $email): string
    {
        $jwtSecret = env('JWT_SECRET');
        $issuedAt = time();
        $expiresAt = $issuedAt + (7 * 24 * 60 * 60);

        return JWT::encode([
            'sub' => $userId,
            'email' => $email,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ], $jwtSecret, 'HS256');
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
