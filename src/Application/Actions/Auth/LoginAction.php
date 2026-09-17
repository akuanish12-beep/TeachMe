<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Helpers\JsonResponse;
use App\Application\Helpers\Validator;
use Firebase\JWT\JWT;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LoginAction
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
            ->required($data, ['email', 'password'])
            ->email($data, 'email');

        if ($validator->fails()) {
            return JsonResponse::validationError($response, $validator->errors());
        }

        // Find user
        $stmt = $this->db->prepare(
            "SELECT id, full_name, email, password_hash FROM users WHERE email = ?"
        );
        $email = strtolower(trim((string) $data['email']));
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return JsonResponse::error($response, 'Invalid credentials', 401, 'INVALID_CREDENTIALS');
        }

        // Verify password
        if (!password_verify($data['password'], $user['password_hash'])) {
            return JsonResponse::error($response, 'Invalid credentials', 401, 'INVALID_CREDENTIALS');
        }

        // Generate JWT
        $token = $this->generateJWT((int) $user['id'], $user['email']);

        return JsonResponse::success($response, [
            'token' => $token,
            'user' => [
                'id' => (int) $user['id'],
                'fullName' => $user['full_name'],
                'email' => $user['email'],
            ]
        ]);
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

