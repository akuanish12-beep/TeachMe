<?php

declare(strict_types=1);

namespace App\Schemas;

class LessonSchema
{
    /**
     * Get the expected lesson JSON schema structure
     * Enhanced for contextual, structured lessons with vocabulary, grammar, phrases, and dialogue
     */
    public static function getSchema(): array
    {
        return [
            'topic' => 'string',
            'language' => 'string',
            'title' => 'string',
            'sections' => [
                [
                    'heading' => 'string',
                    'body' => 'string'
                ]
            ],
            'exercises' => [
                'fill_in_the_blanks' => [
                    [
                        'prompt' => 'string',
                        'text_with_gaps' => 'string',
                        'answers' => ['string']
                    ]
                ],
                'translate_phrase' => [
                    [
                        'prompt' => 'string',
                        'source' => 'string',
                        'target_hint' => 'string'
                    ]
                ],
                'answer_question' => [
                    [
                        'prompt' => 'string',
                        'question' => 'string',
                        'expected_points' => ['string']
                    ]
                ],
                'multiple_choice' => [
                    [
                        'prompt' => 'string',
                        'question' => 'string',
                        'options' => ['string', 'string', 'string', 'string'],
                        'correct_answer' => 'string',
                        'explanation' => 'string'
                    ]
                ]
            ]
        ];
    }

    /**
     * Validate lesson JSON structure
     */
    public static function validate(array $data): array
    {
        $errors = [];

        // Required top-level fields
        $requiredFields = ['topic', 'language', 'title', 'sections', 'exercises'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Validate sections
        if (isset($data['sections'])) {
            if (!is_array($data['sections']) || empty($data['sections'])) {
                $errors[] = "sections must be a non-empty array";
            } else {
                foreach ($data['sections'] as $idx => $section) {
                    if (!isset($section['heading']) || !is_string($section['heading'])) {
                        $errors[] = "sections[{$idx}].heading is required and must be a string";
                    }
                    if (!isset($section['body']) || !is_string($section['body'])) {
                        $errors[] = "sections[{$idx}].body is required and must be a string";
                    }
                }
            }
        }

        // Validate exercises
        if (isset($data['exercises'])) {
            if (!is_array($data['exercises'])) {
                $errors[] = "exercises must be an object";
            } else {
                // Validate fill_in_the_blanks
                if (isset($data['exercises']['fill_in_the_blanks'])) {
                    if (!is_array($data['exercises']['fill_in_the_blanks'])) {
                        $errors[] = "exercises.fill_in_the_blanks must be an array";
                    } else {
                        foreach ($data['exercises']['fill_in_the_blanks'] as $idx => $ex) {
                            if (!isset($ex['prompt']) || !isset($ex['text_with_gaps']) || !isset($ex['answers'])) {
                                $errors[] = "exercises.fill_in_the_blanks[{$idx}] missing required fields";
                            }
                            if (isset($ex['answers']) && !is_array($ex['answers'])) {
                                $errors[] = "exercises.fill_in_the_blanks[{$idx}].answers must be an array";
                            }
                        }
                    }
                }

                // Validate translate_phrase
                if (isset($data['exercises']['translate_phrase'])) {
                    if (!is_array($data['exercises']['translate_phrase'])) {
                        $errors[] = "exercises.translate_phrase must be an array";
                    } else {
                        foreach ($data['exercises']['translate_phrase'] as $idx => $ex) {
                            if (!isset($ex['prompt']) || !isset($ex['source']) || !isset($ex['target_hint'])) {
                                $errors[] = "exercises.translate_phrase[{$idx}] missing required fields";
                            }
                        }
                    }
                }

                // Validate answer_question
                if (isset($data['exercises']['answer_question'])) {
                    if (!is_array($data['exercises']['answer_question'])) {
                        $errors[] = "exercises.answer_question must be an array";
                    } else {
                        foreach ($data['exercises']['answer_question'] as $idx => $ex) {
                            if (!isset($ex['prompt']) || !isset($ex['question']) || !isset($ex['expected_points'])) {
                                $errors[] = "exercises.answer_question[{$idx}] missing required fields";
                            }
                            if (isset($ex['expected_points']) && !is_array($ex['expected_points'])) {
                                $errors[] = "exercises.answer_question[{$idx}].expected_points must be an array";
                            }
                        }
                    }
                }

                // Validate multiple_choice (NEW)
                if (isset($data['exercises']['multiple_choice'])) {
                    if (!is_array($data['exercises']['multiple_choice'])) {
                        $errors[] = "exercises.multiple_choice must be an array";
                    } else {
                        foreach ($data['exercises']['multiple_choice'] as $idx => $ex) {
                            if (!isset($ex['prompt']) || !isset($ex['question']) || !isset($ex['options']) || !isset($ex['correct_answer'])) {
                                $errors[] = "exercises.multiple_choice[{$idx}] missing required fields";
                            }
                            if (isset($ex['options'])) {
                                if (!is_array($ex['options']) || count($ex['options']) !== 4) {
                                    $errors[] = "exercises.multiple_choice[{$idx}].options must be an array of exactly 4 strings";
                                }
                            }
                        }
                    }
                }
            }
        }

        return $errors;
    }
}
