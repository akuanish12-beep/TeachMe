<?php

declare(strict_types=1);

namespace App\Application\Actions\Lesson;

use App\Application\Helpers\JsonResponse;
use App\Application\Helpers\Validator;
use App\Services\GeminiService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GenerateLessonAction
{
    private PDO $db;
    private GeminiService $geminiService;

    public function __construct(PDO $db, GeminiService $geminiService)
    {
        $this->db = $db;
        $this->geminiService = $geminiService;
    }

    public function __invoke(Request $request, Response $response): Response
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
            // Return enhanced 402 Payment Required with upgrade info
            $errorResponse = [
                'error' => $canGenerate['error'],
                'message' => $canGenerate['message'],
                'upgrade' => $canGenerate['upgrade']
            ];
            
            $response->getBody()->write(json_encode($errorResponse));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Reason', 'payment_required')
                ->withStatus(402);
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
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return JsonResponse::error(
                $response,
                'Failed to generate lesson: ' . $e->getMessage(),
                500,
                'GENERATION_FAILED'
            );
        }
    }

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

        // Free trial already used - return enhanced payment required message
        return [
            'allowed' => false,
            'error' => 'payment_required',
            'message' => 'Free trial used. Upgrade to Pro ($9/month) to continue.',
            'upgrade' => [
                'price' => 9,
                'currency' => 'USD',
                'plan' => 'pro_monthly'
            ]
        ];
    }
}

