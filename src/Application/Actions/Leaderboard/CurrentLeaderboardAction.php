<?php
declare(strict_types=1);

namespace App\Application\Actions\Leaderboard;

use App\Application\Actions\Action;
use Psr\Log\LoggerInterface;
use PDO;
use Slim\Exception\HttpBadRequestException;

class CurrentLeaderboardAction extends Action
{
    private PDO $db;

    public function __construct(LoggerInterface $logger, PDO $db)
    {
        parent::__construct($logger);
        $this->db = $db;
    }

    protected function action(): \Psr\Http\Message\ResponseInterface
    {
        $userId = $this->request->getQueryParams()['user_id'] ?? null;
        
        if (!$userId) {
            throw new HttpBadRequestException($this->request, "Missing user_id query parameter.");
        }

        $stmt = $this->db->prepare("
            SELECT c.id as cohort_id, c.league_id, l.name as league_name, l.rank_tier, c.week_start_date, c.week_end_date
            FROM leaderboard_members m
            JOIN leaderboard_cohorts c ON m.cohort_id = c.id
            JOIN leagues l ON c.league_id = l.id
            WHERE m.user_id = ?
            ORDER BY c.week_end_date DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $cohort = $stmt->fetch();

        if (!$cohort) {
            return $this->respondWithData([
                'cohort' => null,
                'leaderboard' => []
            ]);
        }

        $stmt = $this->db->prepare("
            SELECT u.id, u.full_name, m.weekly_xp
            FROM leaderboard_members m
            JOIN users u ON m.user_id = u.id
            WHERE m.cohort_id = ?
            ORDER BY m.weekly_xp DESC, u.id ASC
            LIMIT 30
        ");
        $stmt->execute([$cohort['cohort_id']]);
        $members = $stmt->fetchAll();

        $leaderboard = [];
        foreach ($members as $index => $member) {
            $leaderboard[] = [
                'rank' => $index + 1,
                'user_id' => $member['id'],
                'full_name' => $member['full_name'],
                'weekly_xp' => (int)$member['weekly_xp']
            ];
        }

        return $this->respondWithData([
            'cohort' => [
                'id' => $cohort['cohort_id'],
                'league_id' => (int)$cohort['league_id'],
                'league_name' => $cohort['league_name'],
                'rank_tier' => (int)$cohort['rank_tier'],
                'week_start_date' => $cohort['week_start_date'],
                'week_end_date' => $cohort['week_end_date']
            ],
            'leaderboard' => $leaderboard
        ]);
    }
}
