<?php

declare(strict_types=1);

namespace App\Application\Actions\Subscription;

use App\Application\Helpers\JsonResponse;
use App\Services\StripeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class VerifyCheckoutSessionAction
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
        $userId = $request->getAttribute('user_id');

        if (!$userId) {
            return JsonResponse::error($response, 'User authentication required', 401, 'UNAUTHORIZED');
        }

        $data = $request->getParsedBody() ?? [];
        $sessionId = $data['sessionId'] ?? $data['session_id'] ?? null;

        if (empty($sessionId) || !is_string($sessionId)) {
            return JsonResponse::error($response, 'sessionId is required', 422, 'VALIDATION_ERROR');
        }

        try {
            $activated = $this->stripeService->verifyCheckoutSession((int) $userId, $sessionId);

            $this->log('info', 'Checkout session verification', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'activated' => $activated,
            ]);

            return JsonResponse::success($response, [
                'activated' => $activated,
                'status' => $activated ? 'active' : 'pending',
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->log('error', 'Stripe API error verifying session', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return JsonResponse::error(
                $response,
                'Failed to verify checkout session',
                500,
                'STRIPE_ERROR'
            );
        } catch (\RuntimeException $e) {
            return JsonResponse::error($response, $e->getMessage(), 403, 'FORBIDDEN');
        } catch (\Exception $e) {
            $this->log('error', 'Unexpected error verifying checkout session', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return JsonResponse::error($response, 'An unexpected error occurred', 500, 'INTERNAL_ERROR');
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
