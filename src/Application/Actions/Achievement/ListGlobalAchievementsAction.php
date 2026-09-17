<?php

declare(strict_types=1);

namespace App\Application\Actions\Achievement;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ListGlobalAchievementsAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $stmt = $this->db->query(
            "SELECT id, badge_key, title, description, icon_url FROM achievements ORDER BY id ASC"
        );
        $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format to properly typed values
        $formatted = array_map(function($a) {
            return [
                'id' => (int) $a['id'],
                'badge_key' => $a['badge_key'],
                'title' => $a['title'],
                'description' => $a['description'],
                'icon_url' => $a['icon_url']
            ];
        }, $achievements);

        return JsonResponse::success($response, [
            'status' => 'success',
            'data' => $formatted
        ]);
    }
}
