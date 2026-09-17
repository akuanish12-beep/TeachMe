<?php

declare(strict_types=1);

namespace App\Application\Actions\Subscription;

use App\Application\Helpers\JsonResponse;
use App\Services\StripeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class CreateBillingPortalAction
{
    private StripeService $stripeService;
    private ?LoggerInterface $logger;

    public function __construct(StripeService $stripeService, ?LoggerInterface $logger = null)
    {
        $this->stripeService = $stripeService;
        $this->logger = $logger;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');

        try {
            $url = $this->stripeService->createBillingPortalSession($userId);

            return JsonResponse::success($response, ['url' => $url]);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return JsonResponse::error(
                $response,
                $e->getMessage(),
                $code >= 400 && $code < 600 ? $code : 400
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->log('error', 'Stripe billing portal error', ['error' => $e->getMessage()]);

            return JsonResponse::error(
                $response,
                'Unable to open billing portal. Please try again later.',
                502,
                'STRIPE_ERROR'
            );
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
