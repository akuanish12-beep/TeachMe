<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Helpers\JsonResponse;
use App\Application\Helpers\Validator;
use App\Services\OtpService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class SignupSendOtpAction
{
    private PDO $db;
    private OtpService $otpService;
    private ?LoggerInterface $logger;

    public function __construct(PDO $db, OtpService $otpService, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->otpService = $otpService;
        $this->logger = $logger;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required($data, ['fullName', 'email', 'password'])
            ->email($data, 'email')
            ->minLength($data, 'password', 8)
            ->maxLength($data, 'fullName', 120);

        if ($validator->fails()) {
            return JsonResponse::validationError($response, $validator->errors());
        }

        $email = strtolower(trim($data['email']));

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return JsonResponse::error($response, 'Email already exists', 409, 'EMAIL_EXISTS');
        }

        try {
            $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);

            $this->otpService->sendSignupCode($email, $data['fullName'], [
                'fullName' => $data['fullName'],
                'password_hash' => $passwordHash,
            ]);

            $this->log('info', 'Signup OTP sent', ['email' => $email]);

            return JsonResponse::success($response, [
                'sent' => true,
                'email' => $email,
                'expiresInMinutes' => (int) env('OTP_TTL_MINUTES', 10),
            ]);
        } catch (\RuntimeException $e) {
            $this->log('error', 'Failed to send signup OTP', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return JsonResponse::error(
                $response,
                'Unable to send verification email. Please try again later.',
                500,
                'EMAIL_SEND_FAILED'
            );
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
