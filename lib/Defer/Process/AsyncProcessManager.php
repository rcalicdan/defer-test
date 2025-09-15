<?php

namespace Library\Defer\Process;

/**
 * Async-aware process manager using Promise-based file I/O
 */
class AsyncProcessManager extends ProcessManager
{
    /**
     * Generate background script with Promise-based file I/O and event loop
     */
    protected function generateBackgroundScript(
        string $taskId,
        string $serializedCallback,
        string $serializedContext,
        string $autoloadPath,
        string $statusFile,
        string $memoryLimit,
        int $timeout,
        array $frameworkInfo
    ): string {
        $generatedAt = date('Y-m-d H:i:s');
        $frameworkName = $frameworkInfo['name'] ?? 'none';
        $frameworkBootstrap = $frameworkInfo['bootstrap_file'] ?? '';
        $frameworkInitCode = $frameworkInfo['init_code'] ?? '';
        $escapedBootstrapFile = addslashes($frameworkBootstrap);

        return <<<PHP
<?php
/**
 * Auto-generated async background task script with Promise-based file I/O
 * Task ID: {$taskId}
 * Generated at: {$generatedAt}
 */

declare(strict_types=1);

// FORK BOMB PROTECTION
putenv('DEFER_BACKGROUND_PROCESS=1');
\$_ENV['DEFER_BACKGROUND_PROCESS'] = '1';

// Set execution environment
set_time_limit({$timeout});
error_reporting(E_ALL);
ini_set('memory_limit', '{$memoryLimit}');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

\$taskId = '{$taskId}';
\$statusFile = '{$statusFile}';
\$startTime = microtime(true);
\$pid = getmypid();
\$capturedOutput = '';

// Load autoloader first
if (file_exists('{$autoloadPath}')) {
    require_once '{$autoloadPath}';
} else {
    throw new RuntimeException('Autoloader not found at: {$autoloadPath}');
}

// Import required classes for async operations
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Api\File;
use Rcalicdan\FiberAsync\Promise\Promise;

// Initialize event loop
\$eventLoop = EventLoop::getInstance();
\$isEventLoopRunning = false;

// Promise-based status update function
function updateTaskStatusAsync(\$status, \$message = '', \$extra = []): Promise {
    global \$taskId, \$statusFile, \$startTime, \$pid, \$capturedOutput;
    
    \$statusData = array_merge([
        'task_id' => \$taskId,
        'status' => \$status,
        'message' => \$message,
        'timestamp' => time(),
        'duration' => microtime(true) - \$startTime,
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
        'pid' => \$pid,
        'created_at' => '{$generatedAt}',
        'updated_at' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
        'os_family' => PHP_OS_FAMILY
    ], \$extra);
    
    if (!empty(\$capturedOutput)) {
        \$statusData['output'] = \$capturedOutput;
    }
    
    \$jsonData = json_encode(\$statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    // Use async file write
    return File::write(\$statusFile, \$jsonData)
        ->then(function(\$bytesWritten) use (\$status, \$message) {
            return ['status' => \$status, 'message' => \$message, 'bytes_written' => \$bytesWritten];
        })
        ->catch(function(\$error) use (\$status, \$message) {
            error_log("Failed to update task status: " . \$error->getMessage());
            // Fallback to synchronous write
            global \$statusFile;
            file_put_contents(\$statusFile, json_encode([
                'status' => \$status,
                'message' => \$message,
                'error' => 'Async status update failed: ' . \$error->getMessage()
            ]));
            return ['status' => \$status, 'message' => \$message, 'fallback' => true];
        });
}

// Enhanced output capture with async file operations
function captureOutputAsync(\$buffer) {
    global \$capturedOutput;
    \$capturedOutput .= \$buffer;
    return \$buffer;
}

// Start event loop in background
function ensureEventLoopRunning() {
    global \$eventLoop, \$isEventLoopRunning;
    
    if (!\$isEventLoopRunning) {
        \$isEventLoopRunning = true;
        
        // Run event loop in a separate process/thread context
        \$eventLoop->nextTick(function() {
            // Keep event loop alive for async operations
        });
        
        // Start the event loop (non-blocking)
        register_shutdown_function(function() use (\$eventLoop) {
            if (\$eventLoop->isRunning()) {
                \$eventLoop->stop();
            }
        });
    }
}

// Main execution with Promise chaining
try {
    ensureEventLoopRunning();
    
    // Start with initial status update
    updateTaskStatusAsync('RUNNING', 'Task started execution')
        ->then(function() {
            // Load framework bootstrap if detected
            if ('{$frameworkName}' !== 'none' && '{$escapedBootstrapFile}' !== '') {
                \$bootstrapFile = '{$escapedBootstrapFile}';
                if (file_exists(\$bootstrapFile)) {
                    try {
                        {$frameworkInitCode}
                        return updateTaskStatusAsync('RUNNING', '{$frameworkName} framework bootstrap loaded successfully');
                    } catch (Throwable \$bootstrapError) {
                        return updateTaskStatusAsync('RUNNING', 'Framework bootstrap failed, continuing: ' . \$bootstrapError->getMessage());
                    }
                }
            }
            return Promise::resolve();
        })
        ->then(function() {
            // Restore context and callback
            global \$capturedOutput;
            \$context = {$serializedContext};
            \$callback = {$serializedCallback};
            
            if (!is_callable(\$callback)) {
                throw new RuntimeException('Deserialized callback is not callable');
            }
            
            // Start output buffering
            ob_start('captureOutputAsync');
            
            return updateTaskStatusAsync('RUNNING', 'Executing callback');
        })
        ->then(function() use (\$eventLoop) {
            // Execute the callback with Promise support
            global \$capturedOutput;
            \$context = {$serializedContext};
            \$callback = {$serializedCallback};
            
            \$reflection = new ReflectionFunction(\$callback instanceof Closure ? \$callback : Closure::fromCallable(\$callback));
            \$paramCount = \$reflection->getNumberOfParameters();
            
            try {
                \$result = \$paramCount > 0 && !empty(\$context) ? \$callback(\$context) : \$callback();
                
                // If result is a Promise, wait for it
                if (\$result instanceof \Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface) {
                    return \$result;
                }
                
                return Promise::resolve(\$result);
            } catch (Throwable \$callbackError) {
                ob_end_flush();
                throw \$callbackError;
            }
        })
        ->then(function(\$result) {
            // Flush output buffer
            ob_end_flush();
            
            global \$startTime, \$capturedOutput;
            \$duration = microtime(true) - \$startTime;
            
            \$resultInfo = [
                'execution_time' => \$duration,
                'memory_final' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true)
            ];
            
            if (\$result !== null) {
                \$resultInfo['result'] = \$result;
                \$resultInfo['result_type'] = gettype(\$result);
                
                // Handle large results
                if (is_string(\$result) && strlen(\$result) > 1000) {
                    \$resultInfo['result_truncated'] = true;
                    \$resultInfo['result'] = substr(\$result, 0, 1000) . '... (truncated)';
                    \$resultInfo['result_length'] = strlen(\$result);
                }
            }
            
            // Include output statistics
            if (!empty(\$capturedOutput)) {
                \$resultInfo['output_length'] = strlen(\$capturedOutput);
                \$resultInfo['output_lines'] = substr_count(\$capturedOutput, "\n") + 1;
            }
            
            return updateTaskStatusAsync('COMPLETED', "Task completed successfully in " . number_format(\$duration, 3) . " seconds", \$resultInfo);
        })
        ->catch(function(\$error) {
            // Ensure output buffer is cleaned up
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            global \$capturedOutput;
            \$errorInfo = [
                'error_message' => \$error->getMessage(),
                'error_file' => \$error->getFile(),
                'error_line' => \$error->getLine(),
                'error_code' => \$error->getCode(),
                'stack_trace' => \$error->getTraceAsString()
            ];
            
            if (!empty(\$capturedOutput)) {
                \$errorInfo['output'] = \$capturedOutput;
            }
            
            return updateTaskStatusAsync('ERROR', 'Task failed: ' . \$error->getMessage(), \$errorInfo);
        })
        ->finally(function() {
            // Clean up
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Clean up task file
            if (file_exists(__FILE__)) {
                @unlink(__FILE__);
            }
            
            global \$eventLoop;
            if (\$eventLoop->isRunning()) {
                \$eventLoop->stop();
            }
        });
    
    // Run the event loop to process all Promises
    \$eventLoop->run();
    
} catch (Throwable \$e) {
    // Fallback error handling
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    \$errorInfo = [
        'error_message' => \$e->getMessage(),
        'error_file' => \$e->getFile(),
        'error_line' => \$e->getLine(),
        'stack_trace' => \$e->getTraceAsString()
    ];
    
    if (!empty(\$capturedOutput)) {
        \$errorInfo['output'] = \$capturedOutput;
    }
    
    // Synchronous fallback for critical errors
    file_put_contents(\$statusFile, json_encode(array_merge([
        'task_id' => \$taskId,
        'status' => 'ERROR',
        'message' => 'Critical error: ' . \$e->getMessage(),
        'timestamp' => time()
    ], \$errorInfo)));
    
    error_log("❌ Critical background task error: " . \$e->getMessage());
    exit(1);
}

exit(0);
PHP;
    }
}