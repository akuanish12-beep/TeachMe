<?php
declare(strict_types=1);

namespace App\Application\Actions\Challenge;

use App\Application\Actions\Action;
use Psr\Log\LoggerInterface;
use PDO;
use Slim\Exception\HttpBadRequestException;

class CreateChallengeAction extends Action
{
    private PDO $db;

    public function __construct(LoggerInterface $logger, PDO $db)
    {
        parent::__construct($logger);
        $this->db = $db;
    }

    protected function action(): \Psr\Http\Message\ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $playerOneId = $body['player_one_id'] ?? null;
        $playerTwoId = $body['player_two_id'] ?? null;
        $targetXpGoal = isset($body['target_xp_goal']) ? (int)$body['target_xp_goal'] : null;

        if (!$playerOneId || !$playerTwoId || !$targetXpGoal) {
            throw new HttpBadRequestException($this->request, "Missing required parameters: player_one_id, player_two_id, target_xp_goal");
        }

        $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $stmt = $this->db->prepare("
            INSERT INTO shared_challenges (id, player_one_id, player_two_id, target_xp_goal, status, expires_at)
            VALUES (?, ?, ?, ?, 'active', DATE_ADD(NOW(), INTERVAL 7 DAY))
        ");

        $stmt->execute([$id, $playerOneId, $playerTwoId, $targetXpGoal]);

        return $this->respondWithData([
            'id' => $id,
            'player_one_id' => $playerOneId,
            'player_two_id' => $playerTwoId,
            'target_xp_goal' => $targetXpGoal,
            'status' => 'active'
        ], 201);
    }
}
