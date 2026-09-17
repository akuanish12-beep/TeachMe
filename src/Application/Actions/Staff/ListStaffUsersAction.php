<?php

declare(strict_types=1);

namespace App\Application\Actions\Staff;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ListStaffUsersAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $stmt = $this->db->query(
            'SELECT u.id, u.full_name, u.email, u.is_staff, u.created_at,
                    s.status AS subscription_status, s.plan_tier AS plan_tier,
                    s.stripe_subscription_id
             FROM users u
             LEFT JOIN subscriptions s ON s.user_id = u.id
             ORDER BY u.created_at DESC
             LIMIT 500'
        );

        return JsonResponse::success($response, ['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
}
