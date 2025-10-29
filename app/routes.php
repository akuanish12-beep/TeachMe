<?php

declare(strict_types=1);

use App\Application\Actions\Auth\SignupAction;
use App\Application\Actions\Auth\LoginAction;
use App\Application\Actions\Auth\MeAction;
use App\Application\Actions\TestPostAction;
use App\Application\Actions\Lesson\GenerateLessonAction;
use App\Application\Actions\Lesson\ListLessonsAction;
use App\Application\Actions\Lesson\GetLessonAction;
use App\Application\Actions\User\ListUsersAction;
use App\Application\Actions\User\ViewUserAction;
use App\Application\Actions\User\QuotaAction;
use App\Application\Actions\Ai\ListModelsAction;
use App\Application\Middleware\JwtMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (App $app) {
    // OPTIONS handler for CORS preflight (handled by CorsMiddleware)
    $app->options('/{routes:.+}', function (Request $request, Response $response) {
        return $response;
    });

    // Public routes
    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write('Hello world!');
        return $response;
    });

    // Health check endpoint
    $app->get('/health', function (Request $request, Response $response) {
        $data = [
            'ok' => true,
            'ts' => date('c'), // ISO 8601 format
        ];
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // AI diagnostics endpoint (public, read-only)
    $app->get('/ai/models', ListModelsAction::class);

    // Test POST endpoint to debug JWT middleware
    $app->post('/test-jwt', function (Request $request, Response $response) {
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody();
        $response->getBody()->write(json_encode([
            'user_id' => $userId,
            'received_data' => $data,
            'message' => 'JWT Test Successful'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(JwtMiddleware::class);

    // Test with closure at /lessons/test-generate
    $app->post('/lessons/test-generate', function (Request $request, Response $response) {
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody();
        $response->getBody()->write(json_encode([
            'user_id' => $userId,
            'data' => $data,
            'message' => 'Lesson Generate Test - Closure'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(JwtMiddleware::class);

    // Test POST with Action class (not in group)
    $app->post('/test-post-action', TestPostAction::class)->add(JwtMiddleware::class);

    // Auth routes (public except /me)
    $app->group('/auth', function (Group $group) {
        $group->post('/signup', SignupAction::class);
        $group->post('/login', LoginAction::class);
        $group->get('/me', MeAction::class)->add(JwtMiddleware::class);
    });

    // Protected GET lesson routes (work fine in groups with Action classes)
    $app->group('/lessons', function (Group $group) {
        $group->get('', ListLessonsAction::class)->add(JwtMiddleware::class);
        $group->get('/{id}', GetLessonAction::class)->add(JwtMiddleware::class);
    });
    
    // POST /lessons/generate - Complete implementation with logger
    $app->post('/lessons/generate', function (Request $request, Response $response) {
        try {
            // Create dependencies directly (avoid $this->get() which breaks JWT)
            $db = new \PDO(
                sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", 
                    $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_NAME']),
                $_ENV['DB_USER'],
                $_ENV['DB_PASS'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            
            // Create logger
            $logPath = __DIR__ . '/../storage/logs/app.log';
            $logger = new \Monolog\Logger('gemini');
            $logger->pushHandler(new \Monolog\Handler\StreamHandler($logPath, \Monolog\Logger::DEBUG));
            
            // Create GeminiService with logger
            $geminiService = new \App\Services\GeminiService($logger);
            
            // Create and invoke action
            $action = new \App\Application\Actions\Lesson\GenerateLessonAction($db, $geminiService);
            return $action($request, $response);
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(JwtMiddleware::class);

    // User routes
    $app->group('/users', function (Group $group) {
        // Quota endpoint MUST be defined BEFORE /{id} route (Slim routing precedence)
        $group->get('/quota', QuotaAction::class)->add(JwtMiddleware::class);
        
        $group->get('', ListUsersAction::class);
        $group->get('/{id}', ViewUserAction::class);
    });
};
