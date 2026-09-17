<?php

declare(strict_types=1);

namespace App\Application\Actions\User;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CreateReportAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = $request->getParsedBody();
        $itemType = $body['item_type'] ?? null;
        $itemId = $body['item_id'] ?? null;
        $reason = $body['reason'] ?? null;

        if (!$itemType || !$itemId || !$reason) {
            return JsonResponse::error($response, 'Missing required fields: item_type, item_id, reason', 400);
        }

        if (!in_array($itemType, ['lesson', 'generation', 'plan'])) {
            return JsonResponse::error($response, 'Invalid item_type. Must be lesson, generation, or plan', 400);
        }

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO reports (user_id, item_type, item_id, reason) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $itemType, $itemId, $reason]);

            return JsonResponse::success($response, [
                'message' => 'Report submitted successfully',
                'report_id' => $this->db->lastInsertId()
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error(
                $response,
                'Failed to submit report: ' . $e->getMessage(),
                500,
                'REPORT_FAILED'
            );
        }
    }
}
