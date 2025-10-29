<?php

declare(strict_types=1);

namespace App\Application\Actions\Admin;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StatsAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        // TODO: Add admin role check
        // For now, just require authentication (JWT middleware)
        
        try {
            // Get total counts
            $stats = [
                'users' => $this->getCount('users'),
                'lessons' => $this->getCount('lessons'),
                'generations' => $this->getCount('generations'),
                'subscriptions' => $this->getSubscriptionStats(),
                'last24h' => $this->getLast24HourStats(),
            ];

            return JsonResponse::success($response, $stats);

        } catch (\Exception $e) {
            return JsonResponse::error(
                $response,
                'Failed to retrieve stats',
                500,
                'STATS_ERROR'
            );
        }
    }

    private function getCount(string $table): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM {$table}");
        return (int) $stmt->fetchColumn();
    }

    private function getSubscriptionStats(): array
    {
        $stmt = $this->db->query(
            "SELECT status, COUNT(*) as count 
             FROM subscriptions 
             GROUP BY status"
        );
        
        $stats = [
            'none' => 0,
            'active' => 0,
            'canceled' => 0,
            'past_due' => 0,
        ];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stats[$row['status']] = (int) $row['count'];
        }
        
        return $stats;
    }

    private function getLast24HourStats(): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        // New users
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM users WHERE created_at >= ?"
        );
        $stmt->execute([$since]);
        $newUsers = (int) $stmt->fetchColumn();
        
        // New lessons
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM lessons WHERE created_at >= ?"
        );
        $stmt->execute([$since]);
        $newLessons = (int) $stmt->fetchColumn();
        
        // New generations
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM generations WHERE created_at >= ?"
        );
        $stmt->execute([$since]);
        $newGenerations = (int) $stmt->fetchColumn();
        
        // New subscriptions
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM subscriptions WHERE updated_at >= ? AND status = 'active'"
        );
        $stmt->execute([$since]);
        $newSubscriptions = (int) $stmt->fetchColumn();
        
        return [
            'users' => $newUsers,
            'lessons' => $newLessons,
            'generations' => $newGenerations,
            'subscriptions' => $newSubscriptions,
        ];
    }
}

