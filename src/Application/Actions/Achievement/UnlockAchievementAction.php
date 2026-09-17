<?php

declare(strict_types=1);

namespace App\Application\Actions\Achievement;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UnlockAchievementAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $authUserId = (int) $request->getAttribute('user_id');
        
        $body = $request->getParsedBody();
        $targetUserId = isset($body['user_id']) ? (int) $body['user_id'] : null;
        $badgeKey = $body['badge_key'] ?? null;

        // Validation
        if (!$targetUserId || !$badgeKey) {
            return JsonResponse::error($response, 'user_id and badge_key are required', 400);
        }

        // Security check
        if ($targetUserId !== $authUserId) {
            return JsonResponse::error($response, 'Forbidden: Cannot unlock achievements for other users', 403);
        }

        try {
            $this->db->beginTransaction();

            // Check if the achievement exists
            $achStmt = $this->db->prepare("SELECT id FROM achievements WHERE badge_key = ?");
            $achStmt->execute([$badgeKey]);
            $achievementId = $achStmt->fetchColumn();

            if (!$achievementId) {
                // Return 404 because the badge requested is invalid
                $this->db->rollBack();
                return JsonResponse::error($response, 'Achievement badge_key not found in system', 404);
            }

            // Check if user already unlocked it
            $checkStmt = $this->db->prepare(
                "SELECT unlocked_at FROM user_achievements WHERE user_id = ? AND achievement_id = ?"
            );
            $checkStmt->execute([$targetUserId, $achievementId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $this->db->rollBack();
                return JsonResponse::success($response, [
                    'status' => 'already_unlocked',
                    'message' => 'User has already unlocked this achievement.',
                    'unlocked_at' => $existing['unlocked_at']
                ]);
            }

            // Unlock the achievement
            $insertStmt = $this->db->prepare(
                "INSERT INTO user_achievements (user_id, achievement_id, unlocked_at) VALUES (?, ?, NOW())"
            );
            $insertStmt->execute([$targetUserId, $achievementId]);

            $this->db->commit();

            return JsonResponse::success($response, [
                'status' => 'success',
                'message' => 'Achievement unlocked successfully'
            ]);

        } catch (\PDOException $e) {
            $this->db->rollBack();
            // Duplicate entry prevention fallback due to PRIMARY KEY constraint
            if ($e->getCode() === '23000') {
                return JsonResponse::success($response, [
                    'status' => 'already_unlocked',
                    'message' => 'User has already unlocked this achievement.'
                ]);
            }
            return JsonResponse::error($response, 'Database error: ' . $e->getMessage(), 500);
        } catch (\Exception $e) {
            $this->db->rollBack();
            return JsonResponse::error($response, 'Internal server error: ' . $e->getMessage(), 500);
        }
    }
}
