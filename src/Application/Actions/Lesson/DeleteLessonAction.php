<?php

declare(strict_types=1);

namespace App\Application\Actions\Lesson;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DeleteLessonAction
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

        try {
            // Begin transaction
            $this->db->beginTransaction();

            // Verify ownership
            $stmt = $this->db->prepare(
                "SELECT id FROM lessons WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$lessonId, $userId]);
            $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lesson) {
                $this->db->rollBack();
                return JsonResponse::error(
                    $response,
                    'Lesson not found or you do not have permission to delete it',
                    404,
                    'NOT_FOUND'
                );
            }

            // Delete from lessons table (CASCADE will handle generations)
            // Note: If there's no CASCADE, we need to delete from generations first
            $stmt = $this->db->prepare("DELETE FROM lessons WHERE id = ? AND user_id = ?");
            $stmt->execute([$lessonId, $userId]);

            $this->db->commit();

            return JsonResponse::success($response, [
                'message' => 'Lesson deleted successfully',
                'id' => $lessonId
            ]);

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return JsonResponse::error(
                $response,
                'Failed to delete lesson: ' . $e->getMessage(),
                500,
                'DELETE_FAILED'
            );
        }
    }
}

