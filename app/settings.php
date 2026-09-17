<?php

declare(strict_types=1);

use App\Application\Settings\Settings;
use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Logger;

// Helper function to get env variable from either Apache SetEnv or .env file
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        // Prefer .env ($_ENV) over Apache SetEnv
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return $default;
    }
}

return function (ContainerBuilder $containerBuilder) {

    // Global Settings Object
    $containerBuilder->addDefinitions([
        SettingsInterface::class => function () {
            return new Settings([
                'displayErrorDetails' => env('APP_DEBUG') === 'true', // false in production
                'logError'            => true,
                'logErrorDetails'     => true,
                'logger' => [
                    'name' => 'teachme-api',
                    'path' => __DIR__ . '/../storage/logs/app.log',
                    'level' => Logger::DEBUG,
                ],
                'cors' => [
                    'origin' => env('FRONTEND_ORIGIN', 'http://localhost:5173'),
                    'methods' => 'GET,POST,PUT,DELETE,OPTIONS',
                    'headers' => 'Content-Type,Authorization,X-Requested-With',
                ],
            ]);
        }
    ]);
};
