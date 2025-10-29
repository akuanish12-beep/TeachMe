<?php

declare(strict_types=1);

namespace App\Application\Actions;

use App\Application\Helpers\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TestPostAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody();
        
        return JsonResponse::success($response, [
            'user_id' => $userId,
            'received_data' => $data,
            'message' => 'POST Action Test Successful'
        ]);
    }
}

