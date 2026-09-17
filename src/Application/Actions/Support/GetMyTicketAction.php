<?php

declare(strict_types=1);

namespace App\Application\Actions\Support;

use App\Application\Helpers\JsonResponse;
use App\Services\SupportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GetMyTicketAction
{
    private SupportService $support;

    public function __construct(SupportService $support)
    {
        $this->support = $support;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        try {
            $ticket = $this->support->getTicketById(
                (int) $args['id'],
                (int) $request->getAttribute('user_id'),
                false
            );
            return JsonResponse::success($response, ['ticket' => $ticket]);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return JsonResponse::error($response, $e->getMessage(), $code >= 400 ? $code : 404);
        }
    }
}
