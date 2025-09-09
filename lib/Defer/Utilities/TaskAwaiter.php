<?php

namespace Library\Defer\Utilities;

use Library\Defer\Defer;

/**
 * Utility class for awaiting multiple tasks
 */
class TaskAwaiter
{
    /**
     * Wait for multiple tasks to complete and return their results
     * Fails fast on first error (like Promise.all())
     * 
     * @param array $taskIds Array of task IDs to wait for (keys are preserved in result)
     * @param int $timeoutSeconds Maximum time to wait for all tasks
     * @return array Task results with preserved keys, or null for failed tasks
     * @throws \RuntimeException If any task fails or times out
     */
    public static function awaitAll(array $taskIds, int $timeoutSeconds = 60): array
    {
        if (empty($taskIds)) {
            return [];
        }

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

            usleep(250000); // Wait 250ms before next check

        } while (true);
    }

    /**
     * Wait for multiple tasks to complete and return all results (settled version)
     * Similar to JavaScript's Promise.allSettled(), waits for all tasks to complete
     * and returns results for both successful and failed tasks without throwing exceptions.
     * 
     * @param array $taskIds Array of task IDs to wait for (keys are preserved in result)
     * @param int $timeoutSeconds Maximum time to wait for all tasks
     * @return array Task results with preserved keys. Each result has 'status' and either 'value' or 'reason'
     */
    public static function awaitAllSettled(array $taskIds, int $timeoutSeconds = 60): array
    {
        if (empty($taskIds)) {
            return [];
        }

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

            usleep(250000); // Wait 250ms before next check

        } while (true);
    }
}