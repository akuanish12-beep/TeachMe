<?php

declare(strict_types=1);

use App\Application\Middleware\SessionMiddleware;
use App\Application\Middleware\CorsMiddleware;
use App\Application\Middleware\RateLimitMiddleware;
use App\Application\Middleware\MetricsLoggerMiddleware;
use Slim\App;

return function (App $app) {
    $app->add(MetricsLoggerMiddleware::class);
    $app->add(RateLimitMiddleware::class);
    $app->add(CorsMiddleware::class);
    $app->add(SessionMiddleware::class);
};
