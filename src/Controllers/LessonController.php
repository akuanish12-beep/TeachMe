<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Helpers\JsonResponse;
use App\Application\Helpers\Validator;
use App\Services\GeminiService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LessonController
{
    private PDO $db;
    private GeminiService $geminiService;

    public function __construct(PDO $db, GeminiService $geminiService)
    {
        $this->db = $db;
        $this->geminiService = $geminiService;
    }

    /**
     * POST /lessons/generate
     * Generate a new lesson using Gemini AI
     */
    public function generate(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody();

        // Validate input
        $validator = new Validator();
        $validator
            ->required($data, ['topic', 'language'])
            ->maxLength($data, 'topic', 255)
            ->maxLength($data, 'language', 80);

        if ($validator->fails()) {
            return JsonResponse::validationError($response, $validator->errors());
        }

        // Check subscription status and generation limits
        $canGenerate = $this->checkGenerationPermission($userId);
        
        if (!$canGenerate['allowed']) {
            return JsonResponse::error(
                $response,
                $canGenerate['message'],
                402,
                'PAYMENT_REQUIRED'
            );
        }

        try {
            // Generate lesson using Gemini
            $lessonData = $this->geminiService->generateLesson(
                $data['topic'],
                $data['language']
            );

            // Begin transaction
            $this->db->beginTransaction();

            // Insert into lessons table
            $stmt = $this->db->prepare(
                "INSERT INTO lessons (user_id, topic, language, title, content_json) 
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $data['topic'],
                $data['language'],
                $lessonData['title'],
                json_encode($lessonData)
            ]);

            $lessonId = (int) $this->db->lastInsertId();

            // Insert into generations table (tracks usage)
            $stmt = $this->db->prepare(
                "INSERT INTO generations (user_id, topic, language, result_json) 
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $data['topic'],
                $data['language'],
                json_encode($lessonData)
            ]);

            $this->db->commit();

            return JsonResponse::success($response, [
                'id' => $lessonId,
                'lesson' => $lessonData
            ], 201);

        } catch (\Exception $e) {
            $this->db->rollBack();
            return JsonResponse::error(
                $response,
                'Failed to generate lesson: ' . $e->getMessage(),
                500,
                'GENERATION_FAILED'
            );
        }
    }

    /**
     * GET /lessons
     * List all lessons for the authenticated user
     */
    public function list(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        
        // Parse pagination parameters
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($params['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        // Get total count
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM lessons WHERE user_id = ?");
        $stmt->execute([$userId]);
        $total = (int) $stmt->fetchColumn();

        // Get lessons
        $stmt = $this->db->prepare(
            "SELECT id, topic, language, title, created_at 
             FROM lessons 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $perPage, $offset]);
        $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format timestamps
        foreach ($lessons as &$lesson) {
            $lesson['id'] = (int) $lesson['id'];
        }

        return JsonResponse::success($response, [
            'lessons' => $lessons,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage)
            ]
        ]);
    }

    /**
     * GET /lessons/{id}
     * Get full lesson content by ID
     */
    public function get(Request $request, Response $response, array $args): Response
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

    /**
     * Check if user can generate a lesson based on subscription and usage
     */
    private function checkGenerationPermission(int $userId): array
    {
        // Check subscription status
        $stmt = $this->db->prepare(
            "SELECT status FROM subscriptions WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($subscription && $subscription['status'] === 'active') {
            return ['allowed' => true];
        }

        // Not subscribed - check free trial usage
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM generations WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $generationCount = (int) $stmt->fetchColumn();

        if ($generationCount === 0) {
            // First generation - allow as free trial
            return ['allowed' => true];
        }

        // Free trial already used
        return [
            'allowed' => false,
            'message' => 'Free trial used. Upgrade to Pro to continue generating lessons.'
        ];
    }
}

