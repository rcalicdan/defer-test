<?php

namespace Library\Defer;

use Library\Defer\Handlers\ProcessDeferHandler;
use Library\Defer\Utilities\DeferInstance;

/**
 * Static defer utility with reliable function scope management and background task monitoring
 */
class Defer
{
    /**
     * @var ProcessDeferHandler|null Global defer handler
     */
    private static ?ProcessDeferHandler $globalHandler = null;

    /**
     * Create a new function-scoped defer instance
     *
     * @return DeferInstance Function-scoped defer instance
     */
    public static function scope(): DeferInstance
    {
        return new DeferInstance;
    }

    /**
     * Global-scoped defer - executes at script shutdown
     *
     * @param callable $callback The callback to defer
     */
    public static function global(callable $callback): void
    {
        if (self::$globalHandler === null) {
            self::$globalHandler = new ProcessDeferHandler;
        }

        self::$globalHandler->defer($callback);
    }

    /**
     * Terminate-scoped defer - executes after response is sent (like Laravel's defer)
     * 
     * This is similar to Laravel's terminable middleware and defer() helper.
     * Callbacks are executed after the HTTP response has been sent to the client
     * or after the main CLI script execution completes.
     *
     * @param callable $callback The callback to execute after response
     * @param bool $forceBackground Force background execution even in FastCGI environments
     * @param array $context Additional context data to pass to background task
     * @return string|null Task ID if background execution is used, null otherwise
     */
    public static function terminate(callable $callback, bool $forceBackground = false, array $context = []): ?string
    {
        if (self::$globalHandler === null) {
            self::$globalHandler = new ProcessDeferHandler;
        }

        return self::$globalHandler->terminate($callback, $forceBackground, $context);
    }

    /**
     * Execute a background task with full monitoring capabilities
     * 
     * @param callable $callback The callback to execute in background
     * @param array $context Additional context data
     * @return string Task ID for monitoring
     */
    public static function background(callable $callback, array $context = []): string
    {
        if (self::$globalHandler === null) {
            self::$globalHandler = new ProcessDeferHandler;
        }

        return self::$globalHandler->executeBackground($callback, $context);
    }

    /**
     * Get the status of a background task
     * 
     * @param string $taskId Task ID returned from terminate() or background()
     * @return array Task status information
     */
    public static function getTaskStatus(string $taskId): array
    {
        if (self::$globalHandler === null) {
            return [
                'task_id' => $taskId,
                'status' => 'NO_HANDLER',
                'message' => 'No defer handler initialized',
                'timestamp' => null
            ];
        }

        return self::$globalHandler->getTaskStatus($taskId);
    }

    /**
     * Get status of all background tasks
     * 
     * @return array All tasks status
     */
    public static function getAllTasksStatus(): array
    {
        if (self::$globalHandler === null) {
            return [];
        }

        return self::$globalHandler->getAllTasksStatus();
    }

    /**
     * Get summary statistics of background tasks
     * 
     * @return array Summary statistics
     */
    public static function getTasksSummary(): array
    {
        if (self::$globalHandler === null) {
            return [
                'total_tasks' => 0,
                'running' => 0,
                'completed' => 0,
                'failed' => 0,
                'pending' => 0
            ];
        }

        return self::$globalHandler->getTasksSummary();
    }

    /**
     * Get recent background task logs
     * 
     * @param int $limit Maximum number of log entries to return
     * @return array Recent log entries
     */
    public static function getRecentLogs(int $limit = 100): array
    {
        if (self::$globalHandler === null) {
            return [];
        }

        return self::$globalHandler->getRecentLogs($limit);
    }

    /**
     * Clean up old background task files and logs
     * 
     * @param int $maxAgeHours Maximum age in hours before cleanup
     * @return int Number of files cleaned up
     */
    public static function cleanupOldTasks(int $maxAgeHours = 24): int
    {
        if (self::$globalHandler === null) {
            return 0;
        }

        return self::$globalHandler->cleanupOldTasks($maxAgeHours);
    }

    /**
     * Monitor a task until completion or timeout
     * 
     * @param string $taskId Task ID to monitor
     * @param int $timeoutSeconds Maximum time to wait (0 = no timeout)
     * @param callable|null $progressCallback Called with status updates
     * @return array Final task status
     */
    public static function monitorTask(string $taskId, int $timeoutSeconds = 30, ?callable $progressCallback = null): array
    {
        if (self::$globalHandler === null) {
            return [
                'task_id' => $taskId,
                'status' => 'NO_HANDLER',
                'message' => 'No defer handler initialized'
            ];
        }

        $startTime = time();
        $lastStatus = null;

        do {
            $status = self::getTaskStatus($taskId);
            
            // Call progress callback if status changed
            if ($progressCallback && $status !== $lastStatus) {
                $progressCallback($status);
                $lastStatus = $status;
            }

            // Check if task is finished
            if (in_array($status['status'], ['COMPLETED', 'ERROR', 'NOT_FOUND'])) {
                return $status;
            }

            // Check timeout
            if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                return array_merge($status, [
                    'timeout' => true,
                    'message' => $status['message'] . ' (monitoring timeout reached)'
                ]);
            }

            usleep(250000); // Wait 250ms before next check

        } while (true);
    }

    /**
     * Wait for a task to complete and return its result
     * 
     * @param string $taskId Task ID to wait for
     * @param int $timeoutSeconds Maximum time to wait
     * @return mixed Task result or null if failed/timeout
     * @throws \RuntimeException If task fails or times out
     */
    public static function awaitTask(string $taskId, int $timeoutSeconds = 60): mixed
    {
        $finalStatus = self::monitorTask($taskId, $timeoutSeconds);

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
     * Create a task monitoring dashboard data
     * 
     * @return array Dashboard data for monitoring interface
     */
    public static function getDashboardData(): array
    {
        $summary = self::getTasksSummary();
        $recentLogs = self::getRecentLogs(20);
        $activeTasks = array_filter(self::getAllTasksStatus(), function($task) {
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
     * Reset state (useful for testing)
     */
    public static function reset(): void
    {
        self::$globalHandler = null;
    }

    /**
     * Get defer statistics including background task info
     *
     * @return array Statistics about defer usage and environment
     */
    public static function getStats(): array
    {
        if (self::$globalHandler === null) {
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

        $stats = self::$globalHandler->getStats();
        $tasksSummary = self::getTasksSummary();
        
        $stats['background_tasks'] = [
            'total' => $tasksSummary['total_tasks'],
            'active' => $tasksSummary['running'] + $tasksSummary['pending'],
            'completed' => $tasksSummary['completed'],
            'failed' => $tasksSummary['failed']
        ];

        return $stats;
    }
}