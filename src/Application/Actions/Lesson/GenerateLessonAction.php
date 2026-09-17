<?php

declare(strict_types=1);

namespace App\Application\Actions\Lesson;

use App\Application\Helpers\JsonResponse;
use App\Application\Helpers\Validator;
use App\Services\GeminiService;
use App\Exceptions\GeminiInvalidJsonException;
use App\Exceptions\GeminiSchemaViolationException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class GenerateLessonAction
{
    private PDO $db;
    private GeminiService $geminiService;
    private ?LoggerInterface $logger;

    public function __construct(PDO $db, GeminiService $geminiService, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->geminiService = $geminiService;
        $this->logger = $logger;
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

        $attemptCount = 0;
        
        try {
            $attemptCount++;
            
            // Generate lesson using Gemini (with retries built-in)
            $lessonData = $this->geminiService->generateLesson(
                $data['topic'],
                $data['language'],
                (int) $userId
            );

            // Begin transaction
            $this->db->beginTransaction();

            try {
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

                $this->log('info', 'Lesson generated and persisted successfully', [
                    'user_id' => $userId,
                    'lesson_id' => $lessonId,
                    'topic' => $data['topic'],
                    'language' => $data['language']
                ]);

                return JsonResponse::success($response, [
                    'id' => $lessonId,
                    'lesson' => $lessonData
                ], 201);
                
            } catch (\Exception $dbError) {
                $this->db->rollBack();
                throw $dbError;
            }

        } catch (GeminiInvalidJsonException | GeminiSchemaViolationException $e) {
            // AI generation failed (invalid JSON or schema violation)
            $this->log('error', 'AI generation failed', [
                'user_id' => $userId,
                'topic' => $data['topic'],
                'language' => $data['language'],
                'attempt_count' => $attemptCount,
                'error' => $e->getMessage()
            ]);
            
            return JsonResponse::error(
                $response,
                'AI generation failed. Please try again.',
                502,
                'generation_error'
            );
            
        } catch (\RuntimeException $e) {
            // HTTP or API errors
            $this->log('error', 'Generation service error', [
                'user_id' => $userId,
                'topic' => $data['topic'],
                'language' => $data['language'],
                'attempt_count' => $attemptCount,
                'error' => $e->getMessage()
            ]);
            
            return JsonResponse::error(
                $response,
                'AI generation failed. Please try again.',
                502,
                'generation_error'
            );
            
        } catch (\Exception $e) {
            // Database or unexpected errors
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            
            $this->log('error', 'Unexpected error during lesson generation', [
                'user_id' => $userId,
                'topic' => $data['topic'],
                'language' => $data['language'],
                'attempt_count' => $attemptCount,
                'error' => $e->getMessage()
            ]);
            
            return JsonResponse::error(
                $response,
                'Failed to generate lesson: ' . $e->getMessage(),
                500,
                'GENERATION_FAILED'
            );
        }
    }
    
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
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

