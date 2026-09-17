<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createMutable(__DIR__ . '/..');
$dotenv->safeLoad();

function getEnvVar(string $key, $default = null) {
    return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? $default;
}

function generateUuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

try {
    $db = new \PDO(
        sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", 
            getEnvVar('DB_HOST', '127.0.0.1'), 
            getEnvVar('DB_PORT', '3306'), 
            getEnvVar('DB_NAME', 'teachmedb')
        ),
        getEnvVar('DB_USER', 'root'),
        getEnvVar('DB_PASS', ''),
        [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]
    );

    // Determine week bounds (assuming weeks run Monday to Sunday)
    $now = new DateTime('now', new DateTimeZone('UTC'));
    
    // If run on Sunday, prepare for next Monday. Otherwise, prepare for this Monday.
    if ($now->format('w') == 0) { 
        $newWeekStart = $now->modify('+1 day')->format('Y-m-d');
        $newWeekEnd = clone $now;
        $newWeekEnd->modify('+6 days');
        $newWeekEndStr = $newWeekEnd->format('Y-m-d');
        
        $prevWeekStart = (new DateTime('now', new DateTimeZone('UTC')))->modify('-6 days')->format('Y-m-d');
        $prevWeekEnd = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
    } else { 
        $monday = clone $now;
        $monday->modify('monday this week');
        $newWeekStart = $monday->format('Y-m-d');
        
        $sunday = clone $monday;
        $sunday->modify('+6 days');
        $newWeekEndStr = $sunday->format('Y-m-d');
        
        $prevWeekStart = (clone $monday)->modify('-7 days')->format('Y-m-d');
        $prevWeekEnd = (clone $monday)->modify('-1 day')->format('Y-m-d');
    }

    $db->beginTransaction();

    // 1. Fetch leagues ordered by rank_tier
    $stmt = $db->query("SELECT * FROM leagues ORDER BY rank_tier ASC");
    $leagues = $stmt->fetchAll();
    
    if (empty($leagues)) {
        throw new \Exception("No leagues found in the database. Please seed the leagues table.");
    }

    $leagueByTier = [];
    foreach ($leagues as $l) {
        $leagueByTier[$l['rank_tier']] = $l['id'];
    }
    
    $minTier = min(array_keys($leagueByTier));
    $maxTier = max(array_keys($leagueByTier));

    // 2. Process previous cohorts
    $stmt = $db->prepare("
        SELECT c.id as cohort_id, c.league_id, l.rank_tier, m.user_id, m.weekly_xp 
        FROM leaderboard_cohorts c
        JOIN leaderboard_members m ON c.id = m.cohort_id
        JOIN leagues l ON c.league_id = l.id
        WHERE c.week_start_date = ? AND c.week_end_date = ?
        ORDER BY c.id, m.weekly_xp DESC
    ");
    $stmt->execute([$prevWeekStart, $prevWeekEnd]);
    $prevMembers = $stmt->fetchAll();

    $cohorts = [];
    foreach ($prevMembers as $row) {
        $cohorts[$row['cohort_id']][] = $row;
    }

    $userNextTier = [];
    foreach ($cohorts as $cohortId => $members) {
        $totalMembers = count($members);
        foreach ($members as $index => $member) {
            $rank = $index + 1;
            $currentTier = $member['rank_tier'];
            $nextTier = $currentTier;
            
            // Promote Top 3
            if ($rank <= 3) {
                $nextTier = min($maxTier, $currentTier + 1);
            }
            // Demote Bottom 3
            elseif ($rank > $totalMembers - 3) {
                $nextTier = max($minTier, $currentTier - 1);
            }
            
            $userNextTier[$member['user_id']] = $nextTier;
        }
    }

    // 3. Find active users
    $stmt = $db->prepare("
        SELECT id 
        FROM users 
        WHERE last_active_date >= NOW() - INTERVAL 14 DAY 
           OR id IN (SELECT user_id FROM leaderboard_members)
    ");
    $stmt->execute();
    $activeUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $tierUsers = [];
    foreach ($leagueByTier as $tier => $leagueId) {
        $tierUsers[$tier] = [];
    }

    foreach ($activeUsers as $userId) {
        $tier = $userNextTier[$userId] ?? $minTier; 
        $tierUsers[$tier][] = $userId;
    }

    // Check if cohorts for the new week already exist
    $checkStmt = $db->prepare("SELECT COUNT(*) FROM leaderboard_cohorts WHERE week_start_date = ?");
    $checkStmt->execute([$newWeekStart]);
    if ($checkStmt->fetchColumn() > 0) {
        // Delete existing if we're re-running
        $delStmt = $db->prepare("DELETE FROM leaderboard_cohorts WHERE week_start_date = ?");
        $delStmt->execute([$newWeekStart]);
    }

    // 4. Create new cohorts
    $insertCohortStmt = $db->prepare("INSERT INTO leaderboard_cohorts (id, league_id, week_start_date, week_end_date) VALUES (?, ?, ?, ?)");
    $insertMemberStmt = $db->prepare("INSERT INTO leaderboard_members (cohort_id, user_id, weekly_xp) VALUES (?, ?, 0)");

    foreach ($tierUsers as $tier => $users) {
        shuffle($users);
        $leagueId = $leagueByTier[$tier];
        
        $chunks = array_chunk($users, 30);
        foreach ($chunks as $chunk) {
            $cohortId = generateUuid();
            $insertCohortStmt->execute([$cohortId, $leagueId, $newWeekStart, $newWeekEndStr]);
            
            foreach ($chunk as $userId) {
                $insertMemberStmt->execute([$cohortId, $userId]);
            }
        }
    }

    $db->commit();
    echo "Leaderboard cohorts successfully generated for $newWeekStart to $newWeekEndStr.\n";
} catch (\Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
