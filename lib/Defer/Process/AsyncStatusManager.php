<?php

namespace Library\Defer\Process;

use Rcalicdan\FiberAsync\Api\File;
use Rcalicdan\FiberAsync\Promise\Promise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Async status manager using Promise-based file operations
 */
class AsyncStatusManager extends StatusManager
{
    private array $fileWatchers = [];
    
    /**
     * Get task status asynchronously
     */
    public function getTaskStatusAsync(string $taskId): PromiseInterface
    {
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.status';
        
        return File::exists($statusFile)
            ->then(function($exists) use ($statusFile, $taskId) {
                if (!$exists) {
                    return [
                        'task_id' => $taskId,
                        'status' => 'NOT_FOUND',
                        'message' => 'Task not found or status file missing',
                        'timestamp' => null
                    ];
                }
                
                return File::read($statusFile);
            })
            ->then(function($content) use ($taskId, $statusFile) {
                if (is_array($content)) {
                    // File didn't exist case
                    return $content;
                }
                
                $status = json_decode($content, true);
                
                if ($status === null) {
                    return File::stats($statusFile)
                        ->then(function($stats) use ($taskId) {
                            return [
                                'task_id' => $taskId,
                                'status' => 'CORRUPTED',
                                'message' => 'Status file corrupted',
                                'timestamp' => $stats['mtime'],
                                'created_at' => date('Y-m-d H:i:s', $stats['ctime']),
                                'updated_at' => date('Y-m-d H:i:s', $stats['mtime'])
                            ];
                        });
                }
                
                // Add file timestamps if missing
                if (!isset($status['file_created_at']) || !isset($status['file_modified_at'])) {
                    return File::stats($statusFile)
                        ->then(function($stats) use ($status) {
                            if (!isset($status['file_created_at'])) {
                                $status['file_created_at'] = date('Y-m-d H:i:s', $stats['ctime']);
                            }
                            if (!isset($status['file_modified_at'])) {
                                $status['file_modified_at'] = date('Y-m-d H:i:s', $stats['mtime']);
                            }
                            return $status;
                        });
                }
                
                return $status;
            });
    }
    
    /**
     * Watch task status file for changes
     */
    public function watchTaskStatus(string $taskId, callable $callback, array $options = []): string
    {
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.status';
        
        $watcherId = File::watch($statusFile, function($path, $event, $data) use ($callback, $taskId) {
            if ($event === 'modified' || $event === 'created') {
                $this->getTaskStatusAsync($taskId)
                    ->then(function($status) use ($callback, $taskId) {
                        $callback($taskId, $status);
                    })
                    ->catch(function($error) use ($taskId) {
                        error_log("Failed to read status for task {$taskId}: " . $error->getMessage());
                    });
            }
        }, array_merge([
            'events' => ['modify', 'create'],
            'debounce' => 0.1 // Debounce rapid changes
        ], $options));
        
        $this->fileWatchers[$taskId] = $watcherId;
        return $watcherId;
    }
    
    /**
     * Stop watching task status
     */
    public function unwatchTaskStatus(string $taskId): bool
    {
        if (isset($this->fileWatchers[$taskId])) {
            $watcherId = $this->fileWatchers[$taskId];
            $result = File::unwatch($watcherId);
            unset($this->fileWatchers[$taskId]);
            return $result;
        }
        return false;
    }
    
    /**
     * Create initial status asynchronously
     */
    public function createInitialStatusAsync(string $taskId, callable $callback, array $context): PromiseInterface
    {
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.status';
        
        $initialStatus = [
            'task_id' => $taskId,
            'status' => 'PENDING',
            'message' => 'Task created and queued for execution',
            'timestamp' => time(),
            'duration' => null,
            'memory_usage' => null,
            'memory_peak' => null,
            'pid' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'callback_type' => $this->getCallableType($callback),
            'context_size' => count($context)
        ];
        
        $jsonData = json_encode($initialStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return File::write($statusFile, $jsonData);
    }
    
    /**
     * Get all tasks status asynchronously
     */
    public function getAllTasksStatusAsync(): PromiseInterface
    {
        $pattern = $this->logDir . DIRECTORY_SEPARATOR . '*.status';
        $statusFiles = glob($pattern);
        
        if (empty($statusFiles)) {
            return Promise::resolved([]);
        }
        
        $promises = [];
        $taskIds = [];
        
        foreach ($statusFiles as $statusFile) {
            $taskId = basename($statusFile, '.status');
            $taskIds[] = $taskId;
            $promises[] = $this->getTaskStatusAsync($taskId);
        }
        
        return Promise::all($promises)
            ->then(function($statuses) use ($taskIds) {
                $tasks = [];
                foreach ($statuses as $index => $status) {
                    $tasks[$taskIds[$index]] = $status;
                }
                
                // Sort by creation time (newest first)
                uasort($tasks, function ($a, $b) {
                    return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
                });
                
                return $tasks;
            });
    }
}