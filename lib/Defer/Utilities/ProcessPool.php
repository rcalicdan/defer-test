<?php

namespace Library\Defer\Utilities;

use Library\Defer\Defer;

/**
 * Process pool manager for limiting concurrent background tasks
 */
class ProcessPool
{
    private int $maxConcurrentTasks;
    private array $activeTasks = [];
    private array $queuedTasks = [];
    private int $pollIntervalMs;

    public function __construct(int $maxConcurrentTasks = 5, int $pollIntervalMs = 100)
    {
        $this->maxConcurrentTasks = max(1, $maxConcurrentTasks);
        $this->pollIntervalMs = max(10, $pollIntervalMs);
    }

    /**
     * Execute tasks with pool management
     * 
     * @param array $tasks Array of [callback, context] pairs with preserved keys
     * @return array Task IDs with preserved keys
     */
    public function executeTasks(array $tasks): array
    {
        $taskIds = [];

        // Queue all tasks
        foreach ($tasks as $key => $taskData) {
            $this->queuedTasks[] = [
                'key' => $key,
                'callback' => $taskData['callback'],
                'context' => $taskData['context'] ?? []
            ];
        }

        // Start initial batch
        $this->processQueue();

        // Get all task IDs
        foreach ($this->activeTasks as $task) {
            $taskIds[$task['key']] = $task['task_id'];
        }

        // Continue processing until all tasks are started
        while (!empty($this->queuedTasks)) {
            $this->checkCompletedTasks();
            $this->processQueue();
            usleep($this->pollIntervalMs * 1000);
        }

        // Collect remaining active task IDs
        foreach ($this->activeTasks as $task) {
            if (!isset($taskIds[$task['key']])) {
                $taskIds[$task['key']] = $task['task_id'];
            }
        }

        return $taskIds;
    }

    /**
     * Check for completed tasks and remove them from active pool
     */
    private function checkCompletedTasks(): void
    {
        foreach ($this->activeTasks as $index => $task) {
            $status = Defer::getTaskStatus($task['task_id']);

            if (in_array($status['status'], ['COMPLETED', 'ERROR', 'NOT_FOUND'])) {
                unset($this->activeTasks[$index]);
                $this->activeTasks = array_values($this->activeTasks); // Re-index
            }
        }
    }

    /**
     * Process queued tasks up to the pool limit
     */
    private function processQueue(): void
    {
        while (count($this->activeTasks) < $this->maxConcurrentTasks && !empty($this->queuedTasks)) {
            $task = array_shift($this->queuedTasks);

            try {
                $taskId = Defer::background($task['callback'], $task['context']);
                $this->activeTasks[] = [
                    'key' => $task['key'],
                    'task_id' => $taskId,
                    'started_at' => time()
                ];
            } catch (\Throwable $e) {
                // If task fails to start, we could either skip it or throw
                // For now, we'll skip and let the awaiter handle the missing task
                error_log("Failed to start pooled task: " . $e->getMessage());
            }
        }
    }

    /**
     * Get current pool statistics
     */
    public function getStats(): array
    {
        return [
            'max_concurrent' => $this->maxConcurrentTasks,
            'active_tasks' => count($this->activeTasks),
            'queued_tasks' => count($this->queuedTasks),
            'poll_interval_ms' => $this->pollIntervalMs
        ];
    }

    /**
     * Wait for all active tasks to complete (used internally)
     */
    public function waitForCompletion(int $timeoutSeconds = 0): bool
    {
        $startTime = time();

        while (!empty($this->activeTasks)) {
            $this->checkCompletedTasks();

            if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                return false;
            }

            usleep($this->pollIntervalMs * 1000);
        }

        return true;
    }
}
