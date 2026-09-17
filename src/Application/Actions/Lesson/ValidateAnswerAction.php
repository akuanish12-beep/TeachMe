<?php

declare(strict_types=1);

namespace App\Application\Actions\Lesson;

use App\Application\Actions\Action;
use App\Services\GeminiService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ValidateAnswerAction extends Action
{
    private GeminiService $geminiService;

    public function __construct(GeminiService $geminiService, ?LoggerInterface $logger = null)
    {
        parent::__construct($logger);
        $this->geminiService = $geminiService;
    }

    protected function action(): Response
    {
        $data = $this->getFormData();
        
        // Validate required fields
        if (!isset($data['question']) || !isset($data['userAnswer']) || !isset($data['expectedAnswer'])) {
            return $this->respondWithData([
                'error' => 'Missing required fields: question, userAnswer, expectedAnswer'
            ], 400);
        }

        $question = trim($data['question']);
        $userAnswer = trim($data['userAnswer']);
        $expectedAnswer = trim($data['expectedAnswer']);

        // Quick checks for empty answers
        if (empty($userAnswer)) {
            return $this->respondWithData([
                'isCorrect' => false,
                'explanation' => 'Please provide an answer before checking.'
            ]);
        }

        // Exact match check (case-insensitive)
        if (strtolower($userAnswer) === strtolower($expectedAnswer)) {
            return $this->respondWithData([
                'isCorrect' => true,
                'explanation' => 'Perfect match! Your answer is correct.'
            ]);
        }

        // Check if user answer contains expected answer or vice versa
        if (stripos($userAnswer, $expectedAnswer) !== false || stripos($expectedAnswer, $userAnswer) !== false) {
            return $this->respondWithData([
                'isCorrect' => true,
                'explanation' => 'Great! Your answer captures the key concept.'
            ]);
        }

        try {
            // Use AI to validate semantic similarity
            $prompt = $this->buildValidationPrompt($question, $userAnswer, $expectedAnswer);
            
            $this->logger->info('Validating answer with AI', [
                'question' => $question,
                'user_answer' => $userAnswer,
                'expected_answer' => $expectedAnswer
            ]);

            $aiResponse = $this->geminiService->generateContent($prompt);
            
            // Parse AI response
            $result = $this->parseAIResponse($aiResponse);
            
            $this->logger->info('AI validation result', [
                'is_correct' => $result['isCorrect'],
                'explanation' => $result['explanation']
            ]);

            return $this->respondWithData($result);

        } catch (\Exception $e) {
            $this->logger->error('AI validation failed', [
                'error' => $e->getMessage()
            ]);

            // Fallback to similarity check
            $similarity = $this->calculateSimilarity($userAnswer, $expectedAnswer);
            
            if ($similarity >= 0.7) {
                return $this->respondWithData([
                    'isCorrect' => true,
                    'explanation' => 'Good! Your answer demonstrates understanding of the concept.'
                ]);
            }

            return $this->respondWithData([
                'isCorrect' => false,
                'explanation' => sprintf(
                    'Not quite. The expected answer is: "%s". Your answer should focus on the key concepts.',
                    $expectedAnswer
                )
            ]);
        }
    }

    private function buildValidationPrompt(string $question, string $userAnswer, string $expectedAnswer): string
    {
        return <<<PROMPT
You are an educational AI assistant validating student answers.

**Question/Task:**
{$question}

**Student's Answer:**
{$userAnswer}

**Expected Answer:**
{$expectedAnswer}

**Your Task:**
Evaluate if the student's answer demonstrates understanding of the concept, even if worded differently.

**Response Format (JSON only):**
{
  "isCorrect": true/false,
  "explanation": "Brief explanation (max 2 sentences)"
}

**Criteria:**
- Award "isCorrect": true if the student captures the main idea, even with different wording
- Award "isCorrect": false only if the answer is clearly wrong or completely misses the point
- Be lenient with minor spelling or grammatical errors
- Focus on semantic meaning, not exact wording
- Keep explanation encouraging and educational

Respond ONLY with valid JSON.
PROMPT;
    }

    private function parseAIResponse(string $aiResponse): array
    {
        // Try to extract JSON from response
        $cleanResponse = trim($aiResponse);
        
        // Remove markdown code blocks if present
        $cleanResponse = preg_replace('/```json\s*/', '', $cleanResponse);
        $cleanResponse = preg_replace('/```\s*$/', '', $cleanResponse);
        $cleanResponse = trim($cleanResponse);
        
        // Try to find JSON object
        if (preg_match('/\{[^}]+\}/', $cleanResponse, $matches)) {
            $cleanResponse = $matches[0];
        }
        
        $decoded = json_decode($cleanResponse, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Fallback parsing
            $isCorrect = stripos($aiResponse, '"isCorrect": true') !== false ||
                        stripos($aiResponse, 'correct') !== false;
            
            return [
                'isCorrect' => $isCorrect,
                'explanation' => $isCorrect 
                    ? 'Your answer demonstrates good understanding!' 
                    : 'Please review the concept and try again.'
            ];
        }
        
        return [
            'isCorrect' => (bool)($decoded['isCorrect'] ?? false),
            'explanation' => (string)($decoded['explanation'] ?? 'Answer validated.')
        ];
    }

    private function calculateSimilarity(string $str1, string $str2): float
    {
        $str1 = strtolower(trim($str1));
        $str2 = strtolower(trim($str2));
        
        // Levenshtein distance
        $distance = levenshtein($str1, $str2);
        $maxLen = max(strlen($str1), strlen($str2));
        
        if ($maxLen === 0) {
            return 1.0;
        }
        
        return 1.0 - ($distance / $maxLen);
    }
}

