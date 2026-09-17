<?php

declare(strict_types=1);

namespace App\Application\Actions\Subscription;

use App\Application\Helpers\JsonResponse;
use App\Services\StripeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GetSubscriptionAction
{
    private StripeService $stripeService;

    public function __construct(StripeService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');

        return JsonResponse::success($response, [
            'subscription' => $this->stripeService->getSubscriptionSummary($userId),
        ]);
    }
}
