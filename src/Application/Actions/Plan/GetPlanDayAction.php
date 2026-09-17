<?php

declare(strict_types=1);

namespace App\Application\Actions\Plan;

use App\Application\Helpers\JsonResponse;
use App\Services\LearningPlanService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GetPlanDayAction
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
            $result = $this->plans->openDay($planId, $dayNumber, $userId);
            return JsonResponse::success($response, $result);
        } catch (\RuntimeException $e) {
            $status = $e->getCode() >= 400 ? (int) $e->getCode() : 400;
            return JsonResponse::error($response, $e->getMessage(), $status);
        }
    }
}
