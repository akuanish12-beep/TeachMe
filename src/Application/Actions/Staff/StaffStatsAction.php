<?php

declare(strict_types=1);

namespace App\Application\Actions\Staff;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StaffStatsAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $users = (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $lessons = (int) $this->db->query('SELECT COUNT(*) FROM lessons')->fetchColumn();
        $openTickets = (int) $this->db->query(
            "SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress','waiting')"
        )->fetchColumn();

        $subs = $this->db->query(
            "SELECT status, COUNT(*) AS cnt FROM subscriptions GROUP BY status"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        return JsonResponse::success($response, [
            'users' => $users,
            'lessons' => $lessons,
            'openTickets' => $openTickets,
            'subscriptions' => $subs ?: [],
        ]);
    }
}
