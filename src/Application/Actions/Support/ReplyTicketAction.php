<?php

declare(strict_types=1);

namespace App\Application\Actions\Support;

use App\Application\Helpers\JsonResponse;
use App\Application\Helpers\Validator;
use App\Services\SupportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReplyTicketAction
{
    private SupportService $support;

    public function __construct(SupportService $support)
    {
        $this->support = $support;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody() ?? [];
        $validator = new Validator();
        $validator->required($data, ['message']);

        if ($validator->fails()) {
            return JsonResponse::validationError($response, $validator->errors());
        }

        try {
            $ticket = $this->support->addUserReply(
                (int) $args['id'],
                (int) $request->getAttribute('user_id'),
                trim($data['message'])
            );
            return JsonResponse::success($response, ['ticket' => $ticket]);
        } catch (\RuntimeException $e) {
            return JsonResponse::error($response, $e->getMessage(), 400);
        }
    }
}
