<?php

namespace Library\Defer;

use Library\Defer\Handlers\ProcessDeferHandler;
use Library\Defer\Utilities\LazyTask;

/**
 * Static process utility for monitoring and awaiting background tasks
 */
class Process
{
    protected static ?ProcessDeferHandler $handler = null;

    protected static function getHandler(): ProcessDeferHandler
    {
        if (self::$handler === null) {
            self::$handler = new ProcessDeferHandler;
        }
        return self::$handler;
    }

    /**
     * Monitor a task until completion or timeout
     */
    public static function monitor(string $taskId, int $timeoutSeconds = 30, ?callable $progressCallback = null): array
    {
        $startTime = time();
        $lastStatus = null;
        $displayedOutput = false;

        do {
            $status = self::getTaskStatus($taskId);

            if (isset($status['output']) && !empty($status['output']) && !$displayedOutput) {
                echo $status['output'];
                $displayedOutput = true;
            }

            if ($progressCallback && $status !== $lastStatus) {
                $progressCallback($status);
                $lastStatus = $status;
            }

            if (in_array($status['status'], ['COMPLETED', 'ERROR', 'NOT_FOUND'])) {
                if (isset($status['output']) && !empty($status['output']) && !$displayedOutput) {
                    echo $status['output'];
                }
                return $status;
            }

            if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                return array_merge($status, [
                    'timeout' => true,
                    'message' => $status['message'] . ' (monitoring timeout reached)'
                ]);
            }

            usleep(10000);

        } while (true);
    }

    /**
     * Wait for a task to complete and return its result
     */
    public static function await(string $taskId, int $timeoutSeconds = 60): mixed
    {
        if (LazyTask::isLazyId($taskId)) {
            $task = LazyTask::get($taskId);
            if (!$task) {
                throw new \RuntimeException("Lazy task not found: {$taskId}");
            }

            $realTaskId = $task->execute();
            return self::await($realTaskId, $timeoutSeconds);
        }

        $finalStatus = self::monitor($taskId, $timeoutSeconds);

        if ($finalStatus['status'] === 'COMPLETED') {
            return $finalStatus['result'] ?? null;
        }

        if (isset($finalStatus['timeout']) && $finalStatus['timeout']) {
            throw new \RuntimeException("Task {$taskId} timed out after {$timeoutSeconds} seconds");
        }

        if ($finalStatus['status'] === 'ERROR') {
            $errorMsg = $finalStatus['error_message'] ?? $finalStatus['message'];
            throw new \RuntimeException("Task {$taskId} failed: {$errorMsg}");
        }

        throw new \RuntimeException("Task {$taskId} ended with unexpected status: " . $finalStatus['status']);
    }

    /**
     * Get task status, handling lazy tasks
     */
    public static function getTaskStatus(string $taskId): array
    {
        if (LazyTask::isLazyId($taskId)) {
            $task = LazyTask::get($taskId);
            if (!$task) {
                return [
                    'task_id' => $taskId,
                    'status' => 'NOT_FOUND',
                    'message' => 'Lazy task not found'
                ];
            }

            if (!$task->isExecuted()) {
                return [
                    'task_id' => $taskId,
                    'status' => 'LAZY_PENDING',
                    'message' => 'Lazy task not yet executed'
                ];
            }

            return self::getTaskStatus($task->getRealTaskId());
        }

        return self::getHandler()->getTaskStatus($taskId);
    }

    /**
     * Get status of all background tasks
     */
    public static function getAllTasksStatus(): array
    {
        return self::getHandler()->getAllTasksStatus();
    }

    /**
     * Get summary statistics of background tasks
     */
    public static function getTasksSummary(): array
    {
        return self::getHandler()->getTasksSummary();
    }

    /**
     * Get recent background task logs
     */
    public static function getRecentLogs(int $limit = 100): array
    {
        return self::getHandler()->getRecentLogs($limit);
    }

    /**
     * Clean up old background task files and logs
     */
    public static function cleanupOldTasks(int $maxAgeHours = 24): int
    {
        return self::getHandler()->cleanupOldTasks($maxAgeHours);
    }

    /**
     * Create a task monitoring dashboard data
     */
    public static function getDashboardData(): array
    {
        $summary = self::getTasksSummary();
        $recentLogs = self::getRecentLogs(20);
        $activeTasks = array_filter(self::getAllTasksStatus(), function ($task) {
            return in_array($task['status'], ['PENDING', 'RUNNING']);
        });

        return [
            'summary' => $summary,
            'active_tasks' => $activeTasks,
            'recent_logs' => $recentLogs,
            'system_info' => self::getStats(),
            'last_updated' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Get defer statistics including background task info
     */
    public static function getStats(): array
    {
        $handler = self::$handler;
        if ($handler === null) {
            return [
                'global_defers' => 0,
                'terminate_callbacks' => 0,
                'memory_usage' => memory_get_usage(true),
                'background_tasks' => [
                    'total' => 0,
                    'active' => 0
                ]
            ];
        }

        $stats = $handler->getStats();
        $tasksSummary = self::getTasksSummary();

        $stats['background_tasks'] = [
            'total' => $tasksSummary['total_tasks'],
            'active' => $tasksSummary['running'] + $tasksSummary['pending'],
            'completed' => $tasksSummary['completed'],
            'failed' => $tasksSummary['failed']
        ];

        return $stats;
    }

    /**
     * Get lazy task context without executing
     */
    public static function getLazyTaskContext(string $lazyTaskId): ?array
    {
        $task = LazyTask::get($lazyTaskId);
        return $task ? $task->getContext() : null;
    }

    /**
     * Update lazy task context before execution
     */
    public static function setLazyTaskContext(string $lazyTaskId, array $context): bool
    {
        $task = LazyTask::get($lazyTaskId);
        if ($task) {
            $task->setContext($context);
            return true;
        }
        return false;
    }
}