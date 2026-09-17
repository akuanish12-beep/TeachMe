<?php

declare(strict_types=1);

namespace App\Application\Actions\User;

use App\Application\Helpers\JsonResponse;
use App\Services\SubscriptionTierService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DeleteUserAiSettingsAction
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

        if (!$this->tiers->canManageByok($userId)) {
            return JsonResponse::error(
                $response,
                'Bring your own Gemini key is available on Ultra only',
                403,
                'FORBIDDEN'
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE users SET gemini_api_key_encrypted = NULL, gemini_api_key_hint = NULL WHERE id = ?'
        );
        $stmt->execute([$userId]);

        return JsonResponse::success($response, [
            'hasCustomKey' => false,
            'keyHint' => null,
        ]);
    }
}
