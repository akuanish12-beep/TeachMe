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
    private ?LoggerInterface $logger;
    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_DELAYS = [0, 0.5, 1.0]; // seconds

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->httpClient = new Client(['timeout' => 60]);
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        $this->logger = $logger;
    }

    /**
     * Generate a language learning lesson
     */
    public function generateLesson(string $topic, string $language): array
    {
        // Check if mock mode
        if (empty($this->apiKey) || $this->apiKey === 'PLACEHOLDER_TO_BE_FILLED') {
            $this->log('info', 'Using mock mode for lesson generation');
            return $this->getMockResponse();
        }

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
You are an AI language tutor. Your role is to ONLY produce language tutoring content.

CRITICAL REQUIREMENTS:
1. Output ONLY valid JSON matching EXACTLY this schema (no markdown, no comments, no explanatory text):
{$schemaJson}

2. All natural language content MUST be in {$language}.

3. Limit total output to approximately 1200 words.

4. Exercise type examples (for your reference, do NOT include these in output):
   - fill_in_the_blanks: "Complete: I ___ to the store" with answer ["go", "went"]
   - translate_phrase: Translate "Hello" to target language
   - answer_question: "What is a greeting?" expecting key points

5. If you cannot comply, return minimal valid JSON with empty arrays for exercises.

STRICT OUTPUT FORMAT:
- Start with { and end with }
- NO markdown code fences (no ```json)
- NO explanatory text before or after JSON
- NO comments inside JSON
- Use standard double quotes only
- No trailing commas

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
            // Note: Using v1beta with gemini-1.5-flash (fallback if API key has limited model access)
            $response = $this->httpClient->post(
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$this->apiKey}",
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
                            'maxOutputTokens' => 2048,
                        ]
                    ]
                ]
            );

            $body = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                throw new \RuntimeException('Unexpected Gemini API response structure');
            }

            return $body['candidates'][0]['content']['parts'][0]['text'];
            
        } catch (GuzzleException $e) {
            $maskedMessage = str_replace($this->apiKey, '***REDACTED***', $e->getMessage());
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
     * Get deterministic mock response for testing
     */
    private function getMockResponse(): array
    {
        return [
            'topic' => 'Spanish Greetings',
            'language' => 'Spanish',
            'title' => 'Introduction to Spanish Greetings',
            'sections' => [
                [
                    'heading' => 'Basic Greetings',
                    'body' => 'In Spanish, the most common greeting is "Hola" (Hello). You can use it at any time of day with anyone. For a more formal greeting, you can say "Buenos días" (Good morning), "Buenas tardes" (Good afternoon), or "Buenas noches" (Good evening/night).'
                ],
                [
                    'heading' => 'Asking How Someone Is',
                    'body' => 'After greeting someone, it\'s polite to ask how they are. You can say "¿Cómo estás?" (How are you? - informal) or "¿Cómo está?" (How are you? - formal). Common responses include "Bien, gracias" (Good, thank you) or "Muy bien" (Very good).'
                ]
            ],
            'exercises' => [
                'fill_in_the_blanks' => [
                    [
                        'prompt' => 'Complete the greeting',
                        'text_with_gaps' => '___ días, ¿cómo estás?',
                        'answers' => ['Buenos']
                    ],
                    [
                        'prompt' => 'Fill in the response',
                        'text_with_gaps' => 'Hola, ___ bien, gracias.',
                        'answers' => ['estoy', 'muy']
                    ]
                ],
                'translate_phrase' => [
                    [
                        'prompt' => 'Translate to Spanish',
                        'source' => 'Good morning',
                        'target_hint' => 'Buenos ___'
                    ],
                    [
                        'prompt' => 'Translate to Spanish',
                        'source' => 'How are you? (informal)',
                        'target_hint' => '¿Cómo ___?'
                    ]
                ],
                'answer_question' => [
                    [
                        'prompt' => 'Answer in your own words',
                        'question' => '¿Cuándo usas "Buenos días"?',
                        'expected_points' => [
                            'In the morning',
                            'As a formal greeting',
                            'Until noon or early afternoon'
                        ]
                    ]
                ]
            ]
        ];
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
