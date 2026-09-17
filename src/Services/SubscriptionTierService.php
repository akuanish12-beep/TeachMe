<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class SubscriptionTierService
{
    public const TIER_PRO = 'pro';
    public const TIER_PRO_PLUS = 'pro_plus';
    public const TIER_ULTRA = 'ultra';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function hasPaidAccess(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT status FROM subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row && $row['status'] === 'active';
    }

    /**
     * Effective tier when user has paid access; null for free accounts.
     */
    public function getEffectiveTier(int $userId): ?string
    {
        if (!$this->hasPaidAccess($userId)) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT plan_tier FROM subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $tier = $row['plan_tier'] ?? self::TIER_PRO;

        return $this->normalizeTier($tier);
    }

    public function getMaxConcurrentPlans(?string $tier): int
    {
        return match ($tier) {
            self::TIER_PRO_PLUS => 2,
            self::TIER_ULTRA => 4,
            self::TIER_PRO => 1,
            default => 1,
        };
    }

    public function getMaxConcurrentPlansForUser(int $userId): int
    {
        return $this->getMaxConcurrentPlans($this->getEffectiveTier($userId));
    }

    public function getTierLabel(?string $tier): string
    {
        return match ($tier) {
            self::TIER_PRO_PLUS => 'Pro+',
            self::TIER_ULTRA => 'Ultra',
            self::TIER_PRO => 'Pro',
            default => 'Free',
        };
    }

    public function getPriceLabel(?string $tier): string
    {
        return match ($tier) {
            self::TIER_PRO_PLUS => '$12/month',
            self::TIER_ULTRA => '$14/month',
            self::TIER_PRO => '$9/month',
            default => 'Free',
        };
    }

    /**
     * @return list<string>
     */
    public static function allowedTiers(): array
    {
        return [self::TIER_PRO, self::TIER_PRO_PLUS, self::TIER_ULTRA];
    }

    public function normalizeTier(string $tier): string
    {
        $tier = strtolower(trim($tier));

        if (!in_array($tier, self::allowedTiers(), true)) {
            return self::TIER_PRO;
        }

        return $tier;
    }

    public function tierFromStripePriceId(string $priceId): string
    {
        $proPlus = env('STRIPE_PRICE_ID_PRO_PLUS_MONTHLY', '');
        $ultra = env('STRIPE_PRICE_ID_ULTRA_MONTHLY', '');

        if ($ultra !== '' && $priceId === $ultra) {
            return self::TIER_ULTRA;
        }
        if ($proPlus !== '' && $priceId === $proPlus) {
            return self::TIER_PRO_PLUS;
        }

        return self::TIER_PRO;
    }

    public function priceIdForTier(string $tier): string
    {
        $tier = $this->normalizeTier($tier);

        return match ($tier) {
            self::TIER_PRO_PLUS => env('STRIPE_PRICE_ID_PRO_PLUS_MONTHLY', '') ?: '',
            self::TIER_ULTRA => env('STRIPE_PRICE_ID_ULTRA_MONTHLY', '') ?: '',
            default => env('STRIPE_PRICE_ID_PRO_MONTHLY', ''),
        };
    }

    public function userHasByokKey(int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT gemini_api_key_encrypted FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row && !empty($row['gemini_api_key_encrypted']);
    }

    public function canManageByok(int $userId): bool
    {
        return $this->getEffectiveTier($userId) === self::TIER_ULTRA;
    }

    /**
     * @return array{status: string, planTier: string|null, maxConcurrentPlans: int, tierLabel: string}
     */
    public function getSubscriptionMeta(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT status, plan_tier FROM subscriptions WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $status = $row['status'] ?? 'none';
        $tier = ($status === 'active') ? $this->normalizeTier($row['plan_tier'] ?? self::TIER_PRO) : null;

        return [
            'status' => $status,
            'planTier' => $tier,
            'maxConcurrentPlans' => $this->getMaxConcurrentPlans($tier),
            'tierLabel' => $this->getTierLabel($tier),
        ];
    }
}
