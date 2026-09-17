<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Helpers\AuthHeader;
use App\Application\Helpers\JsonResponse;
use App\Services\SubscriptionTierService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StatusAction
{
    private PDO $db;
    private SubscriptionTierService $tiers;
    private const FREE_GENERATIONS_LIMIT = 1;

    public function __construct(PDO $db, SubscriptionTierService $tiers)
    {
        $this->db = $db;
        $this->tiers = $tiers;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $token = AuthHeader::bearerToken($request);

        if ($token === null) {
            return JsonResponse::success($response, [
                'authenticated' => false,
            ]);
        }
        
        try {
            // Verify JWT
            $jwtSecret = env('JWT_SECRET') ?? '';
            $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));
            
            $userId = $decoded->sub;
            $email = $decoded->email;
            
            // Get user details
            $stmt = $this->db->prepare(
                "SELECT id, full_name, email FROM users WHERE id = ?"
            );
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                // Token valid but user not found
                return JsonResponse::success($response, [
                    'authenticated' => false
                ]);
            }
            
            $subMeta = $this->tiers->getSubscriptionMeta((int) $userId);
            $subscriptionStatus = $subMeta['status'];
            $planTier = $subMeta['planTier'];
            
            // Check generation count for canGenerate
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM generations WHERE user_id = ?"
            );
            $stmt->execute([$userId]);
            $freeGenerationsUsed = (int) $stmt->fetchColumn();
            
            // Determine canGenerate (same logic as /users/quota)
            $hasActiveSubscription = ($subscriptionStatus === 'active');
            $canGenerate = $hasActiveSubscription || ($freeGenerationsUsed < self::FREE_GENERATIONS_LIMIT);
            
            return JsonResponse::success($response, [
                'authenticated' => true,
                'user' => [
                    'id' => (int) $user['id'],
                    'fullName' => $user['full_name'],
                    'email' => $user['email']
                ],
                'subscription' => [
                    'status' => $subscriptionStatus,
                    'planTier' => $planTier,
                    'tierLabel' => $subMeta['tierLabel'],
                    'maxConcurrentPlans' => $subMeta['maxConcurrentPlans'],
                ],
                'canGenerate' => $canGenerate
            ]);
            
        } catch (\Exception $e) {
            // Invalid token
            return JsonResponse::success($response, [
                'authenticated' => false
            ]);
        }
    }
}

