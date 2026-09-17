<?php

declare(strict_types=1);

namespace App\Application\Actions\Staff;

use App\Application\Helpers\JsonResponse;
use App\Services\SubscriptionTierService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UpdateStaffUserAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($args['id'] ?? 0);
        $data = $request->getParsedBody() ?? [];

        if ($userId <= 0) {
            return JsonResponse::error($response, 'Invalid user id', 422);
        }

        if (isset($data['is_staff'])) {
            $stmt = $this->db->prepare('UPDATE users SET is_staff = ? WHERE id = ?');
            $stmt->execute([(int) (bool) $data['is_staff'], $userId]);
        }

        if (isset($data['subscriptionStatus'])) {
            $status = $data['subscriptionStatus'];
            $allowed = ['none', 'active', 'canceled', 'past_due'];
            if (!in_array($status, $allowed, true)) {
                return JsonResponse::error($response, 'Invalid subscription status', 422);
            }
            $stmt = $this->db->prepare(
                'UPDATE subscriptions SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?'
            );
            $stmt->execute([$status, $userId]);
        }

        if (isset($data['planTier']) || isset($data['plan_tier'])) {
            $tier = $data['planTier'] ?? $data['plan_tier'];
            if (!in_array($tier, SubscriptionTierService::allowedTiers(), true)) {
                return JsonResponse::error($response, 'Invalid plan tier', 422);
            }
            $stmt = $this->db->prepare(
                'UPDATE subscriptions SET plan_tier = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?'
            );
            $stmt->execute([$tier, $userId]);
        }

        $stmt = $this->db->prepare(
            'SELECT u.id, u.full_name, u.email, u.is_staff, s.status AS subscription_status,
                    s.plan_tier AS plan_tier
             FROM users u LEFT JOIN subscriptions s ON s.user_id = u.id WHERE u.id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return JsonResponse::error($response, 'User not found', 404);
        }

        return JsonResponse::success($response, ['user' => $user]);
    }
}
