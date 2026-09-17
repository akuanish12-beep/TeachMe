<?php

declare(strict_types=1);

namespace App\Application\Actions\Plan;

use App\Application\Helpers\JsonResponse;
use App\Services\LearningPlanService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProcessDailyPlansAction
{
    private LearningPlanService $plans;

    public function __construct(LearningPlanService $plans)
    {
        $this->plans = $plans;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $secret = env('CRON_SECRET', '');
        $provided = $request->getHeaderLine('X-Cron-Secret');

        if ($secret === '' || !hash_equals($secret, $provided)) {
            return JsonResponse::error($response, 'Forbidden', 403, 'FORBIDDEN');
        }

        $result = $this->plans->processDailyJobs();

        return JsonResponse::success($response, $result);
    }
}
