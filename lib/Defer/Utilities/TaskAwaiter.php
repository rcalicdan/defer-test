<?php

namespace Library\Defer\Utilities;

use Library\Defer\Defer;

class TaskAwaiter
{
    /**
     * Wait for multiple tasks to complete and return their results
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

        // Check what types of tasks we have
        $hasLazyTasks = false;
        $hasCallables = false;
        
        foreach ($taskIds as $item) {
            if (is_string($item) && LazyTask::isLazyId($item)) {
                $hasLazyTasks = true;
            } elseif (is_callable($item) || (is_array($item) && isset($item['callback']))) {
                $hasCallables = true;
            }
        }

        // If we have lazy tasks or callables and a process pool limit, use the pool
        if (($hasLazyTasks || $hasCallables) && $maxConcurrentTasks !== null) {
            return self::awaitAllWithPool($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs);
        }

        // If we have lazy tasks or callables but no pool limit, execute them all immediately
        if ($hasLazyTasks || $hasCallables) {
            $actualTaskIds = [];
            foreach ($taskIds as $key => $item) {
                if (is_string($item) && LazyTask::isLazyId($item)) {
                    $lazyTask = LazyTask::get($item);
                    if ($lazyTask) {
                        $actualTaskIds[$key] = $lazyTask->execute();
                    } else {
                        throw new \RuntimeException("Lazy task not found: {$item}");
                    }
                } elseif (is_callable($item)) {
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
     * Await tasks using process pool (handles lazy tasks properly)
     */
    private static function awaitAllWithPool(
        array $tasks, 
        int $timeoutSeconds, 
        int $maxConcurrentTasks, 
        int $pollIntervalMs
    ): array {
        $pool = new ProcessPool($maxConcurrentTasks, $pollIntervalMs);
        
        // Convert all items to pool-compatible format
        $poolTasks = [];
        foreach ($tasks as $key => $item) {
            if (is_string($item) && LazyTask::isLazyId($item)) {
                $lazyTask = LazyTask::get($item);
                if ($lazyTask) {
                    $poolTasks[$key] = [
                        'callback' => function() use ($lazyTask) {
                            // Get the original callback from the lazy task
                            $reflection = new \ReflectionClass($lazyTask);
                            $callbackProp = $reflection->getProperty('callback');
                            $callbackProp->setAccessible(true);
                            $callback = $callbackProp->getValue($lazyTask);
                            
                            return call_user_func($callback);
                        },
                        'context' => $lazyTask->getContext()
                    ];
                } else {
                    throw new \RuntimeException("Lazy task not found: {$item}");
                }
            } elseif (is_callable($item)) {
                $poolTasks[$key] = ['callback' => $item, 'context' => []];
            } elseif (is_array($item) && isset($item['callback'])) {
                $poolTasks[$key] = $item;
            } else {
                // Already a task ID, handle separately - but this shouldn't happen in pool mode
                throw new \RuntimeException("Cannot mix lazy/callable tasks with existing task IDs in pool mode");
            }
        }

        // Execute tasks through pool (this respects the concurrent limit)
        $taskIds = $pool->executeTasks($poolTasks);

        // Wait for pool completion
        $poolTimeout = min($timeoutSeconds, 60);
        if (!$pool->waitForCompletion($poolTimeout)) {
            throw new \RuntimeException("Pool execution timed out during startup phase");
        }

        // Now await all results
        return self::awaitTaskIds($taskIds, $timeoutSeconds);
    }

    // Remove the resolveLazyTasks method since we're handling it differently now
    // Keep the original awaitTaskIds method unchanged
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

    // Keep awaitAllSettled implementation similar but with lazy task handling
    public static function awaitAllSettled(
        array $taskIds, 
        int $timeoutSeconds = 60,
        ?int $maxConcurrentTasks = null,
        int $pollIntervalMs = 100
    ): array {
        if (empty($taskIds)) {
            return [];
        }

        // Check what types of tasks we have
        $hasLazyTasks = false;
        $hasCallables = false;
        
        foreach ($taskIds as $item) {
            if (is_string($item) && LazyTask::isLazyId($item)) {
                $hasLazyTasks = true;
            } elseif (is_callable($item) || (is_array($item) && isset($item['callback']))) {
                $hasCallables = true;
            }
        }

        // Use same logic as awaitAll but with settled behavior
        if (($hasLazyTasks || $hasCallables) && $maxConcurrentTasks !== null) {
            return self::awaitAllSettledWithPool($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs);
        }

        if ($hasLazyTasks || $hasCallables) {
            $actualTaskIds = [];
            foreach ($taskIds as $key => $item) {
                if (is_string($item) && LazyTask::isLazyId($item)) {
                    $lazyTask = LazyTask::get($item);
                    if ($lazyTask) {
                        $actualTaskIds[$key] = $lazyTask->execute();
                    } else {
                        throw new \RuntimeException("Lazy task not found: {$item}");
                    }
                } elseif (is_callable($item)) {
                    $actualTaskIds[$key] = Defer::background($item);
                } elseif (is_array($item) && isset($item['callback'])) {
                    $actualTaskIds[$key] = Defer::background($item['callback'], $item['context'] ?? []);
                } else {
                    $actualTaskIds[$key] = $item;
                }
            }
            $taskIds = $actualTaskIds;
        }

        return self::awaitTaskIdsSettled($taskIds, $timeoutSeconds);
    }

    private static function awaitAllSettledWithPool(
        array $tasks, 
        int $timeoutSeconds, 
        int $maxConcurrentTasks, 
        int $pollIntervalMs
    ): array {
        // Same implementation as awaitAllWithPool but use awaitTaskIdsSettled at the end
        $pool = new ProcessPool($maxConcurrentTasks, $pollIntervalMs);
        
        $poolTasks = [];
        foreach ($tasks as $key => $item) {
            if (is_string($item) && LazyTask::isLazyId($item)) {
                $lazyTask = LazyTask::get($item);
                if ($lazyTask) {
                    $poolTasks[$key] = [
                        'callback' => function() use ($lazyTask) {
                            $reflection = new \ReflectionClass($lazyTask);
                            $callbackProp = $reflection->getProperty('callback');
                            $callbackProp->setAccessible(true);
                            $callback = $callbackProp->getValue($lazyTask);
                            
                            return call_user_func($callback);
                        },
                        'context' => $lazyTask->getContext()
                    ];
                } else {
                    throw new \RuntimeException("Lazy task not found: {$item}");
                }
            } elseif (is_callable($item)) {
                $poolTasks[$key] = ['callback' => $item, 'context' => []];
            } elseif (is_array($item) && isset($item['callback'])) {
                $poolTasks[$key] = $item;
            } else {
                throw new \RuntimeException("Cannot mix lazy/callable tasks with existing task IDs in pool mode");
            }
        }

        $taskIds = $pool->executeTasks($poolTasks);
        $poolTimeout = min($timeoutSeconds, 60);
        $pool->waitForCompletion($poolTimeout);

        return self::awaitTaskIdsSettled($taskIds, $timeoutSeconds);
    }

    private static function awaitTaskIdsSettled(array $taskIds, int $timeoutSeconds): array
    {
        // Keep your existing implementation
        $startTime = time();
        $results = [];
        $completedTasks = [];

        foreach ($taskIds as $key => $taskId) {
            $results[$key] = null;
        }

        do {
            $allCompleted = true;
            
            foreach ($taskIds as $key => $taskId) {
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
                    $allCompleted = false;
                }
            }

            if ($allCompleted) {
                return $results;
            }

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

            usleep(10000);

        } while (true);
    }
}