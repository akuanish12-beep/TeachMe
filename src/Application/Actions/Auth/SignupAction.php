<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Helpers\JsonResponse;
use App\Application\Helpers\Validator;
use Firebase\JWT\JWT;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SignupAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        // Validate input
        $validator = new Validator();
        $validator
            ->required($data, ['fullName', 'email', 'password'])
            ->email($data, 'email')
            ->minLength($data, 'password', 8)
            ->maxLength($data, 'fullName', 120);

        if ($validator->fails()) {
            return JsonResponse::validationError($response, $validator->errors());
        }

        // Check if email already exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            return JsonResponse::error($response, 'Email already exists', 409, 'EMAIL_EXISTS');
        }

        // Hash password
        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);

        // Insert user
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                "INSERT INTO users (full_name, email, password_hash) VALUES (?, ?, ?)"
            );
            $stmt->execute([
                $data['fullName'],
                $data['email'],
                $passwordHash
            ]);

            $userId = (int) $this->db->lastInsertId();

            // Create empty subscription row
            $stmt = $this->db->prepare(
                "INSERT INTO subscriptions (user_id, status) VALUES (?, 'none')"
            );
            $stmt->execute([$userId]);

            $this->db->commit();

            // Generate JWT
            $token = $this->generateJWT($userId, $data['email']);

            return JsonResponse::success($response, [
                'token' => $token,
                'user' => [
                    'id' => $userId,
                    'fullName' => $data['fullName'],
                    'email' => $data['email'],
                ]
            ], 201);

        } catch (\Exception $e) {
            $this->db->rollBack();
            return JsonResponse::error($response, 'Failed to create user', 500, 'INTERNAL_ERROR');
        }
    }

    private function generateJWT(int $userId, string $email): string
    {
        $jwtSecret = env('JWT_SECRET');
        $issuedAt = time();
        $expiresAt = $issuedAt + (7 * 24 * 60 * 60); // 7 days

        $payload = [
            'sub' => $userId,
            'email' => $email,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];

        return JWT::encode($payload, $jwtSecret, 'HS256');
    }
}

