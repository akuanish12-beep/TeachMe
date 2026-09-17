<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class StaffMiddleware implements MiddlewareInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            $response = new \Slim\Psr7\Response();
            return JsonResponse::error($response, 'Unauthorized', 401, 'UNAUTHORIZED');
        }

        $stmt = $this->db->prepare('SELECT is_staff, full_name, email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !(int) $user['is_staff']) {
            $response = new \Slim\Psr7\Response();
            return JsonResponse::error($response, 'Staff access required', 403, 'FORBIDDEN');
        }

        $request = $request->withAttribute('staff_name', $user['full_name']);
        $request = $request->withAttribute('staff_email', $user['email']);

        return $handler->handle($request);
    }
}
