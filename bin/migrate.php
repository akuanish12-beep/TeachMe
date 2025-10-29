#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Database Migration Runner
 * 
 * Executes all SQL migration files in config/migrations/ directory
 * Idempotent - safe to run multiple times
 */

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Database configuration from .env
$dbHost = $_ENV['DB_HOST'];
$dbPort = $_ENV['DB_PORT'];
$dbName = $_ENV['DB_NAME'];
$dbUser = $_ENV['DB_USER'];
$dbPass = $_ENV['DB_PASS'];

echo "======================================\n";
echo "Tutorly Database Migration Runner\n";
echo "======================================\n\n";

try {
    // Connect to database
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✓ Connected to database: {$dbName}\n";
    echo "  Host: {$dbHost}:{$dbPort}\n";
    echo "  User: {$dbUser}\n\n";
    
    // Get migration files
    $migrationsPath = __DIR__ . '/../config/migrations';
    $files = glob($migrationsPath . '/*.sql');
    
    if (empty($files)) {
        echo "⚠ No migration files found in {$migrationsPath}\n";
        exit(0);
    }
    
    sort($files); // Execute in order
    
    echo "Found " . count($files) . " migration file(s):\n";
    foreach ($files as $file) {
        echo "  - " . basename($file) . "\n";
    }
    echo "\n";
    
    // Execute migrations
    foreach ($files as $file) {
        $filename = basename($file);
        echo "Executing: {$filename}... ";
        
        $sql = file_get_contents($file);
        
        // Remove comments and split by semicolons
        $sql = preg_replace('/--.*$/m', '', $sql); // Remove single-line comments
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($stmt) => !empty($stmt)
        );
        
        $executedCount = 0;
        foreach ($statements as $statement) {
            if (empty(trim($statement))) {
                continue;
            }
            
            try {
                $pdo->exec($statement);
                $executedCount++;
            } catch (PDOException $e) {
                // Ignore "already exists" errors for idempotency
                if (
                    strpos($e->getMessage(), 'already exists') !== false ||
                    strpos($e->getMessage(), 'Duplicate') !== false
                ) {
                    // Silent ignore - already exists
                    $executedCount++;
                    continue;
                } else {
                    // Re-throw other errors
                    throw $e;
                }
            }
        }
        
        echo "✓ ({$executedCount} statements)\n";
    }
    
    echo "\n======================================\n";
    echo "Migration completed successfully!\n";
    echo "======================================\n\n";
    
    // Show created tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Tables in database:\n";
    foreach ($tables as $table) {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $count = $countStmt->fetchColumn();
        echo "  - {$table} ({$count} rows)\n";
    }
    
} catch (PDOException $e) {
    echo "\n✗ Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";

