<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MeAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        // Get user_id from JWT middleware
        $userId = $request->getAttribute('user_id');

        // Fetch user with subscription
        $stmt = $this->db->prepare(
            "SELECT u.id, u.full_name, u.email, s.status as subscription_status
             FROM users u
             LEFT JOIN subscriptions s ON u.id = s.user_id
             WHERE u.id = ?"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return JsonResponse::error($response, 'User not found', 404, 'USER_NOT_FOUND');
        }

        return JsonResponse::success($response, [
            'id' => (int) $user['id'],
            'fullName' => $user['full_name'],
            'email' => $user['email'],
            'subscription' => [
                'status' => $user['subscription_status'] ?? 'none'
            ]
        ]);
    }
}

