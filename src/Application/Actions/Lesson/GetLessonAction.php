<?php

declare(strict_types=1);

namespace App\Application\Actions\Lesson;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GetLessonAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        $lessonId = (int) $args['id'];

        $stmt = $this->db->prepare(
            "SELECT l.id, l.topic, l.language, l.title, l.content_json, l.created_at,
                    p.id AS plan_id, p.topic AS plan_topic, p.duration_days AS plan_duration_days,
                    pd.day_number AS plan_day_number
             FROM lessons l
             LEFT JOIN learning_plan_days pd ON pd.lesson_id = l.id
             LEFT JOIN learning_plans p ON p.id = pd.plan_id AND p.user_id = l.user_id
             WHERE l.id = ? AND l.user_id = ?"
        );
        $stmt->execute([$lessonId, $userId]);
        $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lesson) {
            return JsonResponse::error(
                $response,
                'Lesson not found',
                404,
                'NOT_FOUND'
            );
        }

        // Decode JSON content
        $lesson['id'] = (int) $lesson['id'];
        $lesson['content'] = json_decode($lesson['content_json'], true);
        unset($lesson['content_json']);

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

        return JsonResponse::success($response, $lesson);
    }
}

