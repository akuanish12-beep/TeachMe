<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;

class LearningPlanService
{
    private const ALLOWED_DURATIONS = [7, 15, 30];
    private const ALLOWED_SKILL_LEVELS = ['beginner', 'intermediate', 'expert', 'refresher'];

    private PDO $db;
    private GeminiService $gemini;
    private EmailService $email;
    private SubscriptionTierService $tiers;
    private ?LoggerInterface $logger;

    public function __construct(
        PDO $db,
        GeminiService $gemini,
        EmailService $email,
        SubscriptionTierService $tiers,
        ?LoggerInterface $logger = null
    ) {
        $this->db = $db;
        $this->gemini = $gemini;
        $this->email = $email;
        $this->tiers = $tiers;
        $this->logger = $logger;
    }

    /**
     * @param array{topic: string, language: string, duration_days: int, skill_level: string, goal_notes?: string} $input
     */
    public function createPlan(int $userId, array $input): array
    {
        $topic = trim($input['topic']);
        $language = trim($input['language'] ?: 'English');
        $durationDays = (int) $input['duration_days'];
        $skillLevel = $input['skill_level'];
        $goalNotes = trim($input['goal_notes'] ?? '');

        if ($topic === '') {
            throw new \InvalidArgumentException('Topic is required');
        }
        if (!in_array($durationDays, self::ALLOWED_DURATIONS, true)) {
            throw new \InvalidArgumentException('duration_days must be 7, 15, or 30');
        }
        if (!in_array($skillLevel, self::ALLOWED_SKILL_LEVELS, true)) {
            throw new \InvalidArgumentException('Invalid skill_level');
        }

        $this->assertPlanDurationAllowed($userId, $durationDays);
        $this->assertCanStartPlan($userId);
        $this->assertWithinActivePlanLimit($userId);

        $curriculum = $this->gemini->generatePlanCurriculum(
            $topic,
            $language,
            $skillLevel,
            $durationDays,
            $goalNotes !== '' ? $goalNotes : "Learn practical {$language} for: {$topic}",
            $userId
        );

        $startedOn = new \DateTimeImmutable('today');
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO learning_plans (user_id, topic, language, skill_level, duration_days, goal_notes, curriculum_json, status, started_on)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $topic,
                $language,
                $skillLevel,
                $durationDays,
                $goalNotes !== '' ? $goalNotes : null,
                json_encode($curriculum),
                'active',
                $startedOn->format('Y-m-d'),
            ]);
            $planId = (int) $this->db->lastInsertId();

            $dayStmt = $this->db->prepare(
                'INSERT INTO learning_plan_days (plan_id, day_number, scheduled_on, day_title, day_focus, difficulty_phase, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($curriculum['days'] as $day) {
                $dayNum = (int) $day['day'];
                $scheduled = $startedOn->modify('+' . ($dayNum - 1) . ' days')->format('Y-m-d');
                $dayStmt->execute([
                    $planId,
                    $dayNum,
                    $scheduled,
                    $day['title'],
                    $day['focus'],
                    $day['phase'],
                    'pending',
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->generateLessonForDay($planId, 1, $userId);
        $this->notifyDayReady($planId, 1, $userId);

        return $this->getPlanForUser($planId, $userId);
    }

    public function getActivePlan(int $userId): ?array
    {
        $plans = $this->getActivePlans($userId);

        return $plans[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getActivePlans(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM learning_plans WHERE user_id = ? AND status = 'active' ORDER BY id ASC"
        );
        $stmt->execute([$userId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $plans = [];
        foreach ($ids as $id) {
            $plans[] = $this->getPlanForUser((int) $id, $userId);
        }

        return $plans;
    }

    public function closePlan(int $planId, int $userId): array
    {
        $this->assertPlanOwnership($planId, $userId);
        $plan = $this->getPlanRow($planId, $userId);

        if ($plan['status'] !== 'active') {
            throw new \RuntimeException('Only active plans can be closed', 400);
        }

        $stmt = $this->db->prepare(
            "UPDATE learning_plans SET status = 'closed' WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$planId, $userId]);

        return $this->getPlanForUser($planId, $userId);
    }

    public function getPlanForUser(int $planId, int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM learning_plans WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$planId, $userId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            throw new \RuntimeException('Plan not found', 404);
        }

        $daysStmt = $this->db->prepare(
            'SELECT id, day_number, scheduled_on, day_title, day_focus, difficulty_phase, status, lesson_id, notified_at, completed_at, skipped_at
             FROM learning_plan_days WHERE plan_id = ? ORDER BY day_number ASC'
        );
        $daysStmt->execute([$planId]);
        $days = $daysStmt->fetchAll(PDO::FETCH_ASSOC);

        $progress = $this->buildProgress($days, (int) $plan['duration_days']);

        return [
            'id' => (int) $plan['id'],
            'topic' => $plan['topic'],
            'language' => $plan['language'],
            'skillLevel' => $plan['skill_level'],
            'durationDays' => (int) $plan['duration_days'],
            'goalNotes' => $plan['goal_notes'],
            'status' => $plan['status'],
            'startedOn' => $plan['started_on'],
            'progress' => $progress,
            'days' => array_map(fn (array $d) => $this->formatDay($d), $days),
        ];
    }

    public function openDay(int $planId, int $dayNumber, int $userId): array
    {
        $this->assertPlanOwnership($planId, $userId);
        $day = $this->getDayRow($planId, $dayNumber);

        if ($day['status'] === 'skipped') {
            throw new \RuntimeException('This day was skipped', 400);
        }

        if (!$this->isDayUnlocked($planId, $dayNumber, $day)) {
            throw new \RuntimeException('This day is not available yet', 403);
        }

        if ($day['status'] === 'pending') {
            $this->generateLessonForDay($planId, $dayNumber, $userId);
            $day = $this->getDayRow($planId, $dayNumber);
        }

        if ($day['status'] !== 'ready' && $day['status'] !== 'completed') {
            throw new \RuntimeException('Lesson not ready', 503);
        }

        $lesson = null;
        if ($day['lesson_id']) {
            $lesson = $this->fetchLesson((int) $day['lesson_id'], $userId);
        }

        return [
            'day' => $this->formatDay($day),
            'lesson' => $lesson,
        ];
    }

    public function completeDay(int $planId, int $dayNumber, int $userId): array
    {
        $this->assertPlanOwnership($planId, $userId);
        $day = $this->getDayRow($planId, $dayNumber);

        if (!in_array($day['status'], ['ready', 'completed'], true)) {
            throw new \RuntimeException('Complete the lesson before marking this day done', 400);
        }

        $stmt = $this->db->prepare(
            "UPDATE learning_plan_days SET status = 'completed', completed_at = NOW() WHERE plan_id = ? AND day_number = ?"
        );
        $stmt->execute([$planId, $dayNumber]);

        $this->maybeCompletePlan($planId);

        return $this->getPlanForUser($planId, $userId);
    }

    public function skipDay(int $planId, int $dayNumber, int $userId): array
    {
        $this->assertPlanOwnership($planId, $userId);
        $day = $this->getDayRow($planId, $dayNumber);

        if ($day['status'] === 'completed') {
            throw new \RuntimeException('Cannot skip a completed day', 400);
        }

        $stmt = $this->db->prepare(
            "UPDATE learning_plan_days SET status = 'skipped', skipped_at = NOW() WHERE plan_id = ? AND day_number = ?"
        );
        $stmt->execute([$planId, $dayNumber]);

        $this->maybeCompletePlan($planId);

        return $this->getPlanForUser($planId, $userId);
    }

    /**
     * Cron: generate due lessons and send notification emails.
     */
    public function processDailyJobs(): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $stmt = $this->db->prepare(
            "SELECT d.id AS day_row_id, d.plan_id, d.day_number, p.user_id, p.topic, p.language, u.email, u.full_name
             FROM learning_plan_days d
             JOIN learning_plans p ON p.id = d.plan_id
             JOIN users u ON u.id = p.user_id
             WHERE p.status = 'active'
               AND d.status = 'pending'
               AND d.scheduled_on <= ?
             ORDER BY d.scheduled_on ASC, d.day_number ASC"
        );
        $stmt->execute([$today]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $processed = 0;
        $emailed = 0;
        $errors = [];

        foreach ($rows as $row) {
            try {
                $this->generateLessonForDay((int) $row['plan_id'], (int) $row['day_number'], (int) $row['user_id']);
                $processed++;

                $day = $this->getDayRow((int) $row['plan_id'], (int) $row['day_number']);
                if ($day['notified_at'] === null && $day['status'] === 'ready') {
                    $this->notifyDayReady((int) $row['plan_id'], (int) $row['day_number'], (int) $row['user_id']);
                    $emailed++;
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'plan_id' => $row['plan_id'],
                    'day' => $row['day_number'],
                    'error' => $e->getMessage(),
                ];
                $this->log('error', 'Daily plan job failed', $errors[count($errors) - 1]);
            }
        }

        return ['processed' => $processed, 'emailed' => $emailed, 'errors' => $errors];
    }

    private function generateLessonForDay(int $planId, int $dayNumber, int $userId): void
    {
        $plan = $this->getPlanRow($planId, $userId);
        $day = $this->getDayRow($planId, $dayNumber);

        if ($day['status'] === 'ready' || $day['status'] === 'completed') {
            return;
        }

        $curriculum = json_decode($plan['curriculum_json'], true);
        $dayOutline = null;
        foreach ($curriculum['days'] ?? [] as $d) {
            if ((int) $d['day'] === $dayNumber) {
                $dayOutline = $d;
                break;
            }
        }
        if ($dayOutline === null) {
            throw new \RuntimeException('Curriculum day not found');
        }

        $prevStmt = $this->db->prepare(
            'SELECT day_title FROM learning_plan_days WHERE plan_id = ? AND day_number < ? ORDER BY day_number ASC'
        );
        $prevStmt->execute([$planId, $dayNumber]);
        $previousTitles = array_column($prevStmt->fetchAll(PDO::FETCH_ASSOC), 'day_title');

        $lessonData = $this->gemini->generatePlanDayLesson(
            $plan['topic'],
            $plan['language'],
            $plan['skill_level'],
            $dayNumber,
            (int) $plan['duration_days'],
            $dayOutline,
            $previousTitles,
            $userId
        );

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lessons (user_id, topic, language, title, content_json) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $plan['topic'],
                $plan['language'],
                $lessonData['title'],
                json_encode($lessonData),
            ]);
            $lessonId = (int) $this->db->lastInsertId();

            $gen = $this->db->prepare(
                'INSERT INTO generations (user_id, topic, language, result_json) VALUES (?, ?, ?, ?)'
            );
            $gen->execute([
                $userId,
                $plan['topic'] . " (Plan day {$dayNumber})",
                $plan['language'],
                json_encode($lessonData),
            ]);

            $upd = $this->db->prepare(
                "UPDATE learning_plan_days SET status = 'ready', lesson_id = ? WHERE plan_id = ? AND day_number = ?"
            );
            $upd->execute([$lessonId, $planId, $dayNumber]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function notifyDayReady(int $planId, int $dayNumber, int $userId): void
    {
        $day = $this->getDayRow($planId, $dayNumber);
        if ($day['notified_at'] !== null || $day['status'] !== 'ready') {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT u.email, u.full_name, p.topic FROM users u JOIN learning_plans p ON p.user_id = u.id WHERE p.id = ? AND u.id = ?'
        );
        $stmt->execute([$planId, $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return;
        }

        $appUrl = rtrim(env('APP_URL', 'https://teachme.mom'), '/');
        $link = "{$appUrl}/plan/{$planId}/day/{$dayNumber}";

        $this->email->sendPlanDayReadyEmail(
            $user['email'],
            $user['full_name'],
            $user['topic'],
            $dayNumber,
            $day['day_title'],
            $link
        );

        $notify = $this->db->prepare(
            'UPDATE learning_plan_days SET notified_at = NOW() WHERE plan_id = ? AND day_number = ?'
        );
        $notify->execute([$planId, $dayNumber]);
    }

    private function hasActiveSubscription(int $userId): bool
    {
        return $this->tiers->hasPaidAccess($userId);
    }

    private function assertWithinActivePlanLimit(int $userId): void
    {
        $max = $this->tiers->getMaxConcurrentPlansForUser($userId);
        $activeCount = $this->countActivePlans($userId);

        if ($activeCount >= $max) {
            $tier = $this->tiers->getEffectiveTier($userId);
            $label = $this->tiers->getTierLabel($tier);
            throw new \RuntimeException(
                "You have reached your limit of {$max} active learning plan(s) on {$label}. "
                . 'Complete or close a plan before starting another.',
                400
            );
        }
    }

    private function countActivePlans(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM learning_plans WHERE user_id = ? AND status = 'active'"
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    private function assertPlanDurationAllowed(int $userId, int $durationDays): void
    {
        if (in_array($durationDays, [15, 30], true) && !$this->hasActiveSubscription($userId)) {
            throw new \RuntimeException('15 and 30-day learning plans are available on Pro only', 402);
        }
    }

    private function assertCanStartPlan(int $userId): void
    {
        if ($this->hasActiveSubscription($userId)) {
            return;
        }

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM learning_plans WHERE user_id = ?');
        $countStmt->execute([$userId]);
        if ((int) $countStmt->fetchColumn() > 0) {
            throw new \RuntimeException('Additional learning plans require a Pro subscription', 402);
        }
    }

    private function assertPlanOwnership(int $planId, int $userId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM learning_plans WHERE id = ? AND user_id = ?');
        $stmt->execute([$planId, $userId]);
        if (!$stmt->fetch()) {
            throw new \RuntimeException('Plan not found', 404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getPlanRow(int $planId, int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM learning_plans WHERE id = ? AND user_id = ?');
        $stmt->execute([$planId, $userId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            throw new \RuntimeException('Plan not found', 404);
        }

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    private function getDayRow(int $planId, int $dayNumber): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM learning_plan_days WHERE plan_id = ? AND day_number = ?'
        );
        $stmt->execute([$planId, $dayNumber]);
        $day = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$day) {
            throw new \RuntimeException('Day not found', 404);
        }

        return $day;
    }

    /**
     * @param array<string, mixed> $day
     */
    private function isDayUnlocked(int $planId, int $dayNumber, array $day): bool
    {
        $today = new \DateTimeImmutable('today');
        $scheduled = new \DateTimeImmutable($day['scheduled_on']);

        if ($scheduled > $today) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $days
     */
    private function buildProgress(array $days, int $totalDays): array
    {
        $completed = 0;
        $skipped = 0;
        $ready = 0;
        $pending = 0;
        $missed = 0;
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        foreach ($days as $day) {
            match ($day['status']) {
                'completed' => $completed++,
                'skipped' => $skipped++,
                'ready' => $ready++,
                default => $pending++,
            };
            if ($day['status'] === 'pending' && $day['scheduled_on'] < $today) {
                $missed++;
            }
        }

        $currentDay = 1;
        foreach ($days as $day) {
            if ($day['status'] === 'pending' || $day['status'] === 'ready') {
                $currentDay = (int) $day['day_number'];
                break;
            }
        }

        return [
            'totalDays' => $totalDays,
            'completed' => $completed,
            'skipped' => $skipped,
            'ready' => $ready,
            'pending' => $pending,
            'missed' => $missed,
            'percentComplete' => $totalDays > 0
                ? (int) round((($completed + $skipped) / $totalDays) * 100)
                : 0,
            'currentDay' => $currentDay,
        ];
    }

    /**
     * @param array<string, mixed> $day
     */
    private function formatDay(array $day): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $isMissed = $day['status'] === 'pending' && $day['scheduled_on'] < $today;

        return [
            'dayNumber' => (int) $day['day_number'],
            'scheduledOn' => $day['scheduled_on'],
            'title' => $day['day_title'],
            'focus' => $day['day_focus'],
            'phase' => $day['difficulty_phase'],
            'status' => $day['status'],
            'lessonId' => $day['lesson_id'] ? (int) $day['lesson_id'] : null,
            'isMissed' => $isMissed,
            'completedAt' => $day['completed_at'],
            'skippedAt' => $day['skipped_at'],
        ];
    }

    private function fetchLesson(int $lessonId, int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, topic, language, title, content_json, created_at FROM lessons WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$lessonId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Lesson not found', 404);
        }

        return [
            'id' => (int) $row['id'],
            'topic' => $row['topic'],
            'language' => $row['language'],
            'title' => $row['title'],
            'lesson' => json_decode($row['content_json'], true),
            'created_at' => $row['created_at'],
        ];
    }

    private function maybeCompletePlan(int $planId): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM learning_plan_days WHERE plan_id = ? AND status IN ('pending', 'ready')"
        );
        $stmt->execute([$planId]);
        if ((int) $stmt->fetchColumn() === 0) {
            $upd = $this->db->prepare("UPDATE learning_plans SET status = 'completed' WHERE id = ?");
            $upd->execute([$planId]);
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
