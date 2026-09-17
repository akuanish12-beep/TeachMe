<?php

declare(strict_types=1);

namespace App\Application\Actions\Ai;

use App\Application\Helpers\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PingAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        return JsonResponse::success($response, [
            'ok' => true,
            'model' => env('GEMINI_MODEL') ?? 'not_configured'
        ]);
    }
}

