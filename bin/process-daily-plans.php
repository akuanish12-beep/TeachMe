#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily cron: generate due plan lessons and email users.
 * Example crontab (9:00 AM UTC):
 * 0 9 * * * php /var/www/teachme.mom/TeachMeAPI/app/backend/bin/process-daily-plans.php
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createMutable(__DIR__ . '/..');
$dotenv->safeLoad();

require __DIR__ . '/../app/settings.php';

$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST'), env('DB_PORT'), env('DB_NAME')),
    env('DB_USER'),
    env('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$logger = new Monolog\Logger('plans');
$logger->pushHandler(new Monolog\Handler\StreamHandler(__DIR__ . '/../storage/logs/app.log', Monolog\Logger::INFO));

$encryption = new App\Services\EncryptionService();
$tiers = new App\Services\SubscriptionTierService($db);
$gemini = new App\Services\GeminiService($db, $encryption, $tiers, $logger);

$service = new App\Services\LearningPlanService(
    $db,
    $gemini,
    new App\Services\EmailService(),
    $tiers,
    $logger
);

$result = $service->processDailyJobs();
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
