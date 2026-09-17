<?php

declare(strict_types=1);

namespace App\Application\Actions\Plan;

use App\Application\Helpers\JsonResponse;
use App\Services\LearningPlanService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GetActivePlanAction
{
    private LearningPlanService $plans;

    public function __construct(LearningPlanService $plans)
    {
        $this->plans = $plans;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $plans = $this->plans->getActivePlans($userId);

        return JsonResponse::success($response, [
            'plan' => $plans[0] ?? null,
            'plans' => $plans,
        ]);
    }
}
