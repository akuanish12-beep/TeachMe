<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Helpers\AuthHeader;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class OptionalJwtMiddleware implements Middleware
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $token = AuthHeader::bearerToken($request);

        if ($token !== null) {
            try {
                $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
                $request = $request->withAttribute('user_id', $decoded->sub);
                $request = $request->withAttribute('user_email', $decoded->email);
            } catch (\Exception $e) {
                // continue without auth
            }
        }

        return $handler->handle($request);
    }
}
