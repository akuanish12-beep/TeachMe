<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Helpers\JsonResponse;
use Predis\Client as RedisClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class RateLimitMiddleware implements Middleware
{
    private RedisClient $redis;
    private array $limits;

    public function __construct()
    {
        $this->redis = new RedisClient([
            'scheme' => 'tcp',
            'host'   => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port'   => (int) ($_ENV['REDIS_PORT'] ?? 6379),
        ]);

        // Define rate limits: [requests, window_seconds, by_user]
        $this->limits = [
            '/auth/signup' => [3, 3600, false],    // 3 per hour by IP
            '/auth/login' => [5, 900, false],       // 5 per 15 minutes by IP
            '/lessons/generate' => [5, 3600, true], // 5 per hour by user
        ];
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        // Only rate limit specific routes
        if ($method === 'POST') {
            foreach ($this->limits as $route => $config) {
                if (str_ends_with($path, $route)) {
                    [$limit, $window, $byUser] = $config;
                    
                    // Determine key based on route configuration
                    if ($byUser) {
                        $userId = $request->getAttribute('user_id');
                        if (!$userId) {
                            // If user_id not available (shouldn't happen on protected routes), skip rate limiting
                            return $handler->handle($request);
                        }
                        $key = "ratelimit:user:{$userId}:{$route}";
                    } else {
                        $ip = $this->getClientIp($request);
                        $key = "ratelimit:ip:{$ip}:{$route}";
                    }

                    // Check rate limit
                    $current = (int) $this->redis->get($key);
                    
                    if ($current >= $limit) {
                        $ttl = $this->redis->ttl($key);
                        
                        $response = new \Slim\Psr7\Response();
                        $response->getBody()->write(json_encode([
                            'error' => 'Too Many Requests',
                            'message' => 'Rate limit exceeded. Please try again later.',
                            'limit' => $limit,
                            'window' => $window,
                            'retry_after' => $ttl > 0 ? $ttl : $window
                        ]));
                        
                        return $response
                            ->withHeader('Content-Type', 'application/json')
                            ->withHeader('Retry-After', (string) ($ttl > 0 ? $ttl : $window))
                            ->withHeader('X-RateLimit-Limit', (string) $limit)
                            ->withHeader('X-RateLimit-Remaining', '0')
                            ->withHeader('X-RateLimit-Reset', (string) (time() + ($ttl > 0 ? $ttl : $window)))
                            ->withStatus(429);
                    }

                    // Increment counter
                    $this->redis->incr($key);
                    if ($current === 0) {
                        $this->redis->expire($key, $window);
                    }

                    $remaining = max(0, $limit - $current - 1);
                    
                    // Continue with request and add rate limit headers
                    $response = $handler->handle($request);
                    return $response
                        ->withHeader('X-RateLimit-Limit', (string) $limit)
                        ->withHeader('X-RateLimit-Remaining', (string) $remaining);
                }
            }
        }

        // No rate limiting for this route
        return $handler->handle($request);
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        
        // Check for forwarded IP (if behind proxy)
        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $serverParams['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        
        if (!empty($serverParams['HTTP_X_REAL_IP'])) {
            return $serverParams['HTTP_X_REAL_IP'];
        }
        
        return $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

