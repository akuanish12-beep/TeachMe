<?php

declare(strict_types=1);

use App\Application\Settings\Settings;
use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {

    // Global Settings Object
    $containerBuilder->addDefinitions([
        SettingsInterface::class => function () {
            return new Settings([
                'displayErrorDetails' => $_ENV['APP_DEBUG'] === 'true', // false in production
                'logError'            => true,
                'logErrorDetails'     => true,
                'logger' => [
                    'name' => 'tutorly-api',
                    'path' => __DIR__ . '/../storage/logs/app.log',
                    'level' => Logger::DEBUG,
                ],
                'cors' => [
                    'origin' => $_ENV['FRONTEND_ORIGIN'] ?? 'http://localhost:5173',
                    'methods' => 'GET,POST,PUT,DELETE,OPTIONS',
                    'headers' => 'Content-Type,Authorization,X-Requested-With',
                ],
            ]);
        }
    ]);
};
