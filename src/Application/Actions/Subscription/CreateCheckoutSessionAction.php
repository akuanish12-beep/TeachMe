<?php

declare(strict_types=1);

namespace App\Application\Actions\Subscription;

use App\Application\Helpers\JsonResponse;
use App\Services\StripeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class CreateCheckoutSessionAction
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
        $userEmail = $request->getAttribute('user_email');

        if (!$userId || !$userEmail) {
            return JsonResponse::error(
                $response,
                'User authentication required',
                401,
                'UNAUTHORIZED'
            );
        }

        try {
            // Optional: allow price ID override in request body
            $data = $request->getParsedBody();
            $priceId = $data['priceId'] ?? null;

            // Create Stripe Checkout Session
            $checkoutUrl = $this->stripeService->createCheckoutSession(
                (int) $userId,
                $userEmail,
                $priceId
            );

            $this->log('info', 'Stripe checkout session created', [
                'user_id' => $userId,
                'email' => $userEmail
            ]);

            return JsonResponse::success($response, [
                'url' => $checkoutUrl
            ]);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->log('error', 'Stripe API error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return JsonResponse::error(
                $response,
                'Failed to create checkout session. Please try again.',
                500,
                'STRIPE_ERROR'
            );

        } catch (\RuntimeException $e) {
            $this->log('error', 'Stripe configuration error', [
                'error' => $e->getMessage()
            ]);

            return JsonResponse::error(
                $response,
                'Payment system not configured',
                500,
                'CONFIG_ERROR'
            );

        } catch (\Exception $e) {
            $this->log('error', 'Unexpected error creating checkout session', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return JsonResponse::error(
                $response,
                'An unexpected error occurred',
                500,
                'INTERNAL_ERROR'
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

