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
    private ?RedisClient $redis = null;
    private array $limits;

    public function __construct()
    {
        $config = [
            'scheme' => 'tcp',
            'host'   => env('REDIS_HOST', '127.0.0.1'),
            'port'   => (int) env('REDIS_PORT', 6379),
        ];

        $password = env('REDIS_PASSWORD');
        if ($password !== null && $password !== '') {
            $config['password'] = $password;
        }

        $this->redis = new RedisClient($config);

        // [limit, window_seconds, by_user, only_count_success]
        $this->limits = [
            '/auth/signup/send-code' => [12, 3600, false, true],  // OTP sends; ignore validation errors
            '/auth/signup/verify' => [30, 3600, false, false],    // count all attempts (OTP brute-force)
            '/auth/login' => [30, 900, false, false],
            '/lessons/generate' => [30, 3600, true, false],
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
                    [$limit, $window, $byUser, $onlyCountSuccess] = array_pad($config, 4, false);
                    
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

                    try {
                        // Per-email cap for OTP send (stops one IP burning quota for many addresses)
                        if ($route === '/auth/signup/send-code') {
                            $emailKey = $this->signupEmailRateLimitKey($request);
                            if ($emailKey !== null) {
                                $emailLimit = 6;
                                $emailCurrent = (int) $this->redis->get($emailKey);
                                if ($emailCurrent >= $emailLimit) {
                                    return $this->rateLimitResponse($emailLimit, $window, $this->redis->ttl($emailKey));
                                }
                            }
                        }

                        $current = (int) $this->redis->get($key);

                        if ($current >= $limit) {
                            return $this->rateLimitResponse($limit, $window, $this->redis->ttl($key));
                        }

                        $response = $handler->handle($request);

                        $shouldCount = !$onlyCountSuccess || $response->getStatusCode() < 400;
                        if ($shouldCount) {
                            $this->redis->incr($key);
                            if ($current === 0) {
                                $this->redis->expire($key, $window);
                            }

                            if ($route === '/auth/signup/send-code' && $response->getStatusCode() < 400) {
                                $emailKey = $this->signupEmailRateLimitKey($request);
                                if ($emailKey !== null) {
                                    $emailCurrent = (int) $this->redis->get($emailKey);
                                    $this->redis->incr($emailKey);
                                    if ($emailCurrent === 0) {
                                        $this->redis->expire($emailKey, $window);
                                    }
                                }
                            }
                        }

                        $countAfter = (int) $this->redis->get($key);
                        $remaining = max(0, $limit - $countAfter);

                        return $response
                            ->withHeader('X-RateLimit-Limit', (string) $limit)
                            ->withHeader('X-RateLimit-Remaining', (string) $remaining);
                    } catch (\Throwable $e) {
                        error_log('RateLimitMiddleware: Redis unavailable, skipping rate limit: ' . $e->getMessage());
                        return $handler->handle($request);
                    }
                }
            }
        }

        // No rate limiting for this route
        return $handler->handle($request);
    }

    private function rateLimitResponse(int $limit, int $window, int $ttl): Response
    {
        $retryAfter = $ttl > 0 ? $ttl : $window;
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'error' => 'Too Many Requests',
            'code' => 'RATE_LIMIT',
            'message' => 'Too many attempts. Please wait a few minutes and try again.',
            'limit' => $limit,
            'window' => $window,
            'retry_after' => $retryAfter,
        ]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string) $retryAfter)
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', '0')
            ->withHeader('X-RateLimit-Reset', (string) (time() + $retryAfter))
            ->withStatus(429);
    }

    private function signupEmailRateLimitKey(Request $request): ?string
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || empty($body['email'])) {
            $raw = (string) $request->getBody();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }
        if (!is_array($body) || empty($body['email'])) {
            return null;
        }
        $email = strtolower(trim((string) $body['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return 'ratelimit:email:' . hash('sha256', $email) . ':/auth/signup/send-code';
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

