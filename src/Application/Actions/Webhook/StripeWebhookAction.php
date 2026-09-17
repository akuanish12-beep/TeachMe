<?php

declare(strict_types=1);

namespace App\Application\Actions\Webhook;

use App\Application\Helpers\JsonResponse;
use App\Services\StripeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookAction
{
    private StripeService $stripeService;
    private ?LoggerInterface $logger;
    private string $webhookSecret;

    public function __construct(StripeService $stripeService, ?LoggerInterface $logger = null)
    {
        $this->stripeService = $stripeService;
        $this->logger = $logger;
        $this->webhookSecret = env('STRIPE_WEBHOOK_SECRET') ?? '';
    }

    public function __invoke(Request $request, Response $response): Response
    {
        // Get raw body and signature
        $payload = (string) $request->getBody();
        $sigHeader = $request->getHeaderLine('Stripe-Signature');

        // Verify webhook signature (skip if webhook secret not configured for testing)
        if (!empty($this->webhookSecret)) {
            try {
                $event = Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
            } catch (SignatureVerificationException $e) {
                $this->log('warning', 'Invalid webhook signature', [
                    'error' => $e->getMessage()
                ]);

                return JsonResponse::error(
                    $response,
                    'Invalid signature',
                    400,
                    'INVALID_SIGNATURE'
                );
            } catch (\Exception $e) {
                $this->log('error', 'Webhook verification error', [
                    'error' => $e->getMessage()
                ]);

                return JsonResponse::error(
                    $response,
                    'Webhook error',
                    400,
                    'WEBHOOK_ERROR'
                );
            }
        } else {
            // For testing without signature verification
            $event = json_decode($payload, true);
            $this->log('warning', 'Webhook signature verification skipped (no secret configured)');
        }

        // Handle the event
        try {
            $eventType = is_array($event) ? ($event['type'] ?? '') : $event->type;
            $eventData = is_array($event) ? ($event['data']['object'] ?? []) : $event->data->object;

            $this->log('info', "Stripe webhook received: {$eventType}", [
                'event_id' => is_array($event) ? ($event['id'] ?? 'unknown') : $event->id
            ]);

            switch ($eventType) {
                case 'checkout.session.completed':
                    $this->handleCheckoutSessionCompleted($eventData);
                    break;

                case 'invoice.payment_succeeded':
                    $this->handleInvoicePaymentSucceeded($eventData);
                    break;

                case 'customer.subscription.deleted':
                    $this->handleSubscriptionDeleted($eventData);
                    break;

                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdated($eventData);
                    break;

                default:
                    $this->log('info', "Unhandled webhook event type: {$eventType}");
            }

            return JsonResponse::success($response, ['received' => true]);

        } catch (\Exception $e) {
            $this->log('error', 'Error processing webhook', [
                'event_type' => $eventType ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Still return 200 to acknowledge receipt
            return JsonResponse::success($response, ['received' => true]);
        }
    }

    /**
     * Handle checkout.session.completed event
     */
    private function handleCheckoutSessionCompleted($session): void
    {
        $customerId = is_array($session) ? ($session['customer'] ?? null) : $session->customer;
        $subscriptionId = is_array($session) ? ($session['subscription'] ?? null) : $session->subscription;

        if (!$customerId || !$subscriptionId) {
            $this->log('warning', 'Missing customer or subscription ID in checkout session');
            return;
        }

        $this->log('info', 'Processing checkout.session.completed', [
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId
        ]);

        $this->stripeService->activateSubscriptionForCustomer($customerId, $subscriptionId);
    }

    /**
     * Handle invoice.payment_succeeded event
     */
    private function handleInvoicePaymentSucceeded($invoice): void
    {
        $customerId = is_array($invoice) ? ($invoice['customer'] ?? null) : $invoice->customer;
        $subscriptionId = is_array($invoice) ? ($invoice['subscription'] ?? null) : $invoice->subscription;

        if (!$customerId || !$subscriptionId) {
            $this->log('warning', 'Missing customer or subscription ID in invoice');
            return;
        }

        $this->log('info', 'Processing invoice.payment_succeeded', [
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId
        ]);

        // Mark subscription as active (renewal payment succeeded)
        $this->stripeService->updateSubscriptionStatus($customerId, $subscriptionId, 'active');
    }

    /**
     * Handle customer.subscription.deleted event
     */
    private function handleSubscriptionDeleted($subscription): void
    {
        $subscriptionId = is_array($subscription) ? ($subscription['id'] ?? null) : $subscription->id;

        if (!$subscriptionId) {
            $this->log('warning', 'Missing subscription ID in subscription deleted event');
            return;
        }

        $this->log('info', 'Processing customer.subscription.deleted', [
            'subscription_id' => $subscriptionId
        ]);

        // Mark subscription as canceled
        $this->stripeService->cancelSubscription($subscriptionId);
    }

    /**
     * Handle customer.subscription.updated event
     */
    private function handleSubscriptionUpdated($subscription): void
    {
        $subscriptionId = is_array($subscription) ? ($subscription['id'] ?? null) : $subscription->id;
        $status = is_array($subscription) ? ($subscription['status'] ?? null) : $subscription->status;
        $customerId = is_array($subscription) ? ($subscription['customer'] ?? null) : $subscription->customer;

        if (!$subscriptionId || !$status || !$customerId) {
            $this->log('warning', 'Missing required fields in subscription updated event');
            return;
        }

        $this->log('info', 'Processing customer.subscription.updated', [
            'subscription_id' => $subscriptionId,
            'status' => $status
        ]);

        // Map Stripe status to our internal status
        $internalStatus = match ($status) {
            'active', 'trialing' => 'active',
            'canceled', 'unpaid' => 'canceled',
            'past_due', 'incomplete' => 'past_due',
            default => 'none'
        };

        $this->stripeService->updateSubscriptionStatus($customerId, $subscriptionId, $internalStatus);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}

