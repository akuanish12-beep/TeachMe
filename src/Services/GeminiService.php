<?php

declare(strict_types=1);

namespace App\Services;

use App\Schemas\LessonSchema;
use App\Exceptions\GeminiInvalidJsonException;
use App\Exceptions\GeminiSchemaViolationException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class GeminiService
{
    private Client $httpClient;
    private string $apiKey;
    private string $model;
    private ?LoggerInterface $logger;
    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_DELAYS = [0.5, 1.0, 2.0]; // seconds for HTTP errors
    private const RETRY_BACKOFF_DELAYS = [0, 0.5, 1.0]; // seconds for JSON/schema errors
    private const OFF_TOPIC_KEYWORDS = ['stock price', 'weather api', 'programming code', 'crypto', 'bitcoin', 'sql query', 'javascript function'];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->httpClient = new Client(['timeout' => 60]);
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        $this->model = $_ENV['GEMINI_MODEL'] ?? '';
        $this->logger = $logger;

        // Validate configuration
        if (empty($this->apiKey)) {
            throw new \RuntimeException('GEMINI_API_KEY not configured');
        }

        if (empty($this->model)) {
            throw new \RuntimeException('GEMINI_MODEL not configured');
        }

        $this->log('info', "GeminiService initialized with model: {$this->model}");
    }

    /**
     * Generate a language learning lesson
     */
    public function generateLesson(string $topic, string $language): array
    {
        $lastException = null;
        $offTopicAttempt = false;
        
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $this->log('info', "Gemini API attempt {$attempt}/{" . self::MAX_ATTEMPTS . "} for topic: {$topic}");
                
                // Add delay for retries (exponential backoff for JSON/schema errors)
                if ($attempt > 1 && !($lastException instanceof \RuntimeException)) {
                    $delay = self::RETRY_BACKOFF_DELAYS[$attempt - 1];
                    $this->log('info', "Waiting {$delay}s before retry...");
                    usleep((int)($delay * 1000000));
                }

                $prompt = $this->buildPrompt($topic, $language, $attempt, $offTopicAttempt);
                $rawResponse = $this->callGeminiAPI($prompt, $attempt);
                
                // Sanitize and decode JSON
                $lessonData = $this->sanitizeAndDecodeJson($rawResponse);
                
                // Validate against schema
                $this->validateAgainstSchema($lessonData);
                
                // Semantic guard: check for off-topic content
                if (!$offTopicAttempt && $this->isOffTopic($lessonData)) {
                    $this->log('warning', 'Off-topic content detected, retrying with strict constraint', [
                        'title' => $lessonData['title'] ?? 'N/A'
                    ]);
                    $offTopicAttempt = true;
                    continue; // Retry with strict prompt
                }
                
                // Semantic guard: check for empty content
                if (!$this->hasMinimalContent($lessonData)) {
                    throw new GeminiSchemaViolationException(
                        'Generated lesson has insufficient content',
                        ['Sections or exercises are empty']
                    );
                }
                
                $this->log('info', 'Successfully generated and validated lesson');
                return $lessonData;
                
            } catch (GeminiInvalidJsonException $e) {
                $lastException = $e;
                $this->log('error', "Attempt {$attempt} - Invalid JSON: " . $e->getMessage(), [
                    'raw_payload' => substr($e->getRawPayload(), 0, 500)
                ]);
                
            } catch (GeminiSchemaViolationException $e) {
                $lastException = $e;
                $this->log('error', "Attempt {$attempt} - Schema violation: " . $e->getMessage(), [
                    'violations' => $e->getViolationDetails()
                ]);
                
            } catch (\RuntimeException $e) {
                // HTTP errors (429, 5xx) - already handled with backoff in callGeminiAPI
                $lastException = $e;
                $this->log('error', "Attempt {$attempt} - API error: " . $e->getMessage());
                
                // Non-retryable errors (400/403/404)
                if (strpos($e->getMessage(), 'Non-retryable') !== false) {
                    throw $e;
                }
            }
        }

        // All attempts failed
        $this->log('error', 'All Gemini API attempts exhausted');
        throw new \RuntimeException(
            'Failed to generate valid lesson after ' . self::MAX_ATTEMPTS . ' attempts: ' . 
            ($lastException ? $lastException->getMessage() : 'Unknown error')
        );
    }

    /**
     * Build the prompt with hardened constraints
     */
    private function buildPrompt(string $topic, string $language, int $attempt, bool $strictTutor = false): string
    {
        $schemaJson = json_encode(LessonSchema::getSchema(), JSON_PRETTY_PRINT);
        
        $systemPrompt = <<<PROMPT
NON-NEGOTIABLE CONSTRAINTS:

Role: You are an AI language tutor. Generate ONLY language tutoring content (explanations, examples, exercises). NO other topics.

Output Format: Return ONLY strict JSON per schema below. NO markdown fences. NO commentary. NO text before or after JSON.

Language Discipline: ALL natural language fields (title, body, prompts, etc.) MUST be in {$language}. This is mandatory.

Safety: If uncertain about ANY requirement, minimize output but keep valid JSON with empty arrays for exercises.

Size Limit: Maximum ~1200 words total across all sections and exercises.

JSON Schema:
{$schemaJson}

OUTPUT RULES:
- Start with { and end with }
- NO ```json fences
- NO explanatory text
- Use double quotes only
- No trailing commas

PROMPT;

        // Add strict tutor constraint if off-topic detected
        if ($strictTutor) {
            $systemPrompt .= "\n\nSTRICT TUTOR CONTENT ONLY: Previous attempt contained non-tutoring content. You MUST generate ONLY language learning material about \"{$topic}\". No stock prices, weather, programming code, or unrelated topics.";
        }

        // Add retry-specific guidance
        if ($attempt > 1) {
            $systemPrompt .= "\n\nPREVIOUS ATTEMPT FAILED: You MUST output STRICT JSON with NO markdown formatting. Ensure all schema requirements are met.";
        }

        $userPrompt = "Generate a comprehensive language learning lesson about \"{$topic}\" in {$language}. Include clear explanations and practical exercises.";

        return $systemPrompt . "\n\n" . $userPrompt;
    }

    /**
     * Call Gemini API with retry logic for HTTP errors
     */
    private function callGeminiAPI(string $prompt, int $attempt): string
    {
        $httpAttempt = 0;
        $maxHttpRetries = 3;
        
        while ($httpAttempt < $maxHttpRetries) {
            try {
                $response = $this->httpClient->post(
                    "https://generativelanguage.googleapis.com/v1beta/{$this->model}:generateContent?key={$this->apiKey}",
                    [
                        'json' => [
                            'contents' => [
                                [
                                    'parts' => [
                                        ['text' => $prompt]
                                    ]
                                ]
                            ],
                            'generationConfig' => [
                                'temperature' => 0.4,  // Lower for more deterministic output
                                'topK' => 40,
                                'topP' => 0.95,
                                'maxOutputTokens' => 4096,  // Increased for full lesson generation
                            ]
                        ]
                    ]
                );

                $rawBody = $response->getBody()->getContents();
                $body = json_decode($rawBody, true);
                
                // Check for finish reason MAX_TOKENS
                if (isset($body['candidates'][0]['finishReason']) 
                    && $body['candidates'][0]['finishReason'] === 'MAX_TOKENS'
                ) {
                    $this->log('error', 'Gemini hit token limit', ['response' => substr($rawBody, 0, 500)]);
                    throw new \RuntimeException('Gemini response truncated due to token limit. Please reduce lesson scope.');
                }
                
                if (!isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                    $this->log('error', 'Unexpected Gemini API response structure', [
                        'response' => substr($rawBody, 0, 500)
                    ]);
                    throw new \RuntimeException('Unexpected Gemini API response structure');
                }

                return $body['candidates'][0]['content']['parts'][0]['text'];
                
            } catch (GuzzleException $e) {
                $httpAttempt++;
                $statusCode = $e->getCode();
                $maskedMessage = str_replace($this->apiKey, '***REDACTED***', $e->getMessage());
                
                // Non-retryable errors (400, 403, 404)
                if (in_array($statusCode, [400, 403, 404])) {
                    $this->log('error', "Non-retryable HTTP error {$statusCode}: " . $maskedMessage);
                    throw new \RuntimeException(
                        "Non-retryable: Gemini API error (HTTP {$statusCode}). Code: GENERATION_FAILED. " . $maskedMessage,
                        502
                    );
                }
                
                // Retryable errors (429, 5xx)
                if ($statusCode === 429 || $statusCode >= 500) {
                    $this->log('warning', "Retryable HTTP error {$statusCode} (attempt {$httpAttempt}/{$maxHttpRetries}): " . $maskedMessage);
                    
                    if ($httpAttempt < $maxHttpRetries) {
                        $delay = self::BACKOFF_DELAYS[$httpAttempt - 1];
                        $this->log('info', "Exponential backoff: waiting {$delay}s before HTTP retry...");
                        usleep((int)($delay * 1000000));
                        continue; // Retry
                    } else {
                        // Max retries exhausted
                        throw new \RuntimeException(
                            "Gemini API failed after {$maxHttpRetries} HTTP retries (HTTP {$statusCode}). " . $maskedMessage,
                            502
                        );
                    }
                }
                
                // Unknown error
                $this->log('error', 'Gemini API request failed: ' . $maskedMessage);
                throw new \RuntimeException('Gemini API request failed: ' . $maskedMessage);
            }
        }
        
        throw new \RuntimeException('Gemini API: Max HTTP retries exhausted');
    }

    /**
     * Sanitize and decode JSON from raw response
     */
    private function sanitizeAndDecodeJson(string $raw): array
    {
        // Step 1: Trim whitespace
        $cleaned = trim($raw);

        // Step 2: Remove markdown code fences if present
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $cleaned, $matches)) {
            $cleaned = trim($matches[1]);
        }

        // Step 3: Strip BOM if present
        $cleaned = preg_replace('/^\xEF\xBB\xBF/', '', $cleaned);

        // Step 4: First attempt to decode
        try {
            return json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // JSON is invalid, attempt minimal repair
            $this->log('warning', 'Initial JSON decode failed, attempting repair');
        }

        // Step 5: Minimal repair
        $repaired = $cleaned;

        // Remove trailing commas in arrays and objects
        $repaired = preg_replace('/,\s*([}\]])/', '$1', $repaired);

        // Replace smart quotes with standard quotes
        $smartQuotes = ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"];
        $standardQuotes = ['"', '"', "'", "'"];
        $repaired = str_replace($smartQuotes, $standardQuotes, $repaired);

        // Remove leading text before first { and trailing text after last }
        if (preg_match('/\{.*\}/s', $repaired, $matches)) {
            $repaired = $matches[0];
        }

        // Step 6: Second attempt to decode
        try {
            return json_decode($repaired, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Still invalid
            throw new GeminiInvalidJsonException(
                'Failed to decode JSON even after repair: ' . $e->getMessage(),
                $raw
            );
        }
    }

    /**
     * Validate data against LessonSchema
     */
    private function validateAgainstSchema(array $data): void
    {
        $errors = LessonSchema::validate($data);
        
        if (!empty($errors)) {
            throw new GeminiSchemaViolationException(
                'Lesson data violates schema',
                $errors
            );
        }
    }

    /**
     * Check if content is off-topic (not language tutoring)
     */
    private function isOffTopic(array $lessonData): bool
    {
        $title = strtolower($lessonData['title'] ?? '');
        $sections = $lessonData['sections'] ?? [];
        
        // Check title for off-topic keywords
        foreach (self::OFF_TOPIC_KEYWORDS as $keyword) {
            if (strpos($title, strtolower($keyword)) !== false) {
                return true;
            }
        }
        
        // Check first section body (if exists)
        if (!empty($sections) && isset($sections[0]['body'])) {
            $firstBody = strtolower($sections[0]['body']);
            foreach (self::OFF_TOPIC_KEYWORDS as $keyword) {
                if (strpos($firstBody, strtolower($keyword)) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check if lesson has minimal content (not empty)
     */
    private function hasMinimalContent(array $lessonData): bool
    {
        // Must have at least 1 section
        $sections = $lessonData['sections'] ?? [];
        if (empty($sections)) {
            return false;
        }
        
        // Must have at least one non-empty exercise type
        $exercises = $lessonData['exercises'] ?? [];
        $hasExercises = false;
        
        foreach (['fill_in_the_blanks', 'translate_phrase', 'answer_question'] as $type) {
            if (!empty($exercises[$type]) && is_array($exercises[$type]) && count($exercises[$type]) > 0) {
                $hasExercises = true;
                break;
            }
        }
        
        return $hasExercises;
    }

    /**
     * Log helper (gracefully handles missing logger)
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        } else {
            // Fallback to error_log
            $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
            error_log("[{$level}] {$message}{$contextStr}");
        }
    }
}
