<?php

declare(strict_types=1);

namespace App\Application\Actions\Lesson;

use App\Application\Helpers\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CompleteLessonAction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        // Require authenticated user ID to prevent cheating
        $authUserId = (int) $request->getAttribute('user_id');
        
        $body = $request->getParsedBody();
        $payloadUserId = $body['user_id'] ?? null;
        $lessonId = isset($body['lesson_id']) ? (int) $body['lesson_id'] : null;
        $planId = isset($body['plan_id']) ? (int) $body['plan_id'] : null;
        $dayNumber = isset($body['day_number']) ? (int) $body['day_number'] : null;

        // Validation
        if (!$lessonId) {
            return JsonResponse::error($response, 'lesson_id is required', 400);
        }

        try {
            $this->db->beginTransaction();

            // Check if already completed to prevent double XP
            $alreadyCompleted = false;
            
            // Check plan day completion if plan context is provided
            if ($planId && $dayNumber) {
                $stmt = $this->db->prepare(
                    "SELECT status FROM learning_plan_days WHERE plan_id = ? AND day_number = ?"
                );
                $stmt->execute([$planId, $dayNumber]);
                $dayRow = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dayRow && $dayRow['status'] === 'completed') {
                    $alreadyCompleted = true;
                } else if ($dayRow) {
                    $upd = $this->db->prepare(
                        "UPDATE learning_plan_days SET status = 'completed', completed_at = NOW() WHERE plan_id = ? AND day_number = ?"
                    );
                    $upd->execute([$planId, $dayNumber]);
                    
                    // Update the active plan status if all days are completed
                    $checkPending = $this->db->prepare(
                        "SELECT COUNT(*) FROM learning_plan_days WHERE plan_id = ? AND status IN ('pending', 'ready')"
                    );
                    $checkPending->execute([$planId]);
                    if ((int) $checkPending->fetchColumn() === 0) {
                        $updPlan = $this->db->prepare("UPDATE learning_plans SET status = 'completed' WHERE id = ?");
                        $updPlan->execute([$planId]);
                    }
                }
            }

            // Always check/update the standalone lesson table record
            $lessonStmt = $this->db->prepare(
                "SELECT completed_at, user_id FROM lessons WHERE id = ?"
            );
            $lessonStmt->execute([$lessonId]);
            $lessonRow = $lessonStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$lessonRow || (int)$lessonRow['user_id'] !== $authUserId) {
                $this->db->rollBack();
                return JsonResponse::error($response, 'Lesson not found or access denied', 404);
            }
            
            if ($lessonRow['completed_at'] !== null) {
                $alreadyCompleted = true;
            } else {
                $updLesson = $this->db->prepare("UPDATE lessons SET completed_at = NOW() WHERE id = ?");
                $updLesson->execute([$lessonId]);
            }

            if ($alreadyCompleted) {
                $this->db->rollBack();
                return JsonResponse::error($response, 'Lesson already completed, no duplicate XP awarded', 400);
            }

            // Fetch user gamification stats
            $userStmt = $this->db->prepare(
                "SELECT total_xp, current_level, current_streak, longest_streak, last_active_date, streak_freezes_count 
                 FROM users WHERE id = ?"
            );
            $userStmt->execute([$authUserId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $this->db->rollBack();
                return JsonResponse::error($response, 'User not found', 404);
            }

            // 1. Calculate XP and Level
            $xpGained = 15; // Standard baseline
            $newTotalXp = (int) $user['total_xp'] + $xpGained;
            $currentLevel = (int) $user['current_level'];
            
            // Formula: Level = Floor(SquareRoot(total_xp) / 10) + 1
            $calculatedLevel = (int) floor(sqrt($newTotalXp) / 10) + 1;
            
            $levelUp = false;
            $newLevel = $currentLevel;
            if ($calculatedLevel > $currentLevel) {
                $levelUp = true;
                $newLevel = $calculatedLevel;
            }

            // 2. Calculate Streak
            $today = new \DateTimeImmutable('today'); // Server date with 00:00:00 time
            $lastActiveStr = $user['last_active_date'] ? substr($user['last_active_date'], 0, 10) : null;
            $lastActive = $lastActiveStr ? new \DateTimeImmutable($lastActiveStr) : null;
            
            $streak = (int) $user['current_streak'];
            $longest = (int) $user['longest_streak'];
            $freezes = (int) $user['streak_freezes_count'];
            $streakFrozen = false;

            if ($lastActive) {
                $interval = $lastActive->diff($today);
                $daysDiff = (int) $interval->format('%R%a'); // Positive means today > lastActive
                
                if ($daysDiff == 1) {
                    // Completed a lesson yesterday, increment streak
                    $streak++;
                } else if ($daysDiff > 1) {
                    // Missed one or more days (before yesterday)
                    if ($freezes > 0) {
                        // Protect streak by decrementing freeze count
                        $freezes--;
                        $streak++; // Increment because they completed one today!
                        $streakFrozen = true;
                    } else {
                        // Streak broken
                        $streak = 1;
                    }
                }
                // If daysDiff == 0, it means they already completed a lesson today.
                // Keep the streak exactly the same (do not increment).
            } else {
                // First lesson ever!
                $streak = 1;
            }

            if ($streak > $longest) {
                $longest = $streak;
            }

            // Update user stats
            $updUser = $this->db->prepare(
                "UPDATE users 
                 SET total_xp = ?, 
                     current_level = ?, 
                     current_streak = ?, 
                     longest_streak = ?, 
                     last_active_date = NOW(),
                     streak_freezes_count = ?
                 WHERE id = ?"
            );
            $updUser->execute([
                $newTotalXp,
                $newLevel,
                $streak,
                $longest,
                $freezes,
                $authUserId
            ]);

            // Update weekly XP for current cohort
            $this->db->prepare(
                "UPDATE leaderboard_members lm
                 JOIN leaderboard_cohorts lc ON lm.cohort_id = lc.id
                 SET lm.weekly_xp = lm.weekly_xp + ?
                 WHERE lm.user_id = ? AND lc.week_start_date <= CURDATE() AND lc.week_end_date >= CURDATE()"
            )->execute([$xpGained, $authUserId]);

            // --- Co-op Challenge Logic ---
            $challengeStmt = $this->db->prepare(
                "SELECT id, player_one_id, player_two_id, target_xp_goal, player_one_contribution, player_two_contribution 
                 FROM shared_challenges 
                 WHERE status = 'active' AND (player_one_id = ? OR player_two_id = ?)"
            );
            $challengeStmt->execute([$authUserId, $authUserId]);
            $activeChallenges = $challengeStmt->fetchAll(PDO::FETCH_ASSOC);

            $challengeCompleted = false;
            $bonusXp = 50;

            foreach ($activeChallenges as $challenge) {
                $isPlayerOne = ($challenge['player_one_id'] == $authUserId);
                $p1Contrib = (int)$challenge['player_one_contribution'];
                $p2Contrib = (int)$challenge['player_two_contribution'];

                if ($isPlayerOne) {
                    $p1Contrib += $xpGained;
                } else {
                    $p2Contrib += $xpGained;
                }

                $totalContrib = $p1Contrib + $p2Contrib;
                $targetGoal = (int)$challenge['target_xp_goal'];

                if ($totalContrib >= $targetGoal) {
                    // Challenge completed
                    $updChallenge = $this->db->prepare(
                        "UPDATE shared_challenges SET player_one_contribution = ?, player_two_contribution = ?, status = 'completed' WHERE id = ?"
                    );
                    $updChallenge->execute([$p1Contrib, $p2Contrib, $challenge['id']]);

                    // Add Bonus XP to both players
                    // Update P1
                    $this->db->prepare("UPDATE users SET total_xp = total_xp + ? WHERE id = ?")->execute([$bonusXp, $challenge['player_one_id']]);
                    $this->db->prepare("UPDATE leaderboard_members lm JOIN leaderboard_cohorts lc ON lm.cohort_id = lc.id SET lm.weekly_xp = lm.weekly_xp + ? WHERE lm.user_id = ? AND lc.week_start_date <= CURDATE() AND lc.week_end_date >= CURDATE()")->execute([$bonusXp, $challenge['player_one_id']]);
                    // Update P2
                    $this->db->prepare("UPDATE users SET total_xp = total_xp + ? WHERE id = ?")->execute([$bonusXp, $challenge['player_two_id']]);
                    $this->db->prepare("UPDATE leaderboard_members lm JOIN leaderboard_cohorts lc ON lm.cohort_id = lc.id SET lm.weekly_xp = lm.weekly_xp + ? WHERE lm.user_id = ? AND lc.week_start_date <= CURDATE() AND lc.week_end_date >= CURDATE()")->execute([$bonusXp, $challenge['player_two_id']]);
                    
                    $newTotalXp += $bonusXp;
                    $challengeCompleted = true;
                } else {
                    // Just update contribution
                    $updChallenge = $this->db->prepare(
                        "UPDATE shared_challenges SET player_one_contribution = ?, player_two_contribution = ? WHERE id = ?"
                    );
                    $updChallenge->execute([$p1Contrib, $p2Contrib, $challenge['id']]);
                }
            }
            
            // Re-check level up if bonus XP was awarded
            if ($challengeCompleted) {
                 $calculatedLevel = (int) floor(sqrt($newTotalXp) / 10) + 1;
                 if ($calculatedLevel > $newLevel) {
                     $levelUp = true;
                     $newLevel = $calculatedLevel;
                 }
                 $this->db->prepare("UPDATE users SET current_level = ? WHERE id = ?")->execute([$newLevel, $authUserId]);
            }
            // --- End Co-op Challenge Logic ---

            $this->db->commit();

            return JsonResponse::success($response, [
                'status' => 'success',
                'xp_gained' => $xpGained,
                'total_xp' => $newTotalXp,
                'level_up' => $levelUp,
                'new_level' => $newLevel,
                'current_streak' => $streak,
                'streak_frozen' => $streakFrozen,
                'challenge_completed' => $challengeCompleted,
                'bonus_xp' => $challengeCompleted ? $bonusXp : 0
            ]);

        } catch (\Exception $e) {
            $this->db->rollBack();
            return JsonResponse::error($response, 'Internal server error: ' . $e->getMessage(), 500);
        }
    }
}
