<?php

namespace Library\Defer;

use Rcalicdan\FiberAsync\Promise\Promise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Library\Defer\Process\AsyncStatusManager;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;

/**
 * Enhanced process monitoring with real-time updates
 */
class AsyncProcess extends Process
{
    private static ?AsyncStatusManager $asyncStatusManager = null;
    
    private static function getAsyncStatusManager(): AsyncStatusManager
    {
        if (self::$asyncStatusManager === null) {
            $handler = self::getHandler();
            $reflection = new \ReflectionClass($handler);
            $logDirProperty = $reflection->getProperty('statusManager');
            $logDirProperty->setAccessible(true);
            $statusManager = $logDirProperty->getValue($handler);
            
            $logDirReflection = new \ReflectionClass($statusManager);
            $logDirProperty = $logDirReflection->getProperty('logDir');
            $logDirProperty->setAccessible(true);
            $logDir = $logDirProperty->getValue($statusManager);
            
            self::$asyncStatusManager = new AsyncStatusManager($logDir);
        }
        
        return self::$asyncStatusManager;
    }
    
    /**
     * Monitor a task with real-time updates using file watching
     */
    public static function monitorAsync(
        string $taskId, 
        int $timeoutSeconds = 30, 
        ?callable $progressCallback = null
    ): PromiseInterface {
        $startTime = time();
        $statusManager = self::getAsyncStatusManager();
        
        return new Promise(function($resolve, $reject) use ($taskId, $timeoutSeconds, $progressCallback, $startTime, $statusManager) {
            $lastStatus = null;
            $watcherId = null;
            
            // Set up timeout
            $timeoutTimer = null;
            if ($timeoutSeconds > 0) {
                $timeoutTimer = EventLoop::getInstance()->addTimer($timeoutSeconds, function() use ($reject, $taskId, $timeoutSeconds, &$watcherId, $statusManager) {
                    if ($watcherId) {
                        $statusManager->unwatchTaskStatus($taskId);
                    }
                    
                    $statusManager->getTaskStatusAsync($taskId)
                        ->then(function($status) use ($reject, $taskId, $timeoutSeconds) {
                            $reject(new \RuntimeException(
                                "Task {$taskId} monitoring timed out after {$timeoutSeconds} seconds. Last status: " . 
                                ($status['status'] ?? 'UNKNOWN') . " - " . ($status['message'] ?? 'No message')
                            ));
                        })
                        ->catch(function($error) use ($reject, $taskId, $timeoutSeconds) {
                            $reject(new \RuntimeException("Task {$taskId} timed out after {$timeoutSeconds} seconds"));
                        });
                });
            }
            
            // Watch for status changes
            $watcherId = $statusManager->watchTaskStatus($taskId, function($watchedTaskId, $status) use (
                $resolve, $reject, $progressCallback, &$lastStatus, &$watcherId, &$timeoutTimer, $statusManager
            ) {
                // Display output if available
                if (isset($status['output']) && !empty($status['output'])) {
                    echo $status['output'];
                }
                
                // Call progress callback if provided
                if ($progressCallback && $status !== $lastStatus) {
                    $progressCallback($status);
                    $lastStatus = $status;
                }
                
                // Check if task completed
                if (in_array($status['status'], ['COMPLETED', 'ERROR', 'NOT_FOUND'])) {
                    // Clean up
                    if ($watcherId) {
                        $statusManager->unwatchTaskStatus($watchedTaskId);
                        $watcherId = null;
                    }
                    if ($timeoutTimer) {
                        EventLoop::getInstance()->cancelTimer($timeoutTimer);
                    }
                    
                    $resolve($status);
                }
            });
            
            // Get initial status
            $statusManager->getTaskStatusAsync($taskId)
                ->then(function($initialStatus) use ($progressCallback, &$lastStatus) {
                    if ($progressCallback) {
                        $progressCallback($initialStatus);
                        $lastStatus = $initialStatus;
                    }
                    
                    // If already completed, resolve immediately
                    if (in_array($initialStatus['status'], ['COMPLETED', 'ERROR', 'NOT_FOUND'])) {
                        return $initialStatus;
                    }
                    
                    return null;
                })
                ->then(function($status) use ($resolve, &$watcherId, &$timeoutTimer, $statusManager, $taskId) {
                    if ($status !== null) {
                        // Task already completed
                        if ($watcherId) {
                            $statusManager->unwatchTaskStatus($taskId);
                            $watcherId = null;
                        }
                        if ($timeoutTimer) {
                            EventLoop::getInstance()->cancelTimer($timeoutTimer);
                        }
                        $resolve($status);
                    }
                })
                ->catch(function($error) use ($reject, &$watcherId, &$timeoutTimer, $statusManager, $taskId) {
                    if ($watcherId) {
                        $statusManager->unwatchTaskStatus($taskId);
                    }
                    if ($timeoutTimer) {
                        EventLoop::getInstance()->cancelTimer($timeoutTimer);
                    }
                    $reject($error);
                });
        });
    }
    
    /**
     * Await task with Promise-based implementation
     */
    public static function awaitAsync(string $taskId, int $timeoutSeconds = 60): PromiseInterface
    {
        return self::monitorAsync($taskId, $timeoutSeconds)
            ->then(function($finalStatus) use ($taskId) {
                if ($finalStatus['status'] === 'COMPLETED') {
                    return $finalStatus['result'] ?? null;
                }
                
                if ($finalStatus['status'] === 'ERROR') {
                    $errorMsg = $finalStatus['error_message'] ?? $finalStatus['message'];
                    throw new \RuntimeException("Task {$taskId} failed: {$errorMsg}");
                }
                
                throw new \RuntimeException("Task {$taskId} ended with unexpected status: " . $finalStatus['status']);
            });
    }
}