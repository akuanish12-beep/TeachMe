<?php

declare(strict_types=1);

namespace App\Application\Actions\Lesson;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ListLessonsAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        
        // Parse pagination parameters (use pageSize as standard param name)
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($params['pageSize'] ?? 20)));
        $offset = ($page - 1) * $pageSize;

        // Get total count
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM lessons WHERE user_id = ?");
        $stmt->execute([$userId]);
        $total = (int) $stmt->fetchColumn();

        // Get lessons ordered by created_at DESC
        $stmt = $this->db->prepare(
            "SELECT id, title, topic, language, created_at 
             FROM lessons 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $pageSize, $offset]);
        $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format data - convert id to integer
        foreach ($lessons as &$lesson) {
            $lesson['id'] = (int) $lesson['id'];
        }

        // Return array directly with X-Total-Count header
        $response->getBody()->write(json_encode($lessons));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Total-Count', (string) $total);
    }
}

