<?php

declare(strict_types=1);

namespace App\Application\Actions\Lesson;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ListFavoritesAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        // Get favorite lessons ordered by created_at DESC
        $stmt = $this->db->prepare(
            "SELECT id, title, topic, language, user_notes, created_at
             FROM lessons
             WHERE user_id = ? AND is_favorite = 1
             ORDER BY created_at DESC"
        );
        $stmt->execute([$userId]);
        $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert id to integer
        foreach ($favorites as &$favorite) {
            $favorite['id'] = (int) $favorite['id'];
        }

        // Return array directly (not wrapped in object)
        $response->getBody()->write(json_encode($favorites));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Total-Count', (string) count($favorites));
    }
}

