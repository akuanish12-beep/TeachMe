<?php

declare(strict_types=1);

namespace App\Application\Actions\Staff;

use App\Application\Helpers\JsonResponse;
use App\Services\SupportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UpdateStaffTicketAction
{
    private SupportService $support;

    public function __construct(SupportService $support)
    {
        $this->support = $support;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody() ?? [];
        if (empty($data['status'])) {
            return JsonResponse::error($response, 'status is required', 422);
        }

        try {
            $ticket = $this->support->updateTicketStatus((int) $args['id'], $data['status']);
            return JsonResponse::success($response, ['ticket' => $ticket]);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($response, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return JsonResponse::error($response, $e->getMessage(), 404);
        }
    }
}
