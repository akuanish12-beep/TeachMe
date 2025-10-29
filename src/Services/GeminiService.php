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
    private const BACKOFF_DELAYS = [0, 0.5, 1.0]; // seconds

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
        
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $this->log('info', "Gemini API attempt {$attempt}/{" . self::MAX_ATTEMPTS . "} for topic: {$topic}");
                
                // Add delay for retries (exponential backoff)
                if ($attempt > 1) {
                    $delay = self::BACKOFF_DELAYS[$attempt - 1];
                    $this->log('info', "Waiting {$delay}s before retry...");
                    usleep((int)($delay * 1000000));
                }

                $prompt = $this->buildPrompt($topic, $language, $attempt);
                $rawResponse = $this->callGeminiAPI($prompt);
                
                // Sanitize and decode JSON
                $lessonData = $this->sanitizeAndDecodeJson($rawResponse);
                
                // Validate against schema
                $this->validateAgainstSchema($lessonData);
                
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
                
            } catch (\Exception $e) {
                $lastException = $e;
                $this->log('error', "Attempt {$attempt} - Unexpected error: " . $e->getMessage());
                throw $e; // Re-throw unexpected errors immediately
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
     * Build the prompt with constraints
     */
    private function buildPrompt(string $topic, string $language, int $attempt): string
    {
        $schemaJson = json_encode(LessonSchema::getSchema(), JSON_PRETTY_PRINT);
        
        $systemPrompt = <<<PROMPT
You are an AI language tutor. Output ONLY valid JSON (no markdown, no comments).

Schema:
{$schemaJson}

Rules:
1. All content in {$language}
2. 2-3 sections max, ~600 words total
3. 2 exercises per type minimum
4. Pure JSON output (no ```json fences)
5. Start with { end with }

PROMPT;

        // Add retry-specific guidance
        if ($attempt > 1) {
            $systemPrompt .= "\n\nPREVIOUS ATTEMPT FAILED. You MUST output STRICT JSON with NO markdown formatting.";
        }

        $userPrompt = "Generate a language learning lesson about \"{$topic}\" in {$language}.";

        return $systemPrompt . "\n\n" . $userPrompt;
    }

    /**
     * Call Gemini API
     */
    private function callGeminiAPI(string $prompt): string
    {
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
            $statusCode = $e->getCode();
            $maskedMessage = str_replace($this->apiKey, '***REDACTED***', $e->getMessage());
            
            // Handle model unavailability
            if ($statusCode === 404 || $statusCode === 400) {
                $this->log('error', "Model unavailable: {$this->model} - " . $maskedMessage);
                throw new \RuntimeException(
                    "Gemini model '{$this->model}' is not available or invalid. Please check GEMINI_MODEL configuration.",
                    502
                );
            }
            
            $this->log('error', 'Gemini API request failed: ' . $maskedMessage);
            throw new \RuntimeException('Gemini API request failed: ' . $maskedMessage);
        }
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
