<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;

class MetricsLoggerMiddleware implements Middleware
{
    private ?LoggerInterface $logger;
    private string $metricsLogPath;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->metricsLogPath = __DIR__ . '/../../../storage/logs/metrics.log';
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $startTime = microtime(true);
        $response = $handler->handle($request);
        $duration = round((microtime(true) - $startTime) * 1000, 2); // ms
        
        $statusCode = $response->getStatusCode();
        
        // Log 4xx and 5xx responses
        if ($statusCode >= 400) {
            $this->logMetric($request, $response, $duration);
        }
        
        return $response;
    }

    private function logMetric(Request $request, Response $response, float $duration): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'ip' => $this->getClientIp($request),
            'user_agent' => $request->getHeaderLine('User-Agent'),
        ];
        
        // Write to metrics log file
        $logLine = json_encode($logEntry) . PHP_EOL;
        file_put_contents($this->metricsLogPath, $logLine, FILE_APPEND | LOCK_EX);
        
        // Also log to main logger if available
        if ($this->logger) {
            $level = $response->getStatusCode() >= 500 ? 'error' : 'warning';
            $this->logger->log($level, "HTTP {$response->getStatusCode()}: {$request->getMethod()} {$request->getUri()->getPath()}", $logEntry);
        }
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        
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

