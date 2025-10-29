<?php

declare(strict_types=1);

namespace App\Application\Actions\Lesson;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UpdateLessonAction
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
        $data = $request->getParsedBody();

        // Verify ownership
        $stmt = $this->db->prepare(
            "SELECT id FROM lessons WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$lessonId, $userId]);
        $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lesson) {
            return JsonResponse::error(
                $response,
                'Lesson not found or you do not have permission to update it',
                404,
                'NOT_FOUND'
            );
        }

        // Build update query dynamically based on provided fields
        $allowedFields = ['is_favorite', 'user_notes'];
        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = ?";
                
                // Handle boolean for is_favorite
                if ($field === 'is_favorite') {
                    $params[] = $data[$field] ? 1 : 0;
                } else {
                    $params[] = $data[$field];
                }
            }
        }

        if (empty($updates)) {
            return JsonResponse::error(
                $response,
                'No valid fields to update. Allowed: is_favorite, user_notes',
                422,
                'INVALID_INPUT'
            );
        }

        try {
            // Add lesson ID and user ID to params for WHERE clause
            $params[] = $lessonId;
            $params[] = $userId;

            $sql = "UPDATE lessons SET " . implode(', ', $updates) . " WHERE id = ? AND user_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            // Fetch updated lesson
            $stmt = $this->db->prepare(
                "SELECT id, title, topic, language, is_favorite, user_notes, created_at 
                 FROM lessons 
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$lessonId, $userId]);
            $updatedLesson = $stmt->fetch(PDO::FETCH_ASSOC);

            // Convert is_favorite to boolean
            $updatedLesson['id'] = (int) $updatedLesson['id'];
            $updatedLesson['is_favorite'] = (bool) $updatedLesson['is_favorite'];

            return JsonResponse::success($response, [
                'message' => 'Lesson updated successfully',
                'lesson' => $updatedLesson
            ]);

        } catch (\Exception $e) {
            return JsonResponse::error(
                $response,
                'Failed to update lesson: ' . $e->getMessage(),
                500,
                'UPDATE_FAILED'
            );
        }
    }
}

