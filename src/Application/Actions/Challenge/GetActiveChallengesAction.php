<?php
declare(strict_types=1);

namespace App\Application\Actions\Challenge;

use App\Application\Actions\Action;
use Psr\Log\LoggerInterface;
use PDO;
use Slim\Exception\HttpBadRequestException;

class GetActiveChallengesAction extends Action
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
            SELECT * FROM shared_challenges
            WHERE status = 'active' AND (player_one_id = ? OR player_two_id = ?)
        ");
        $stmt->execute([$userId, $userId]);
        $challenges = $stmt->fetchAll();

        return $this->respondWithData($challenges);
    }
}
