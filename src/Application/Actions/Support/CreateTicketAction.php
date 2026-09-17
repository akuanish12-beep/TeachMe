<?php

declare(strict_types=1);

namespace App\Application\Actions\Support;

use App\Application\Helpers\JsonResponse;
use App\Application\Helpers\Validator;
use App\Services\SupportService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CreateTicketAction
{
    private SupportService $support;
    private PDO $db;

    public function __construct(SupportService $support, PDO $db)
    {
        $this->support = $support;
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $validator = new Validator();
        $validator
            ->required($data, ['name', 'email', 'subject', 'message'])
            ->email($data, 'email');

        if ($validator->fails()) {
            return JsonResponse::validationError($response, $validator->errors());
        }

        $userId = $request->getAttribute('user_id');
        $name = trim($data['name']);
        $email = strtolower(trim($data['email']));

        if ($userId) {
            $stmt = $this->db->prepare('SELECT full_name, email FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $name = $user['full_name'];
                $email = $user['email'];
            }
        }

        try {
            $ticket = $this->support->createTicket(
                $userId ? (int) $userId : null,
                $name,
                $email,
                trim($data['subject']),
                trim($data['message'])
            );

            return JsonResponse::success($response, ['ticket' => $ticket], 201);
        } catch (\Throwable $e) {
            return JsonResponse::error($response, 'Failed to create ticket', 500);
        }
    }
}
