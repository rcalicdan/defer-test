<?php

namespace Library\Defer\Utilities;

use Library\Defer\Defer;

/**
 * Utility class for awaiting multiple tasks with optional process pooling
 */
class TaskAwaiter
{
    /**
     * Wait for multiple tasks to complete and return their results
     * Fails fast on first error (like Promise.all())
     * 
     * @param array $taskIds Array of task IDs or callable tasks to wait for (keys are preserved in result)
     * @param int $timeoutSeconds Maximum time to wait for all tasks
     * @param int|null $maxConcurrentTasks Maximum concurrent processes (null = no limit)
     * @param int $pollIntervalMs Polling interval in milliseconds for process pool
     * @return array Task results with preserved keys, or null for failed tasks
     * @throws \RuntimeException If any task fails or times out
     */
    public static function awaitAll(
        array $taskIds, 
        int $timeoutSeconds = 60, 
        ?int $maxConcurrentTasks = null,
        int $pollIntervalMs = 100
    ): array {
        if (empty($taskIds)) {
            return [];
        }

        // Check if we have callables (need to execute) or task IDs (already running)
        $needsExecution = false;
        foreach ($taskIds as $item) {
            if (is_callable($item) || (is_array($item) && isset($item['callback']))) {
                $needsExecution = true;
                break;
            }
        }

        // If we have callables and a process pool limit, use the pool
        if ($needsExecution && $maxConcurrentTasks !== null) {
            return self::awaitAllWithPool($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs);
        }

        // If we have callables but no pool limit, execute them all immediately
        if ($needsExecution) {
            $actualTaskIds = [];
            foreach ($taskIds as $key => $item) {
                if (is_callable($item)) {
                    $actualTaskIds[$key] = Defer::background($item);
                } elseif (is_array($item) && isset($item['callback'])) {
                    $actualTaskIds[$key] = Defer::background($item['callback'], $item['context'] ?? []);
                } else {
                    $actualTaskIds[$key] = $item; // Assume it's already a task ID
                }
            }
            $taskIds = $actualTaskIds;
        }

        // Original implementation for task IDs
        return self::awaitTaskIds($taskIds, $timeoutSeconds);
    }

    /**
     * Wait for multiple tasks to complete and return all results (settled version)
     * Similar to JavaScript's Promise.allSettled()
     * 
     * @param array $taskIds Array of task IDs or callable tasks to wait for (keys are preserved in result)
     * @param int $timeoutSeconds Maximum time to wait for all tasks
     * @param int|null $maxConcurrentTasks Maximum concurrent processes (null = no limit)
     * @param int $pollIntervalMs Polling interval in milliseconds for process pool
     * @return array Task results with preserved keys. Each result has 'status' and either 'value' or 'reason'
     */
    public static function awaitAllSettled(
        array $taskIds, 
        int $timeoutSeconds = 60,
        ?int $maxConcurrentTasks = null,
        int $pollIntervalMs = 100
    ): array {
        if (empty($taskIds)) {
            return [];
        }

        // Check if we have callables (need to execute) or task IDs (already running)
        $needsExecution = false;
        foreach ($taskIds as $item) {
            if (is_callable($item) || (is_array($item) && isset($item['callback']))) {
                $needsExecution = true;
                break;
            }
        }

        // If we have callables and a process pool limit, use the pool
        if ($needsExecution && $maxConcurrentTasks !== null) {
            return self::awaitAllSettledWithPool($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs);
        }

        // If we have callables but no pool limit, execute them all immediately
        if ($needsExecution) {
            $actualTaskIds = [];
            foreach ($taskIds as $key => $item) {
                if (is_callable($item)) {
                    $actualTaskIds[$key] = Defer::background($item);
                } elseif (is_array($item) && isset($item['callback'])) {
                    $actualTaskIds[$key] = Defer::background($item['callback'], $item['context'] ?? []);
                } else {
                    $actualTaskIds[$key] = $item; // Assume it's already a task ID
                }
            }
            $taskIds = $actualTaskIds;
        }

        // Original implementation for task IDs
        return self::awaitTaskIdsSettled($taskIds, $timeoutSeconds);
    }

    /**
     * Await tasks using process pool (fail-fast version)
     */
    private static function awaitAllWithPool(
        array $tasks, 
        int $timeoutSeconds, 
        int $maxConcurrentTasks, 
        int $pollIntervalMs
    ): array {
        $pool = new ProcessPool($maxConcurrentTasks, $pollIntervalMs);
        
        // Convert callables to proper format
        $poolTasks = [];
        foreach ($tasks as $key => $item) {
            if (is_callable($item)) {
                $poolTasks[$key] = ['callback' => $item, 'context' => []];
            } elseif (is_array($item) && isset($item['callback'])) {
                $poolTasks[$key] = $item;
            } else {
                // Already a task ID, handle separately
                $poolTasks[$key] = ['task_id' => $item];
            }
        }

        // Execute tasks through pool
        $taskIds = $pool->executeTasks($poolTasks);

        // Wait for pool completion (ensures all tasks are at least started)
        $poolTimeout = min($timeoutSeconds, 60); // Don't wait too long for pool startup
        $pool->waitForCompletion($poolTimeout);

        // Now await all results
        return self::awaitTaskIds($taskIds, $timeoutSeconds);
    }

