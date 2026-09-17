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

        // Get lessons ordered by created_at DESC, with optional learning-plan tag metadata
        $stmt = $this->db->prepare(
            "SELECT l.id, l.title, l.topic, l.language, l.created_at,
                    p.id AS plan_id, p.topic AS plan_topic, p.duration_days AS plan_duration_days,
                    pd.day_number AS plan_day_number
             FROM lessons l
             LEFT JOIN learning_plan_days pd ON pd.lesson_id = l.id
             LEFT JOIN learning_plans p ON p.id = pd.plan_id AND p.user_id = l.user_id
             WHERE l.user_id = ?
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $pageSize, $offset]);
        $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format data - convert id to integer, attach plan_tag when lesson belongs to a plan day
        foreach ($lessons as &$lesson) {
            $lesson['id'] = (int) $lesson['id'];
            if (!empty($lesson['plan_id'])) {
                $lesson['plan_tag'] = [
                    'plan_id' => (int) $lesson['plan_id'],
                    'topic' => $lesson['plan_topic'],
                    'day_number' => (int) $lesson['plan_day_number'],
                    'total_days' => (int) $lesson['plan_duration_days'],
                ];
            }
            unset(
                $lesson['plan_id'],
                $lesson['plan_topic'],
                $lesson['plan_duration_days'],
                $lesson['plan_day_number']
            );
        }

        // Return array directly with X-Total-Count header
        $response->getBody()->write(json_encode($lessons));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Total-Count', (string) $total);
    }
}

