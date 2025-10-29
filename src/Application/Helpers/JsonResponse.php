<?php

declare(strict_types=1);

namespace App\Application\Helpers;

use Psr\Http\Message\ResponseInterface as Response;

class JsonResponse
{
    /**
     * Return a JSON success response
     */
    public static function success(Response $response, array $data, int $statusCode = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }

    /**
     * Return a JSON error response
     */
    public static function error(Response $response, string $message, int $statusCode = 400, ?string $code = null): Response
    {
        $error = [
            'error' => $message,
        ];
        
        if ($code) {
            $error['code'] = $code;
        }
        
        $response->getBody()->write(json_encode($error));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }

    /**
     * Return validation errors
     */
    public static function validationError(Response $response, array $errors): Response
    {
        $data = [
            'error' => 'Validation failed',
            'code' => 'VALIDATION_ERROR',
            'errors' => $errors,
        ];
        
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(422);
    }
}

