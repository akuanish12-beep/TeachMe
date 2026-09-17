<?php

declare(strict_types=1);

namespace App\Application\Actions\Achievement;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ListUserAchievementsAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // Enforce access control if needed.
        $targetUserId = (int) $args['user_id'];
        $authUserId = (int) $request->getAttribute('user_id');

        // Optional: Ensure a user can only view their own achievements
        if ($targetUserId !== $authUserId) {
            // Check if user is staff (optional extension)
            $staffCheck = $this->db->prepare("SELECT is_staff FROM users WHERE id = ?");
            $staffCheck->execute([$authUserId]);
            $isStaff = (int) $staffCheck->fetchColumn() === 1;

            if (!$isStaff) {
                return JsonResponse::error($response, 'Forbidden: You can only view your own achievements', 403);
            }
        }

        $stmt = $this->db->prepare(
            "SELECT a.id, a.badge_key, a.title, a.description, a.icon_url, ua.unlocked_at 
             FROM achievements a
             INNER JOIN user_achievements ua ON a.id = ua.achievement_id
             WHERE ua.user_id = ?
             ORDER BY ua.unlocked_at DESC"
        );
        $stmt->execute([$targetUserId]);
        $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function($a) {
            return [
                'id' => (int) $a['id'],
                'badge_key' => $a['badge_key'],
                'title' => $a['title'],
                'description' => $a['description'],
                'icon_url' => $a['icon_url'],
                'unlocked_at' => $a['unlocked_at']
            ];
        }, $achievements);

        return JsonResponse::success($response, [
            'status' => 'success',
            'data' => $formatted
        ]);
    }
}
