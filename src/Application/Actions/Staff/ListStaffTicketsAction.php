<?php

declare(strict_types=1);

namespace App\Application\Actions\Staff;

use App\Application\Helpers\JsonResponse;
use App\Services\SupportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ListStaffTicketsAction
{
    private SupportService $support;

    public function __construct(SupportService $support)
    {
        $this->support = $support;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $status = isset($params['status']) && $params['status'] !== '' ? (string) $params['status'] : null;

        return JsonResponse::success($response, [
            'tickets' => $this->support->listTicketsForStaff($status),
        ]);
    }
}
