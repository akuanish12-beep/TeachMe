<?php

declare(strict_types=1);

namespace App\Services;

use App\Schemas\LessonSchema;
use App\Exceptions\GeminiInvalidJsonException;
use App\Exceptions\GeminiSchemaViolationException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PDO;
use Psr\Log\LoggerInterface;

class GeminiService
{
    private Client $httpClient;
    private string $platformApiKey;
    private string $model;
    private ?LoggerInterface $logger;
    private PDO $db;
    private EncryptionService $encryption;
    private SubscriptionTierService $tiers;
    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_DELAYS = [0.5, 1.0, 2.0]; // seconds for HTTP errors
    private const RETRY_BACKOFF_DELAYS = [0, 0.5, 1.0]; // seconds for JSON/schema errors
    private const OFF_TOPIC_KEYWORDS = ['stock price', 'weather api', 'programming code', 'crypto', 'bitcoin', 'sql query', 'javascript function'];

    public function __construct(
        PDO $db,
        EncryptionService $encryption,
        SubscriptionTierService $tiers,
        ?LoggerInterface $logger = null
    ) {
        $this->httpClient = new Client(['timeout' => 60]);
        $this->db = $db;
        $this->encryption = $encryption;
        $this->tiers = $tiers;
        $this->platformApiKey = env('GEMINI_API_KEY', '');
        $this->model = env('GEMINI_MODEL', '');
        $this->logger = $logger;

        if (empty($this->platformApiKey)) {
            throw new \RuntimeException('GEMINI_API_KEY not configured');
        }

        if (empty($this->model)) {
            throw new \RuntimeException('GEMINI_MODEL not configured');
        }

        $this->log('info', "GeminiService initialized with model: {$this->model}");
    }

