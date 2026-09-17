<?php

declare(strict_types=1);

namespace App\Application\Actions\Staff;

use App\Application\Helpers\JsonResponse;
use App\Application\Helpers\Validator;
use Firebase\JWT\JWT;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StaffLoginAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $validator = new Validator();
        $validator->required($data, ['email', 'password'])->email($data, 'email');

        if ($validator->fails()) {
            return JsonResponse::validationError($response, $validator->errors());
        }

        $stmt = $this->db->prepare(
            'SELECT id, full_name, email, password_hash, is_staff FROM users WHERE email = ?'
        );
        $stmt->execute([strtolower(trim($data['email']))]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            return JsonResponse::error($response, 'Invalid credentials', 401, 'INVALID_CREDENTIALS');
        }

        if (!(int) $user['is_staff']) {
            return JsonResponse::error($response, 'Staff access required', 403, 'FORBIDDEN');
        }

        $token = JWT::encode([
            'sub' => (int) $user['id'],
            'email' => $user['email'],
            'staff' => true,
            'iat' => time(),
            'exp' => time() + (12 * 60 * 60),
        ], env('JWT_SECRET'), 'HS256');

        return JsonResponse::success($response, [
            'token' => $token,
            'user' => [
                'id' => (int) $user['id'],
                'fullName' => $user['full_name'],
                'email' => $user['email'],
            ],
        ]);
    }
}
