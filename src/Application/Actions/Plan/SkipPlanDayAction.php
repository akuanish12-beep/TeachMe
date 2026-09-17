<?php

declare(strict_types=1);

namespace App\Application\Actions\Plan;

use App\Application\Helpers\JsonResponse;
use App\Services\LearningPlanService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SkipPlanDayAction
{
    private LearningPlanService $plans;

    public function __construct(LearningPlanService $plans)
    {
        $this->plans = $plans;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $planId = (int) $args['id'];
        $dayNumber = (int) $args['day'];

        try {
            $plan = $this->plans->skipDay($planId, $dayNumber, $userId);
            return JsonResponse::success($response, $plan);
        } catch (\RuntimeException $e) {
            return JsonResponse::error($response, $e->getMessage(), 400);
        }
    }
}
