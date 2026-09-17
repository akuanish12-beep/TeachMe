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

        // Fetch user with subscription and gamification fields
        $stmt = $this->db->prepare(
            "SELECT u.id, u.full_name, u.email, s.status as subscription_status,
                    u.total_xp, u.current_level, u.current_streak, u.longest_streak, 
                    u.last_active_date, u.streak_freezes_count
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
            ],
            'gamification' => [
                'totalXp' => (int) ($user['total_xp'] ?? 0),
                'currentLevel' => (int) ($user['current_level'] ?? 1),
                'currentStreak' => (int) ($user['current_streak'] ?? 0),
                'longestStreak' => (int) ($user['longest_streak'] ?? 0),
                'lastActiveDate' => $user['last_active_date'],
                'streakFreezesCount' => (int) ($user['streak_freezes_count'] ?? 0)
            ]
        ]);
    }
}

