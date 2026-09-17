<?php

declare(strict_types=1);

namespace App\Services;

use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Checkout\Session;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Exception\ApiErrorException;
use PDO;

class StripeService
{
    private PDO $db;
    private SubscriptionTierService $tiers;
    private string $apiKey;
    private string $priceId;

    public function __construct(PDO $db, SubscriptionTierService $tiers)
    {
        $this->db = $db;
        $this->tiers = $tiers;
        $this->apiKey = env('STRIPE_REST_KEY', '') ?: env('STRIPE_SECRET_KEY', '');
        $this->priceId = env('STRIPE_PRICE_ID_PRO_MONTHLY', '');

        if (empty($this->apiKey)) {
            throw new \RuntimeException('STRIPE_REST_KEY (or STRIPE_SECRET_KEY) not configured');
        }

        if (empty($this->priceId)) {
            throw new \RuntimeException('STRIPE_PRICE_ID_PRO_MONTHLY not configured');
        }

        Stripe::setApiKey($this->apiKey);
    }

    /**
     * Create Stripe Checkout Session for subscription
     *
     * @param int $userId
     * @param string $email
     * @param string|null $priceId Optional override for price ID
     * @return string Checkout session URL
     * @throws ApiErrorException
     */
    public function createCheckoutSession(
        int $userId,
        string $email,
        ?string $priceId = null,
        ?string $planTier = null
    ): string {
        if ($planTier !== null) {
            $resolved = $this->tiers->priceIdForTier($planTier);
            if ($resolved === '') {
                throw new \RuntimeException("Stripe price not configured for plan tier: {$planTier}");
            }
            $priceId = $resolved;
            $planTier = $this->tiers->normalizeTier($planTier);
        } else {
            $planTier = $this->tiers->tierFromStripePriceId($priceId ?? $this->priceId);
        }

        $priceId = $priceId ?? $this->priceId;

        // Check if user already has a Stripe customer ID
        $stmt = $this->db->prepare(
            "SELECT stripe_customer_id FROM subscriptions WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

        $customerId = null;
        if ($subscription && !empty($subscription['stripe_customer_id'])) {
            $customerId = $subscription['stripe_customer_id'];
        } else {
            // Create new Stripe customer
            $customer = Customer::create([
                'email' => $email,
                'metadata' => [
                    'user_id' => (string) $userId,
                ],
            ]);
            $customerId = $customer->id;

            // Update subscriptions table with customer ID
            $stmt = $this->db->prepare(
                "UPDATE subscriptions SET stripe_customer_id = ? WHERE user_id = ?"
            );
            $stmt->execute([$customerId, $userId]);
        }

        // Create Checkout Session
        $frontendUrl = env('FRONTEND_ORIGIN', 'http://localhost:5173');
        
        $session = Session::create([
            'customer' => $customerId,
            'mode' => 'subscription',
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $frontendUrl . '/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontendUrl . '/pricing?canceled=true',
            'metadata' => [
                'user_id' => (string) $userId,
                'plan_tier' => $planTier,
            ],
            'subscription_data' => [
                'metadata' => [
                    'plan_tier' => $planTier,
                ],
            ],
        ]);

        return $session->url;
    }

    /**
     * Verify a completed Checkout Session and activate the user's subscription.
     * Used on the success page so activation does not depend solely on webhooks.
     *
     * @throws ApiErrorException
     * @throws \RuntimeException
     */
    public function verifyCheckoutSession(int $userId, string $sessionId): bool
    {
        $session = Session::retrieve($sessionId, [
            'expand' => ['subscription', 'customer', 'line_items'],
        ]);

        if (($session->metadata['user_id'] ?? '') !== (string) $userId) {
            throw new \RuntimeException('Checkout session does not belong to this user');
        }

        if ($session->status !== 'complete' || $session->payment_status !== 'paid') {
            return false;
        }

        $subscriptionId = is_object($session->subscription)
            ? $session->subscription->id
            : $session->subscription;
        $customerId = is_object($session->customer)
            ? $session->customer->id
            : $session->customer;

        if (!$subscriptionId || !$customerId) {
            return false;
        }

        $planTier = $this->resolveTierFromCheckoutSession($session);
        $this->activateSubscriptionForUser($userId, $customerId, $subscriptionId, $planTier);

        return true;
    }

    /**
     * Activate subscription for a user (by user_id).
     */
    public function activateSubscriptionForUser(
        int $userId,
        string $customerId,
        string $subscriptionId,
        ?string $planTier = null
    ): void {
        if ($planTier === null) {
            $planTier = $this->resolveTierFromStripeSubscriptionId($subscriptionId);
        } else {
            $planTier = $this->tiers->normalizeTier($planTier);
        }

        $stmt = $this->db->prepare(
            "UPDATE subscriptions
             SET stripe_customer_id = ?, stripe_subscription_id = ?, status = 'active',
                 plan_tier = ?, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = ?"
        );
        $stmt->execute([$customerId, $subscriptionId, $planTier, $userId]);
    }

    public function resolveTierFromStripeSubscriptionId(string $subscriptionId): string
    {
        try {
            $stripeSub = \Stripe\Subscription::retrieve($subscriptionId, [
                'expand' => ['items.data.price'],
            ]);
            $priceId = $stripeSub->items->data[0]->price->id ?? '';

            if ($priceId !== '') {
                return $this->tiers->tierFromStripePriceId($priceId);
            }

            $metaTier = $stripeSub->metadata['plan_tier'] ?? null;
            if (is_string($metaTier) && $metaTier !== '') {
                return $this->tiers->normalizeTier($metaTier);
            }
        } catch (ApiErrorException $e) {
            // fall through to default
        }

        return SubscriptionTierService::TIER_PRO;
    }

    /**
     * @param \Stripe\Checkout\Session|object $session
     */
    public function resolveTierFromCheckoutSession($session): string
    {
        $metaTier = $session->metadata['plan_tier'] ?? null;
        if (is_string($metaTier) && $metaTier !== '') {
            return $this->tiers->normalizeTier($metaTier);
        }

        if (!empty($session->line_items->data[0]->price->id)) {
            return $this->tiers->tierFromStripePriceId($session->line_items->data[0]->price->id);
        }

        $subscriptionId = is_object($session->subscription)
            ? $session->subscription->id
            : $session->subscription;

        if ($subscriptionId) {
            return $this->resolveTierFromStripeSubscriptionId($subscriptionId);
        }

        return SubscriptionTierService::TIER_PRO;
    }

    public function activateSubscriptionForCustomer(
        string $customerId,
        string $subscriptionId,
        ?string $planTier = null
    ): void {
        if ($planTier === null) {
            $planTier = $this->resolveTierFromStripeSubscriptionId($subscriptionId);
        }

        $stmt = $this->db->prepare(
            "UPDATE subscriptions
             SET stripe_subscription_id = ?, status = 'active', plan_tier = ?, updated_at = CURRENT_TIMESTAMP
             WHERE stripe_customer_id = ?"
        );
        $stmt->execute([$subscriptionId, $planTier, $customerId]);
    }

    /**
     * Get Stripe customer ID for a user
     *
     * @param int $userId
     * @return string|null
     */
    public function getCustomerId(int $userId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT stripe_customer_id FROM subscriptions WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

        return $subscription['stripe_customer_id'] ?? null;
    }

    /**
     * Update subscription status in database
     *
     * @param string $customerId
     * @param string $subscriptionId
     * @param string $status
     * @return void
     */
    public function updateSubscriptionStatus(string $customerId, string $subscriptionId, string $status): void
    {
        $planTier = null;
        if ($status === 'active') {
            $planTier = $this->resolveTierFromStripeSubscriptionId($subscriptionId);
        }

        if ($planTier !== null) {
            $stmt = $this->db->prepare(
                "UPDATE subscriptions
                 SET stripe_subscription_id = ?, status = ?, plan_tier = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE stripe_customer_id = ?"
            );
            $stmt->execute([$subscriptionId, $status, $planTier, $customerId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE subscriptions
                 SET stripe_subscription_id = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE stripe_customer_id = ?"
            );
            $stmt->execute([$subscriptionId, $status, $customerId]);
        }
    }

    /**
     * Mark subscription as canceled
     *
     * @param string $subscriptionId
     * @return void
     */
    public function cancelSubscription(string $subscriptionId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE subscriptions 
             SET status = 'canceled', updated_at = CURRENT_TIMESTAMP 
             WHERE stripe_subscription_id = ?"
        );
        $stmt->execute([$subscriptionId]);
    }

    /**
     * @return array{status: string, stripe_customer_id: ?string, stripe_subscription_id: ?string, updated_at: ?string}|null
     */
    public function getSubscriptionRow(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT status, plan_tier, stripe_customer_id, stripe_subscription_id, updated_at
             FROM subscriptions WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Subscription summary for the account UI.
     *
     * @return array<string, mixed>
     */
    public function getSubscriptionSummary(int $userId): array
    {
        $row = $this->getSubscriptionRow($userId);
        $status = $row['status'] ?? 'none';
        $customerId = $row['stripe_customer_id'] ?? null;
        $subscriptionId = $row['stripe_subscription_id'] ?? null;
        $planTier = ($status === 'active')
            ? $this->tiers->normalizeTier($row['plan_tier'] ?? SubscriptionTierService::TIER_PRO)
            : null;

        $summary = [
            'status' => $status,
            'plan' => $planTier ?? 'free',
            'planTier' => $planTier,
            'tierLabel' => $this->tiers->getTierLabel($planTier),
            'priceLabel' => $this->tiers->getPriceLabel($planTier),
            'maxConcurrentPlans' => $this->tiers->getMaxConcurrentPlans($planTier),
            'hasSubscription' => $status !== 'none' || !empty($subscriptionId),
            'canManage' => !empty($customerId),
            'canManageByok' => $this->tiers->canManageByok($userId),
            'hasCustomGeminiKey' => $this->tiers->userHasByokKey($userId),
            'updatedAt' => $row['updated_at'] ?? null,
            'cancelAtPeriodEnd' => false,
            'currentPeriodEnd' => null,
        ];

        if ($customerId && $subscriptionId) {
            try {
                $stripeSub = \Stripe\Subscription::retrieve($subscriptionId);
                $summary['cancelAtPeriodEnd'] = (bool) ($stripeSub->cancel_at_period_end ?? false);
                if (!empty($stripeSub->current_period_end)) {
                    $summary['currentPeriodEnd'] = date('c', (int) $stripeSub->current_period_end);
                }
            } catch (ApiErrorException $e) {
                // Stripe lookup optional; DB status remains source of truth
            }
        }

        return $summary;
    }

    /**
     * Stripe Customer Portal — cancel, update payment method, invoices, etc.
     */
    public function createBillingPortalSession(int $userId): string
    {
        $customerId = $this->getCustomerId($userId);

        if (empty($customerId)) {
            throw new \RuntimeException('No billing account found. Subscribe to Pro first.', 400);
        }

        $frontendUrl = rtrim(env('FRONTEND_ORIGIN', 'https://teachme.mom'), '/');

        $session = BillingPortalSession::create([
            'customer' => $customerId,
            'return_url' => $frontendUrl . '/subscription?returned=1',
        ]);

        return $session->url;
    }
}

