<?php

declare(strict_types=1);

namespace App\Application\Actions\User;

use App\Application\Helpers\JsonResponse;
use App\Services\SubscriptionTierService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GetUserAiSettingsAction
{
    private PDO $db;
    private SubscriptionTierService $tiers;

    public function __construct(PDO $db, SubscriptionTierService $tiers)
    {
        $this->db = $db;
        $this->tiers = $tiers;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');

        $stmt = $this->db->prepare(
            'SELECT gemini_api_key_hint FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return JsonResponse::success($response, [
            'canManageByok' => $this->tiers->canManageByok($userId),
            'hasCustomKey' => $this->tiers->userHasByokKey($userId),
            'keyHint' => $row['gemini_api_key_hint'] ?? null,
        ]);
    }
}
