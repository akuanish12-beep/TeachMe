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
            "SELECT id, topic, language, title, content_json, created_at 
             FROM lessons 
             WHERE id = ? AND user_id = ?"
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

        return JsonResponse::success($response, $lesson);
    }
}

