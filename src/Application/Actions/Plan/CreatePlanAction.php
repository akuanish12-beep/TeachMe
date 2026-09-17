<?php

declare(strict_types=1);

namespace App\Application\Actions\Plan;

use App\Application\Helpers\JsonResponse;
use App\Application\Helpers\Validator;
use App\Services\LearningPlanService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CreatePlanAction
{
    private LearningPlanService $plans;

    public function __construct(LearningPlanService $plans)
    {
        $this->plans = $plans;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $data = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required($data, ['topic', 'duration_days', 'skill_level'])
            ->maxLength($data, 'topic', 255);

        if ($validator->fails()) {
            return JsonResponse::validationError($response, $validator->errors());
        }

        $duration = (int) $data['duration_days'];
        if (!in_array($duration, [7, 15, 30], true)) {
            return JsonResponse::error($response, 'duration_days must be 7, 15, or 30', 422, 'VALIDATION_ERROR');
        }

        try {
            $plan = $this->plans->createPlan($userId, [
                'topic' => $data['topic'],
                'language' => $data['language'] ?? 'English',
                'duration_days' => $duration,
                'skill_level' => $data['skill_level'],
                'goal_notes' => $data['goal_notes'] ?? '',
            ]);

            return JsonResponse::success($response, $plan, 201);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($response, $e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            if ($code === 404) {
                return JsonResponse::error($response, $e->getMessage(), 404, 'NOT_FOUND');
            }
            if ($code === 402) {
                $response->getBody()->write(json_encode([
                    'error' => 'payment_required',
                    'message' => $e->getMessage(),
                    'upgrade' => ['price' => '$9/month', 'cta' => 'Upgrade to Pro'],
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(402);
            }
            return JsonResponse::error($response, $e->getMessage(), $code >= 400 && $code < 600 ? $code : 400);
        } catch (\Throwable $e) {
            return JsonResponse::error(
                $response,
                'We could not build your learning plan right now. Please try again in a few minutes.',
                502,
                'GENERATION_FAILED'
            );
        }
    }
}