    /**
     * Validate a Gemini API key with a minimal request (used when saving BYOK).
     */
    public function verifyApiKey(string $apiKey): bool
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return false;
        }

        try {
            $this->callGeminiAPI('Reply with exactly: OK', 1, $apiKey, [
                'maxOutputTokens' => 100,
                'thinkingLevel' => 'minimal'
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Generate an AI lesson for any topic
     */
    public function generateLesson(string $topic, string $language, ?int $userId = null): array
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
                $rawResponse = $this->callGeminiAPI($prompt, $attempt, $userId, [
                    'maxOutputTokens' => 8192,
                    'thinkingLevel' => 'low'
                ]);
                
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
     * Build the prompt with hardened constraints and rich contextual content
     */
    private function buildPrompt(string $topic, string $language, int $attempt, bool $strictTutor = false): string
    {
        $schemaJson = json_encode(LessonSchema::getSchema());
        
        $systemPrompt = <<<PROMPT
NON-NEGOTIABLE CONSTRAINTS:

Role: You are an expert educator and course creator building practical, contextual lessons on any subject.

Output Format: Return ONLY strict JSON per schema below. NO markdown fences. NO commentary. NO text before or after JSON.

Language Discipline: ALL natural language fields (title, body, prompts, etc.) MUST be in {$language}. This is mandatory.

NO MARKDOWN: Do NOT use **, ***, *, or any markdown formatting in text fields. Write plain text only.

Content Focus: Create practical, real-world lessons about: {$topic}
- Teach key concepts, terms, and skills relevant to the topic (adapt structure for languages vs technical/professional subjects)
- Use clear, simple explanations (not overly academic)
- Include real-life examples and scenarios
- Make it immediately useful for the learner's goal

LESSON STRUCTURE REQUIREMENTS:

1. VOCABULARY SECTION (Required):
   - Create a section titled "Vocabulary Terms" or "Key Vocabulary"
   - Include 5-6 important terms related to the topic
   - Format EXACTLY as: "Term: Definition"
   - Then on next line: "Example: [sentence using the term]"
   - NO ** symbols, NO markdown
   - Keep examples CONCISE (one short sentence)

2. GRAMMAR FOCUS (Optional):
   - Include 1 relevant grammar point if highly relevant
   - Explain the rule in simple terms
   - Provide 2 brief examples
   - SKIP if not critical to the topic

3. USEFUL PHRASES (Required):
   - Create a section titled "Useful Phrases"
   - Include 4-5 practical phrases
   - Format as numbered list: "1. Phrase here"
   - Focus on what people actually say in real situations
   - Keep it BRIEF - NO extra text

4. DIALOGUE/SCENARIO (Optional):
   - Include a SHORT dialogue (4-6 lines total)
   - Show the vocabulary and phrases in context
   - Or SKIP to save space for exercises

5. EXERCISES (Required - Multiple Choice ONLY):
   You MUST include EXACTLY 5 multiple_choice exercises:
   
   - Questions with 4 options (A, B, C, D)
   - One correct answer
   - Include BRIEF explanation (one sentence)
   - Test vocabulary, comprehension, and practical usage
   - Mix difficulty levels
   - Cover different aspects of the lesson topic

SIZE LIMIT: Maximum ~600 words total. Keep content CONCISE.

JSON Schema:
{$schemaJson}

OUTPUT RULES:
- Start with { and end with }
- NO ```json fences
- NO markdown symbols (**, *, _)
- Use double quotes only
- No trailing commas
- Plain text in all content fields

PROMPT;

        // Add strict tutor constraint if off-topic detected
        if ($strictTutor) {
            $systemPrompt .= "\n\nSTRICT EDUCATOR MODE: Previous attempt was off-topic. You MUST generate ONLY educational course material about \"{$topic}\". No unrelated news, stock tips, or content outside the learning goal.";
        }

        // Add retry-specific guidance
        if ($attempt > 1) {
            $systemPrompt .= "\n\nPREVIOUS ATTEMPT FAILED: You MUST output STRICT JSON with NO markdown formatting. Ensure all schema requirements are met. Remember: NO ** or * symbols in text.";
        }

        $userPrompt = <<<USER
Generate a CONCISE, practical lesson about "{$topic}". Write all learner-facing text in {$language}.

Include:
- Vocabulary section with 5-6 terms ONLY
  Format: "Term: Definition" on one line
  Then "Example: [usage]" on next line
- 4-5 useful phrases (just list them, numbered)
- EXACTLY 5 multiple choice exercises (ONLY multiple_choice type)
- Skip grammar and dialogue to save space

CRITICAL EXERCISE REQUIREMENTS:
- Generate EXACTLY 5 exercises (not 10, not 6, EXACTLY 5)
- ONLY multiple_choice type exercises
- Do NOT include fill_in_the_blanks, answer_question, or translate_phrase
- Each question: 4 options (A, B, C, D)
- Each correct_answer field: "B) Option text here" (letter + option)
- Brief explanation field (one sentence max)

FORMATTING RULES:
- NO ** symbols anywhere
- NO markdown (* _ ` etc.)
- Plain text only
- Keep total under 600 words
- NO introductory text in section bodies
- NO "Here are some..." or "These are..." - just list the content

Focus on real-world communication. Be EXTREMELY CONCISE.
USER;

        return $systemPrompt . "\n\n" . $userPrompt;
    }

    /**
     * Call Gemini API with retry logic for HTTP errors
     */
    private function callGeminiAPI(string $prompt, int $attempt, int|string|null $userIdOrKey = null, array $options = []): string
    {
        if (is_string($userIdOrKey)) {
            $apiKey = $userIdOrKey;
        } else {
            $apiKey = $this->resolveApiKey(is_int($userIdOrKey) ? $userIdOrKey : null);
        }

        $httpAttempt = 0;
        $maxHttpRetries = 3;

        $modelLower = strtolower($this->model);
        $supportsThinking = (
            strpos($modelLower, '3.5-flash') !== false ||
            strpos($modelLower, '2.5-flash') !== false ||
            strpos($modelLower, 'thinking') !== false
        );

        $generationConfig = [
            'temperature' => 0.4,  // Lower for more deterministic output
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => $options['maxOutputTokens'] ?? 8192,
        ];

        if ($supportsThinking) {
            $generationConfig['thinkingConfig'] = [
                'thinkingLevel' => $options['thinkingLevel'] ?? 'low',
            ];
        }
        
        while ($httpAttempt < $maxHttpRetries) {
            try {
                $response = $this->httpClient->post(
                    "https://generativelanguage.googleapis.com/v1beta/{$this->model}:generateContent?key={$apiKey}",
                    [
                        'json' => [
                            'contents' => [
                                [
                                    'parts' => [
                                        ['text' => $prompt]
                                    ]
                                ]
                            ],
                            'generationConfig' => $generationConfig
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
                $maskedMessage = str_replace([$this->platformApiKey, $apiKey], '***REDACTED***', $e->getMessage());
                
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
     * Check if content is off-topic (not educational material for the requested topic)
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
        
        // Must have multiple_choice exercises (at least 1, ideally 5)
        $exercises = $lessonData['exercises'] ?? [];
        
        if (!empty($exercises['multiple_choice']) && is_array($exercises['multiple_choice'])) {
            return count($exercises['multiple_choice']) > 0;
        }
        
        return false;
    }

    /**
     * Generate a progressive day-by-day curriculum for a multi-day learning plan.
     *
     * @return array{days: list<array{day: int, title: string, focus: string, phase: string, objectives: string[]}>}
     */
    public function generatePlanCurriculum(
        string $topic,
        string $language,
        string $skillLevel,
        int $durationDays,
        string $goalNotes,
        ?int $userId = null
    ): array {
        $skillGuidance = match ($skillLevel) {
            'beginner' => 'Assume little prior knowledge. Days 1-3 are gentle foundations; ramp slowly.',
            'intermediate' => 'Balanced progression from foundations to practical fluency.',
            'expert' => 'Skip basics. Focus on nuance, idioms, and professional usage. Faster ramp.',
            'refresher' => 'User knows the topic but wants a structured memory refresh. Prioritize recall, contrasts, and common mistakes — avoid repeating elementary content.',
            default => 'Balanced progression.',
        };

        $prompt = <<<PROMPT
You are an expert instructional designer. Write all plan text in {$language}.

Create a {$durationDays}-day progressive learning plan for: "{$topic}"

Learner profile: {$skillLevel}
{$skillGuidance}

Additional context from the learner:
{$goalNotes}

RULES:
- Exactly {$durationDays} days, numbered 1 through {$durationDays}
- Progressive difficulty: early days = foundation, middle = building/practice, final days = mastery/application
- NEVER mix advanced content on day 1 with beginner drills on the last day unless skill is "refresher"
- Each day must build on previous days (name prior concepts in focus when relevant from day 3+)
- phase must be one of: foundation, building, practice, mastery

Return ONLY strict JSON (no markdown fences):
{
  "days": [
    {
      "day": 1,
      "title": "short title for the day",
      "focus": "what this day covers in 1-2 sentences",
      "phase": "foundation",
      "objectives": ["objective 1", "objective 2"]
    }
  ]
}
PROMPT;

        $raw = $this->callGeminiAPI($prompt, 1, $userId, [
            'maxOutputTokens' => 16384,
            'thinkingLevel' => 'low'
        ]);
        $data = $this->sanitizeAndDecodeJson($raw);
        $this->validatePlanCurriculum($data, $durationDays);

        return $data;
    }

    /**
     * Generate a single day's lesson aligned with the plan curriculum.
     */
    public function generatePlanDayLesson(
        string $topic,
        string $language,
        string $skillLevel,
        int $dayNumber,
        int $totalDays,
        array $dayOutline,
        array $previousDayTitles,
        ?int $userId = null
    ): array {
        $title = $dayOutline['title'] ?? "Day {$dayNumber}";
        $focus = $dayOutline['focus'] ?? $topic;
        $phase = $dayOutline['phase'] ?? 'foundation';
        $objectives = $dayOutline['objectives'] ?? [];
        $objectivesText = !empty($objectives) ? implode('; ', $objectives) : $focus;
        $prevText = !empty($previousDayTitles)
            ? 'Previous days covered: ' . implode(' → ', $previousDayTitles) . '.'
            : 'This is day 1 of the plan.';

        $skillNote = match ($skillLevel) {
            'beginner' => 'Use simple explanations and basic vocabulary.',
            'expert' => 'Use advanced vocabulary and nuanced examples.',
            'refresher' => 'Focus on refreshing memory — highlight contrasts and common slip-ups.',
            default => 'Use clear, practical language.',
        };

        $schemaJson = json_encode(LessonSchema::getSchema());

        $prompt = <<<PROMPT
NON-NEGOTIABLE: Return ONLY strict JSON per schema. NO markdown. ALL content in {$language}.

You are creating DAY {$dayNumber} of {$totalDays} in a structured course about "{$topic}".
Phase: {$phase}. Skill level: {$skillLevel}. {$skillNote}
{$prevText}

Today's focus: {$focus}
Today's objectives: {$objectivesText}
Day title to use in lesson context: {$title}

Stay on today's focus only — do not jump ahead to later-day topics.
Match difficulty to phase "{$phase}" and day {$dayNumber}/{$totalDays}.

LESSON STRUCTURE (same as standard TeachMe lessons):
- Vocabulary section (5-6 terms)
- Useful phrases (4-5)
- EXACTLY 5 multiple_choice exercises only
- NO markdown symbols
- Under 600 words total

JSON Schema:
{$schemaJson}

Set "topic" to "{$topic}" and "language" to "{$language}".
Set "title" to "{$title}" (you may add a short subtitle after a colon if helpful).
PROMPT;

        $raw = $this->callGeminiAPI($prompt, 1, $userId, [
            'maxOutputTokens' => 8192,
            'thinkingLevel' => 'low'
        ]);
        $lessonData = $this->sanitizeAndDecodeJson($raw);
        $this->validateAgainstSchema($lessonData);

        if (!$this->hasMinimalContent($lessonData)) {
            throw new GeminiSchemaViolationException(
                'Generated plan day lesson has insufficient content',
                ['exercises empty']
            );
        }

        return $lessonData;
    }

    private function resolveApiKey(?int $userId): string
    {
        if ($userId !== null && $this->tiers->userHasByokKey($userId)) {
            $stmt = $this->db->prepare(
                'SELECT gemini_api_key_encrypted FROM users WHERE id = ?'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['gemini_api_key_encrypted'])) {
                return $this->encryption->decrypt($row['gemini_api_key_encrypted']);
            }
        }

        return $this->platformApiKey;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validatePlanCurriculum(array $data, int $expectedDays): void
    {
        if (!isset($data['days']) || !is_array($data['days']) || count($data['days']) !== $expectedDays) {
            throw new GeminiSchemaViolationException(
                "Curriculum must contain exactly {$expectedDays} days",
                ['days' => 'invalid count']
            );
        }

        $validPhases = ['foundation', 'building', 'practice', 'mastery'];
        $seen = [];

        foreach ($data['days'] as $idx => $day) {
            if (!is_array($day)) {
                throw new GeminiSchemaViolationException('Invalid day entry', [(string) $idx => 'not array']);
            }
            foreach (['day', 'title', 'focus', 'phase'] as $field) {
                if (empty($day[$field])) {
                    throw new GeminiSchemaViolationException("Day missing {$field}", [(string) $idx => $field]);
                }
            }
            $dayNum = (int) $day['day'];
            if ($dayNum < 1 || $dayNum > $expectedDays || isset($seen[$dayNum])) {
                throw new GeminiSchemaViolationException('Invalid day number', [(string) $dayNum => 'duplicate or out of range']);
            }
            $seen[$dayNum] = true;
            if (!in_array($day['phase'], $validPhases, true)) {
                throw new GeminiSchemaViolationException('Invalid phase', [(string) $dayNum => $day['phase']]);
            }
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
