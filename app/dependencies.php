<?php

declare(strict_types=1);

use App\Application\Settings\SettingsInterface;
use App\Services\EmailService;
use App\Services\EncryptionService;
use App\Services\GeminiService;
use App\Services\OtpService;
use App\Services\StripeService;
use App\Services\SubscriptionTierService;
use App\Services\SupportEmailService;
use App\Services\SupportService;
use App\Services\LearningPlanService;
use App\Application\Middleware\StaffMiddleware;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get(SettingsInterface::class);

            $loggerSettings = $settings->get('logger');
            $logger = new Logger($loggerSettings['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },
        
        PDO::class => function (ContainerInterface $c) {
            $dbHost = env('DB_HOST');
            $dbPort = env('DB_PORT');
            $dbName = env('DB_NAME');
            $dbUser = env('DB_USER');
            $dbPass = env('DB_PASS');

            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            
            return new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        },
        
        EncryptionService::class => fn () => new EncryptionService(),

        SubscriptionTierService::class => function (ContainerInterface $c) {
            return new SubscriptionTierService($c->get(PDO::class));
        },

        GeminiService::class => function (ContainerInterface $c) {
            return new GeminiService(
                $c->get(PDO::class),
                $c->get(EncryptionService::class),
                $c->get(SubscriptionTierService::class),
                $c->get(LoggerInterface::class)
            );
        },
        
        StripeService::class => function (ContainerInterface $c) {
            return new StripeService(
                $c->get(PDO::class),
                $c->get(SubscriptionTierService::class)
            );
        },

        EmailService::class => function () {
            return new EmailService();
        },

        OtpService::class => function (ContainerInterface $c) {
            return new OtpService(
                $c->get(PDO::class),
                $c->get(EmailService::class)
            );
        },

        SupportEmailService::class => fn () => new SupportEmailService(),

        SupportService::class => function (ContainerInterface $c) {
            return new SupportService($c->get(PDO::class), $c->get(SupportEmailService::class));
        },

        StaffMiddleware::class => function (ContainerInterface $c) {
            return new StaffMiddleware($c->get(PDO::class));
        },

        LearningPlanService::class => function (ContainerInterface $c) {
            return new LearningPlanService(
                $c->get(PDO::class),
                $c->get(GeminiService::class),
                $c->get(EmailService::class),
                $c->get(SubscriptionTierService::class),
                $c->get(LoggerInterface::class)
            );
        },
    ]);
};
