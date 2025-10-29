<?php

declare(strict_types=1);

namespace App\Services;

use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use PDO;

class StripeService
{
    private PDO $db;
    private string $secretKey;
    private string $priceId;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        $this->priceId = $_ENV['STRIPE_PRICE_ID_PRO_MONTHLY'] ?? '';

        if (empty($this->secretKey)) {
            throw new \RuntimeException('STRIPE_SECRET_KEY not configured');
        }

        if (empty($this->priceId)) {
            throw new \RuntimeException('STRIPE_PRICE_ID_PRO_MONTHLY not configured');
        }

        // Initialize Stripe with secret key
        Stripe::setApiKey($this->secretKey);
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
    public function createCheckoutSession(int $userId, string $email, ?string $priceId = null): string
    {
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
        $frontendUrl = $_ENV['FRONTEND_ORIGIN'] ?? 'http://localhost:5173';
        
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
            ],
        ]);

        return $session->url;
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
        $stmt = $this->db->prepare(
            "UPDATE subscriptions 
             SET stripe_subscription_id = ?, status = ?, updated_at = CURRENT_TIMESTAMP 
             WHERE stripe_customer_id = ?"
        );
        $stmt->execute([$subscriptionId, $status, $customerId]);
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
}