    /**
     * Await tasks using process pool (settled version)
     */
    private static function awaitAllSettledWithPool(
        array $tasks, 
        int $timeoutSeconds, 
        int $maxConcurrentTasks, 
        int $pollIntervalMs
    ): array {
        $pool = new ProcessPool($maxConcurrentTasks, $pollIntervalMs);
        
        // Convert callables to proper format
        $poolTasks = [];
        foreach ($tasks as $key => $item) {
            if (is_callable($item)) {
                $poolTasks[$key] = ['callback' => $item, 'context' => []];
            } elseif (is_array($item) && isset($item['callback'])) {
                $poolTasks[$key] = $item;
            } else {
                // Already a task ID, handle separately
                $poolTasks[$key] = ['task_id' => $item];
            }
        }

        // Execute tasks through pool
        $taskIds = $pool->executeTasks($poolTasks);

        // Wait for pool completion
        $poolTimeout = min($timeoutSeconds, 60);
        $pool->waitForCompletion($poolTimeout);

        // Now await all results (settled)
        return self::awaitTaskIdsSettled($taskIds, $timeoutSeconds);
    }

    /**
     * Original implementation for awaiting task IDs (fail-fast)
     */
    private static function awaitTaskIds(array $taskIds, int $timeoutSeconds): array
    {
        $startTime = time();
        $results = [];
        $completedTasks = [];
        $failedTasks = [];

        // Initialize results array with preserved keys
        foreach ($taskIds as $key => $taskId) {
            $results[$key] = null;
        }

        do {
            $allCompleted = true;
            
            foreach ($taskIds as $key => $taskId) {
                // Skip already processed tasks
                if (isset($completedTasks[$key]) || isset($failedTasks[$key])) {
                    continue;
                }

                $status = Defer::getTaskStatus($taskId);
                
                if ($status['status'] === 'COMPLETED') {
                    $results[$key] = $status['result'] ?? null;
                    $completedTasks[$key] = true;
                } elseif ($status['status'] === 'ERROR' || $status['status'] === 'NOT_FOUND') {
                    $failedTasks[$key] = $status;
                    $allCompleted = false;
                    break; // Exit early on first failure
                } else {
                    // Task is still pending or running
                    $allCompleted = false;
                }
            }

            // Check if any task failed
            if (!empty($failedTasks)) {
                $failedKey = array_key_first($failedTasks);
                $failedStatus = $failedTasks[$failedKey];
                $taskId = $taskIds[$failedKey];
                $errorMsg = $failedStatus['error_message'] ?? $failedStatus['message'];
                throw new \RuntimeException("Task {$taskId} (key: {$failedKey}) failed: {$errorMsg}");
            }

            // All tasks completed successfully
            if ($allCompleted) {
                return $results;
            }

            // Check timeout
            if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                $pendingTasks = [];
                foreach ($taskIds as $key => $taskId) {
                    if (!isset($completedTasks[$key])) {
                        $pendingTasks[] = "{$taskId} (key: {$key})";
                    }
                }
                $pendingList = implode(', ', $pendingTasks);
                throw new \RuntimeException("Tasks timed out after {$timeoutSeconds} seconds. Pending: {$pendingList}");
            }

            usleep(10000); // Wait 10ms before next check

        } while (true);
    }

    /**
     * Original implementation for awaiting task IDs (settled version)
     */
    private static function awaitTaskIdsSettled(array $taskIds, int $timeoutSeconds): array
    {
        $startTime = time();
        $results = [];
        $completedTasks = [];

        // Initialize results array with preserved keys
        foreach ($taskIds as $key => $taskId) {
            $results[$key] = null;
        }

        do {
            $allCompleted = true;
            
            foreach ($taskIds as $key => $taskId) {
                // Skip already processed tasks
                if (isset($completedTasks[$key])) {
                    continue;
                }

                $status = Defer::getTaskStatus($taskId);
                
                if ($status['status'] === 'COMPLETED') {
                    $results[$key] = [
                        'status' => 'fulfilled',
                        'value' => $status['result'] ?? null,
                        'task_id' => $taskId
                    ];
                    $completedTasks[$key] = true;
                } elseif ($status['status'] === 'ERROR' || $status['status'] === 'NOT_FOUND') {
                    $errorMsg = $status['error_message'] ?? $status['message'];
                    $results[$key] = [
                        'status' => 'rejected',
                        'reason' => $errorMsg,
                        'task_id' => $taskId
                    ];
                    $completedTasks[$key] = true;
                } else {
                    // Task is still pending or running
                    $allCompleted = false;
                }
            }

            // All tasks processed (completed or failed)
            if ($allCompleted) {
                return $results;
            }

            // Check timeout
            if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                foreach ($taskIds as $key => $taskId) {
                    if (!isset($completedTasks[$key])) {
                        $results[$key] = [
                            'status' => 'rejected',
                            'reason' => "Timeout after {$timeoutSeconds} seconds",
                            'task_id' => $taskId
                        ];
                    }
                }
                return $results;
            }

            usleep(10000); // Wait 10ms before next check

        } while (true);
    }
}