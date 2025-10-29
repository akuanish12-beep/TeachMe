<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Helpers\JsonResponse;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class JwtMiddleware implements Middleware
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        // Allow OPTIONS requests to pass through (CORS preflight)
        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        // Extract Authorization header
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (empty($authHeader)) {
            $response = new \Slim\Psr7\Response();
            return JsonResponse::error($response, 'unauthorized', 401, 'UNAUTHORIZED');
        }

        // Extract Bearer token (case-insensitive, handle extra spaces)
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $response = new \Slim\Psr7\Response();
            return JsonResponse::error($response, 'unauthorized', 401, 'UNAUTHORIZED');
        }

        $token = trim($matches[1]);

        try {
            // Verify and decode JWT
            $jwtSecret = $_ENV['JWT_SECRET'];
            $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));
            
            // Inject user_id into request attribute
            $request = $request->withAttribute('user_id', $decoded->sub);
            $request = $request->withAttribute('user_email', $decoded->email);
            
            return $handler->handle($request);
            
        } catch (\Exception $e) {
            $response = new \Slim\Psr7\Response();
            return JsonResponse::error($response, 'unauthorized', 401, 'UNAUTHORIZED');
        }
    }
}

