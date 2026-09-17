<?php

declare(strict_types=1);

namespace App\Application\Actions\Support;

use App\Application\Helpers\JsonResponse;
use App\Services\SupportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ListMyTicketsAction
{
    private SupportService $support;

    public function __construct(SupportService $support)
    {
        $this->support = $support;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        return JsonResponse::success($response, [
            'tickets' => $this->support->listTicketsForUser($userId),
        ]);
    }
}
