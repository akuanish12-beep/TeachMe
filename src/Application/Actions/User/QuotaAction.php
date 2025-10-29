<?php

declare(strict_types=1);

namespace App\Application\Actions\User;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class QuotaAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        // Get subscription status
        $stmt = $this->db->prepare(
            "SELECT status FROM subscriptions WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$userId]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $hasActiveSubscription = $subscription && $subscription['status'] === 'active';

        // Get free generations used count
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM generations WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $freeGenerationsUsed = (int) $result['count'];

        // Determine if user can generate
        $canGenerate = $hasActiveSubscription || $freeGenerationsUsed < 1;

        return JsonResponse::success($response, [
            'freeGenerationsUsed' => $freeGenerationsUsed,
            'freeGenerationsLimit' => 1,
            'hasActiveSubscription' => $hasActiveSubscription,
            'canGenerate' => $canGenerate
        ]);
    }
}

