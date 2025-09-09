<?php

namespace Library\Defer\Handlers;

use Library\Defer\Serialization\CallbackSerializationManager;
use Library\Defer\Serialization\SerializationException;

/**
 * Handles background process execution for deferred tasks with comprehensive logging and monitoring
 */
/**
 * Handles background process execution for deferred tasks with comprehensive logging and monitoring
 */
class BackgroundProcessExecutorHandler
{
    private CallbackSerializationManager $serializationManager;
    private string $tempDir;
    private string $logDir;
    private string $logFile;
    private bool $enableDetailedLogging;
    private array $taskRegistry = [];

    private array $frameworkInfo = [];

    public function __construct(
        ?CallbackSerializationManager $serializationManager = null,
        bool $enableDetailedLogging = true,
        ?string $customLogDir = null
    ) {
        $this->serializationManager = $serializationManager ?: new CallbackSerializationManager();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'defer_tasks';
        $this->logDir = $customLogDir ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'defer_logs');
        $this->logFile = $this->logDir . DIRECTORY_SEPARATOR . 'background_tasks.log';

        $this->enableDetailedLogging = $enableDetailedLogging;
        $this->frameworkInfo = $this->detectFramework();

        $this->ensureDirectories();
        $this->initializeLogging();

        if ($this->enableDetailedLogging) {
            $this->logEvent('INFO', 'Detected framework: ' . ($this->frameworkInfo['name'] ?? 'none'));
        }
    }

    /**
     * Execute callback in a true background process with comprehensive logging
     *
     * @param callable $callback The callback to execute
     * @param array $context Additional context data
     * @return string Task ID for monitoring
     * @throws SerializationException If serialization fails
     * @throws \RuntimeException If background execution fails
     */
    public function execute(callable $callback, array $context = []): string
    {
        $this->validateSerialization($callback, $context);

        $taskId = $this->generateTaskId();
        $taskFile = $this->tempDir . DIRECTORY_SEPARATOR . $taskId . '.php';
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.status';

        // Register task in registry
        $this->registerTask($taskId, $callback, $context, $statusFile);

        try {
            $this->createBackgroundTaskScript($taskFile, $callback, $context, $taskId, $statusFile);
            $this->spawnBackgroundProcess($taskFile);

            $this->logTaskEvent($taskId, 'SPAWNED', 'Background process spawned successfully');

            return $taskId;
        } catch (\Throwable $e) {
            $this->logTaskEvent($taskId, 'ERROR', 'Failed to spawn background process: ' . $e->getMessage());

            // Update status file with error
            $errorStatus = [
                'task_id' => $taskId,
                'status' => 'SPAWN_ERROR',
                'message' => 'Failed to spawn background process: ' . $e->getMessage(),
                'timestamp' => time(),
                'duration' => null,
                'memory_usage' => null,
                'pid' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine()
            ];
            file_put_contents($statusFile, json_encode($errorStatus, JSON_PRETTY_PRINT));

            throw $e;
        }
    }

    /**
     * Check if callback and context can be serialized for background execution
     *
     * @param callable $callback The callback to check
     * @param array $context The context to check
     * @return bool True if both can be serialized
     */
    public function canExecute(callable $callback, array $context = []): bool
    {
        return $this->serializationManager->canSerializeCallback($callback) &&
            (empty($context) || $this->serializationManager->canSerializeContext($context));
    }

    /**
     * Get the status of a background task
     *
     * @param string $taskId Task ID to check
     * @return array Task status information
     */
    public function getTaskStatus(string $taskId): array
    {
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.status';

        if (!file_exists($statusFile)) {
            return [
                'task_id' => $taskId,
                'status' => 'NOT_FOUND',
                'message' => 'Task not found or status file missing',
                'timestamp' => null,
                'duration' => null,
                'memory_usage' => null,
                'pid' => null,
                'created_at' => null,
                'updated_at' => null
            ];
        }

        $statusContent = file_get_contents($statusFile);
        $status = json_decode($statusContent, true);

        if ($status === null) {
            return [
                'task_id' => $taskId,
                'status' => 'CORRUPTED',
                'message' => 'Status file corrupted',
                'timestamp' => filemtime($statusFile),
                'duration' => null,
                'memory_usage' => null,
                'pid' => null,
                'created_at' => date('Y-m-d H:i:s', filemtime($statusFile)),
                'updated_at' => date('Y-m-d H:i:s', filemtime($statusFile))
            ];
        }

        // Add file timestamps if missing
        if (!isset($status['file_created_at'])) {
            $status['file_created_at'] = date('Y-m-d H:i:s', filectime($statusFile));
        }
        if (!isset($status['file_modified_at'])) {
            $status['file_modified_at'] = date('Y-m-d H:i:s', filemtime($statusFile));
        }

        return $status;
    }

    /**
     * Get status of all active background tasks
     *
     * @return array All tasks with their status
     */
    public function getAllTasksStatus(): array
    {
        $tasks = [];
        $pattern = $this->logDir . DIRECTORY_SEPARATOR . '*.status';

        foreach (glob($pattern) as $statusFile) {
            $taskId = basename($statusFile, '.status');
            $tasks[$taskId] = $this->getTaskStatus($taskId);
        }

        // Sort by creation time (newest first)
        uasort($tasks, function ($a, $b) {
            return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
        });

        return $tasks;
    }

    /**
     * Monitor tasks and return summary statistics
     *
     * @return array Summary statistics
     */
    public function getTasksSummary(): array
    {
        $allTasks = $this->getAllTasksStatus();
        $summary = [
            'total_tasks' => count($allTasks),
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'pending' => 0,
            'unknown' => 0,
            'oldest_task' => null,
            'newest_task' => null,
            'total_execution_time' => 0,
            'average_execution_time' => 0,
            'longest_execution_time' => 0,
            'shortest_execution_time' => null,
            'total_memory_usage' => 0,
            'average_memory_usage' => 0,
            'peak_memory_usage' => 0
        ];

        $timestamps = [];
        $executionTimes = [];
        $memoryUsages = [];

        foreach ($allTasks as $task) {
            switch ($task['status']) {
                case 'RUNNING':
                    $summary['running']++;
                    break;
                case 'COMPLETED':
                    $summary['completed']++;
                    if ($task['duration']) {
                        $executionTimes[] = $task['duration'];
                        $summary['longest_execution_time'] = max($summary['longest_execution_time'], $task['duration']);
                        $summary['shortest_execution_time'] = $summary['shortest_execution_time'] === null
                            ? $task['duration']
                            : min($summary['shortest_execution_time'], $task['duration']);
                    }
                    break;
                case 'ERROR':
                case 'FAILED':
                case 'SPAWN_ERROR':
                    $summary['failed']++;
                    break;
                case 'PENDING':
                    $summary['pending']++;
                    break;
                default:
                    $summary['unknown']++;
            }

            if ($task['timestamp']) {
                $timestamps[] = $task['timestamp'];
            }

            if ($task['memory_usage']) {
                $memoryUsages[] = $task['memory_usage'];
                $summary['peak_memory_usage'] = max($summary['peak_memory_usage'], $task['memory_usage']);
            }
        }

        if (!empty($timestamps)) {
            $summary['oldest_task'] = date('Y-m-d H:i:s', min($timestamps));
            $summary['newest_task'] = date('Y-m-d H:i:s', max($timestamps));
        }

        if (!empty($executionTimes)) {
            $summary['total_execution_time'] = array_sum($executionTimes);
            $summary['average_execution_time'] = $summary['total_execution_time'] / count($executionTimes);
        }

        if (!empty($memoryUsages)) {
            $summary['total_memory_usage'] = array_sum($memoryUsages);
            $summary['average_memory_usage'] = $summary['total_memory_usage'] / count($memoryUsages);
        }

        return $summary;
    }

    /**
     * Clean up old task logs and status files
     *
     * @param int $maxAgeHours Maximum age in hours before cleanup
     * @return int Number of files cleaned up
     */
    public function cleanupOldTasks(int $maxAgeHours = 24): int
    {
        $cutoffTime = time() - ($maxAgeHours * 3600);
        $cleanedCount = 0;

        // Clean up status files
        $statusFiles = glob($this->logDir . DIRECTORY_SEPARATOR . '*.status');
        foreach ($statusFiles as $file) {
            if (filemtime($file) < $cutoffTime) {
                // Check if task is still running before cleanup
                $status = json_decode(file_get_contents($file), true);
                if ($status && $status['status'] === 'RUNNING') {
                    // Skip running tasks
                    continue;
                }

                if (unlink($file)) {
                    $cleanedCount++;
                }
            }
        }

        // Clean up task files (in case they weren't self-deleted)
        $taskFiles = glob($this->tempDir . DIRECTORY_SEPARATOR . 'defer_*.php');
        foreach ($taskFiles as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $cleanedCount++;
                }
            }
        }

        $this->logEvent('CLEANUP', "Cleaned up {$cleanedCount} old task files (older than {$maxAgeHours} hours)");
        return $cleanedCount;
    }

    /**
     * Get recent log entries for monitoring
     *
     * @param int $limit Maximum number of entries to return
     * @return array Recent log entries
     */
    public function getRecentLogs(int $limit = 100): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $logs = [];
        $recentLines = array_slice($lines, -$limit);

        foreach ($recentLines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[([^\]]+)\] \[([^\]]+)\] (.+)$/', $line, $matches)) {
                $logs[] = [
                    'timestamp' => $matches[1],
                    'level' => $matches[2],
                    'task_id' => $matches[3] !== 'SYSTEM' ? $matches[3] : null,
                    'message' => $matches[4],
                    'raw_line' => $line
                ];
            }
        }

        return $logs;
    }

    /**
     * Test background execution capabilities
     *
     * @param bool $verbose Whether to output detailed information
     * @return array Test results
     */
    public function testCapabilities(bool $verbose = false): array
    {
        $results = [
            'success' => false,
            'errors' => [],
            'stats' => [],
        ];

        try {
            if ($verbose) {
                echo "🧪 Testing background execution capabilities...\n";
                echo "Environment: " . PHP_SAPI . " on " . PHP_OS_FAMILY . "\n";
            }

            // Test basic callback serialization
            $testCallback = function () {
                $testFile = sys_get_temp_dir() . '/defer_test_' . uniqid() . '.txt';
                file_put_contents($testFile, 'Background execution test: ' . date('Y-m-d H:i:s'));
                return $testFile;
            };

            if ($this->serializationManager->canSerializeCallback($testCallback)) {
                if ($verbose) {
                    echo "✅ Callback serialization: OK\n";
                }
                $results['stats']['callback_serialization'] = true;
            } else {
                $results['errors'][] = 'Callback serialization failed';
                if ($verbose) {
                    echo "❌ Callback serialization: FAILED\n";
                }
                return $results;
            }

            // Test context serialization
            $testContext = ['test' => true, 'timestamp' => time(), 'data' => ['nested' => 'value']];
            if ($this->serializationManager->canSerializeContext($testContext)) {
                if ($verbose) {
                    echo "✅ Context serialization: OK\n";
                }
                $results['stats']['context_serialization'] = true;
            } else {
                $results['errors'][] = 'Context serialization failed';
                if ($verbose) {
                    echo "❌ Context serialization: FAILED\n";
                }
            }

            // Test actual background execution
            if ($verbose) {
                echo "🚀 Testing actual background execution...\n";
            }

            try {
                $taskId = $this->execute($testCallback, $testContext);
                if ($verbose) {
                    echo "✅ Background execution started: Task ID {$taskId}\n";
                }
                $results['stats']['background_execution'] = true;
                $results['stats']['test_task_id'] = $taskId;
            } catch (\Throwable $e) {
                $results['errors'][] = 'Background execution test failed: ' . $e->getMessage();
                if ($verbose) {
                    echo "❌ Background execution: FAILED - " . $e->getMessage() . "\n";
                }
            }

            $results['success'] = empty($results['errors']);
            $results['stats']['php_binary'] = $this->getPhpBinary();
            $results['stats']['temp_dir'] = $this->tempDir;
            $results['stats']['log_dir'] = $this->logDir;
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
            if ($verbose) {
                echo "❌ Test failed: " . $e->getMessage() . "\n";
            }
        }

        return $results;
    }

    /**
     * Get background execution statistics
     *
     * @return array Statistics about background execution capabilities
     */
    public function getStats(): array
    {
        $diskUsage = $this->getDiskUsage();

        return [
            'temp_dir' => $this->tempDir,
            'log_dir' => $this->logDir,
            'php_binary' => $this->getPhpBinary(),
            'logging_enabled' => $this->enableDetailedLogging,
            'framework' => $this->frameworkInfo,
            'serialization' => [
                'available_serializers' => $this->serializationManager->getSerializerInfo(),
            ],
            'environment' => [
                'os_family' => PHP_OS_FAMILY,
                'sapi' => PHP_SAPI,
                'php_version' => PHP_VERSION,
            ],
            'disk_usage' => $diskUsage,
            'registry' => [
                'tracked_tasks' => count($this->taskRegistry),
            ]
        ];
    }

    /**
     * Generate unique task ID with timestamp
     *
     * @return string Unique task identifier
     */
    private function generateTaskId(): string
    {
        return 'defer_' . date('Ymd_His') . '_' . uniqid('', true);
    }

    /**
     * Register task in internal registry
     *
     * @param string $taskId Task identifier
     * @param callable $callback The callback being executed
     * @param array $context Context data
     * @param string $statusFile Path to status file
     */
    private function registerTask(string $taskId, callable $callback, array $context, string $statusFile): void
    {
        $this->taskRegistry[$taskId] = [
            'created_at' => time(),
            'callback_type' => $this->getCallableType($callback),
            'context_size' => count($context),
            'status_file' => $statusFile
        ];

        // Write initial status
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

        file_put_contents($statusFile, json_encode($initialStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get callable type for logging
     *
     * @param callable $callback The callable to analyze
     * @return string Type description
     */
    private function getCallableType(callable $callback): string
    {
        if (is_string($callback)) {
            return 'function';
        } elseif (is_array($callback)) {
            return 'method';
        } elseif ($callback instanceof \Closure) {
            return 'closure';
        } else {
            return 'callable_object';
        }
    }

    /**
     * Get disk usage information
     *
     * @return array Disk usage statistics
     */
    private function getDiskUsage(): array
    {
        $tempDirSize = $this->getDirectorySize($this->tempDir);
        $logDirSize = $this->getDirectorySize($this->logDir);

        return [
            'temp_dir_size' => $tempDirSize,
            'log_dir_size' => $logDirSize,
            'total_size' => $tempDirSize + $logDirSize,
            'temp_dir_files' => count(glob($this->tempDir . DIRECTORY_SEPARATOR . '*')),
            'log_dir_files' => count(glob($this->logDir . DIRECTORY_SEPARATOR . '*')),
        ];
    }

    /**
     * Calculate directory size recursively
     *
     * @param string $directory Directory path
     * @return int Size in bytes
     */
    private function getDirectorySize(string $directory): int
    {
        $size = 0;
        if (is_dir($directory)) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT) as $file) {
                $size += is_file($file) ? filesize($file) : $this->getDirectorySize($file);
            }
        }
        return $size;
    }

    /**
     * Validate that callback and context can be serialized
     *
     * @param callable $callback The callback to validate
     * @param array $context The context to validate
     * @throws SerializationException If validation fails
     */
    private function validateSerialization(callable $callback, array $context): void
    {
        if (!$this->serializationManager->canSerializeCallback($callback)) {
            throw new SerializationException('Callback cannot be serialized for background execution');
        }

        if (!empty($context) && !$this->serializationManager->canSerializeContext($context)) {
            throw new SerializationException('Context cannot be serialized for background execution');
        }
    }

    /**
     * Ensure all necessary directories exist
     */
    private function ensureDirectories(): void
    {
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Initialize logging system
     */
    private function initializeLogging(): void
    {
        if ($this->enableDetailedLogging) {
            $this->logEvent('INFO', 'Background process executor initialized - PHP ' . PHP_VERSION . ' on ' . PHP_OS_FAMILY);
        }
    }

    /**
     * Create a PHP script file for background execution
     *
     * @param string $taskFile Path to the task file
     * @param callable $callback The callback to serialize
     * @param array $context Additional context data
     * @param string $taskId Task identifier
     * @param string $statusFile Path to status file
     * @throws \RuntimeException If script creation fails
     */
    private function createBackgroundTaskScript(
        string $taskFile,
        callable $callback,
        array $context,
        string $taskId,
        string $statusFile
    ): void {
        try {
            $serializedCallback = $this->serializationManager->serializeCallback($callback);
            $serializedContext = $this->serializationManager->serializeContext($context);
        } catch (SerializationException $e) {
            throw new \RuntimeException('Failed to serialize callback or context: ' . $e->getMessage(), 0, $e);
        }

        $autoloadPath = $this->findAutoloadPath();
        $script = $this->generateBackgroundScript($taskId, $serializedCallback, $serializedContext, $autoloadPath, $statusFile);

        if (file_put_contents($taskFile, $script) === false) {
            throw new \RuntimeException("Failed to create background task file: {$taskFile}");
        }

        chmod($taskFile, 0755);
    }

    /**
     * Generate the background script template with comprehensive monitoring
     *
     * @param string $taskId Unique task identifier
     * @param string $serializedCallback Serialized callback code
     * @param string $serializedContext Serialized context code
     * @param string $autoloadPath Path to autoloader
     * @param string $statusFile Path to status file
     * @return string Complete PHP script content
     */
    private function generateBackgroundScript(
        string $taskId,
        string $serializedCallback,
        string $serializedContext,
        string $autoloadPath,
        string $statusFile,
        ?string $laravelBootstrapFile = null
    ): string {
        $generatedAt = date('Y-m-d H:i:s');
        $frameworkName = $this->frameworkInfo['name'] ?? 'none';
        $frameworkBootstrap = $this->frameworkInfo['bootstrap_file'] ?? '';
        $frameworkInitCode = $this->frameworkInfo['init_code'] ?? '';
        $escapedBootstrapFile = addslashes($frameworkBootstrap);

        return <<<PHP
<?php
/**
 * Auto-generated background task script with comprehensive monitoring
 * Task ID: {$taskId}
 * Generated at: {$generatedAt}
 * Status file: {$statusFile}
 * PHP SAPI: <?= PHP_SAPI ?>
 * Background execution enabled
 */

declare(strict_types=1);

// Set execution environment
set_time_limit(0);
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

\$taskId = '{$taskId}';
\$statusFile = '{$statusFile}';
\$startTime = microtime(true);
\$pid = getmypid();

/**
 * Update task status with comprehensive information
 */
function updateTaskStatus(\$status, \$message = '', \$extra = []) {
    global \$taskId, \$statusFile, \$startTime, \$pid;
    
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
    
    if (file_put_contents(\$statusFile, json_encode(\$statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        error_log("❌ Failed to update status file: {\$statusFile}");
    }
}

/**
 * Log error with full context
 */
function logError(\$e) {
    global \$taskId;
    
    \$errorInfo = [
        'error_message' => \$e->getMessage(),
        'error_file' => \$e->getFile(),
        'error_line' => \$e->getLine(),
        'error_code' => \$e->getCode(),
        'stack_trace' => \$e->getTraceAsString()
    ];
    
    updateTaskStatus('ERROR', 'Task failed: ' . \$e->getMessage(), \$errorInfo);
    
    error_log("❌ Background task {\$taskId} error: " . \$e->getMessage() . " in " . \$e->getFile() . ":" . \$e->getLine());
    error_log("📄 Stack trace: " . \$e->getTraceAsString());
}

try {
    updateTaskStatus('RUNNING', 'Task started execution');
    error_log("🚀 Background task {\$taskId} started at " . date('Y-m-d H:i:s.u') . " (PID: {\$pid})");
    
    // Load autoloader
    if (file_exists('{$autoloadPath}')) {
         require_once '{$autoloadPath}';
        updateTaskStatus('RUNNING', 'Autoloader loaded successfully');
    } else {
        throw new RuntimeException('Autoloader not found at: {$autoloadPath}');
    }

    // Load framework bootstrap if detected
    \$frameworkLoaded = false;
    if ('{$frameworkName}' !== 'none' && '{$escapedBootstrapFile}' !== '') {
        \$bootstrapFile = '{$escapedBootstrapFile}';
        
        if (file_exists(\$bootstrapFile)) {
            try {
                {$frameworkInitCode}
                \$frameworkLoaded = true;
                updateTaskStatus('RUNNING', '{$frameworkName} framework bootstrap loaded successfully');
            } catch (Throwable \$bootstrapError) {
                error_log("⚠️ Framework bootstrap failed: " . \$bootstrapError->getMessage());
                updateTaskStatus('RUNNING', 'Framework bootstrap failed, continuing without framework: ' . \$bootstrapError->getMessage());
            }
        } else {
            updateTaskStatus('RUNNING', 'Framework bootstrap file not found, continuing without framework');
        }
    }
    
    // Load session management if available (for framework integration)
    \$sessionLoaded = false;
    \$possibleSessionPaths = [
        dirname(__DIR__, 2) . '/session.php',
        dirname(__DIR__, 3) . '/session.php',
        dirname(\$_SERVER['SCRIPT_FILENAME'] ?? __FILE__) . '/session.php',
    ];
    
    foreach (\$possibleSessionPaths as \$sessionFile) {
        if (file_exists(\$sessionFile)) {
            require_once \$sessionFile;
            \$sessionLoaded = true;
            break;
        }
    }
    
    updateTaskStatus('RUNNING', 'Dependencies loaded, executing callback' . (\$sessionLoaded ? ' (with session)' : ''));
    
    // Restore context and callback
    \$context = {$serializedContext};
    \$callback = {$serializedCallback};
    
    if (!is_callable(\$callback)) {
        throw new RuntimeException('Deserialized callback is not callable');
    }
    
    // Execute the callback with proper parameter handling
    \$reflection = new ReflectionFunction(\$callback instanceof Closure ? \$callback : Closure::fromCallable(\$callback));
    \$paramCount = \$reflection->getNumberOfParameters();
    
    updateTaskStatus('RUNNING', "Executing callback ({\$paramCount} parameters expected)");
    
    \$result = null;
    if (\$paramCount > 0 && !empty(\$context)) {
        \$result = \$callback(\$context);
    } else {
        \$result = \$callback();
    }
    
    \$duration = microtime(true) - \$startTime;
    \$resultInfo = [
        'execution_time' => \$duration,
        'memory_final' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true)
    ];
    
    if (\$result !== null) {
        \$resultStr = is_scalar(\$result) ? (string)\$result : json_encode(\$result, JSON_UNESCAPED_SLASHES);
        \$resultInfo['result'] = \$result;
        \$resultInfo['result_string'] = \$resultStr;
        \$resultInfo['result_type'] = gettype(\$result);
        error_log("📋 Task {\$taskId} result: " . \$resultStr);
    }
    
    updateTaskStatus('COMPLETED', "Task completed successfully in " . number_format(\$duration, 3) . " seconds", \$resultInfo);
    error_log("✅ Background task {\$taskId} completed in " . number_format(\$duration, 3) . " seconds");
    
} catch (Throwable \$e) {
    logError(\$e);
    exit(1);
} finally {
    // Clean up task file
    if (file_exists(__FILE__)) {
        \$deleted = @unlink(__FILE__);
        if (!\$deleted) {
            error_log("⚠️  Failed to delete task file: " . __FILE__);
        }
    }
    
    error_log("🧹 Background task {\$taskId} cleanup completed");
}

exit(0);
PHP;
    }

    /**
     * Find the autoload path with multiple fallback strategies
     *
     * @return string Path to autoload file
     */
    private function findAutoloadPath(): string
    {
        $possiblePaths = [
            __DIR__ . '/../../../../vendor/autoload.php',
            __DIR__ . '/../../../vendor/autoload.php',
            __DIR__ . '/../../vendor/autoload.php',
            __DIR__ . '/../vendor/autoload.php',
            dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__) . '/vendor/autoload.php',
            getcwd() . '/vendor/autoload.php',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return realpath($path);
            }
        }

        return 'vendor/autoload.php'; // Fallback
    }

    /**
     * Detect the current framework and return bootstrap information
     *
     * @return array Framework information with bootstrap path and initialization code
     */
    private function detectFramework(): array
    {
        $frameworks = [
            'laravel' => [
                'bootstrap_files' => [
                    'bootstrap/app.php',
                    '../bootstrap/app.php',
                    '../../bootstrap/app.php',
                    '../../../bootstrap/app.php',
                    '../../../../bootstrap/app.php',
                ],
                'detector_files' => ['artisan', 'app/Http/Kernel.php'],
                'init_code' => '
                $app = require $bootstrapFile;
                $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
                $kernel->bootstrap();'
            ],
            'symfony' => [
                'bootstrap_files' => [
                    'config/bootstrap.php',
                    '../config/bootstrap.php',
                    '../../config/bootstrap.php',
                    '../../../config/bootstrap.php',
                    'public/index.php',
                ],
                'detector_files' => ['bin/console', 'symfony.lock', 'config/bundles.php'],
                'init_code' => '
                if (basename($bootstrapFile) === "index.php") {
                    // For Symfony apps without separate bootstrap
                    $_SERVER["APP_ENV"] = $_SERVER["APP_ENV"] ?? "prod";
                    require $bootstrapFile;
                } else {
                    require $bootstrapFile;
                    if (class_exists("Symfony\Component\Dotenv\Dotenv")) {
                        (new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname($bootstrapFile, 2)."/.env");
                    }
                }'
            ],
            'codeigniter' => [
                'bootstrap_files' => [
                    'system/CodeIgniter.php',
                    '../system/CodeIgniter.php',
                    'app/Config/Boot/production.php',
                    'app/Config/Boot/development.php',
                ],
                'detector_files' => ['system/CodeIgniter.php', 'app/Config/App.php'],
                'init_code' => '
                if (strpos($bootstrapFile, "CodeIgniter.php") !== false) {
                    define("ENVIRONMENT", $_ENV["CI_ENVIRONMENT"] ?? "production");
                    require $bootstrapFile;
                } else {
                    require $bootstrapFile;
                }'
            ],
            'cakephp' => [
                'bootstrap_files' => [
                    'config/bootstrap.php',
                    '../config/bootstrap.php',
                    '../../config/bootstrap.php',
                ],
                'detector_files' => ['bin/cake', 'config/app_local.php'],
                'init_code' => '
                require $bootstrapFile;'
            ],
            'zend' => [
                'bootstrap_files' => [
                    'config/application.config.php',
                    'public/index.php',
                ],
                'detector_files' => ['module/Application', 'config/application.config.php'],
                'init_code' => '
                if (basename($bootstrapFile) === "index.php") {
                    $_SERVER["REQUEST_URI"] = "/";
                    $_SERVER["REQUEST_METHOD"] = "GET";
                }
                require $bootstrapFile;'
            ]
        ];

        foreach ($frameworks as $name => $config) {
            // Check if framework detector files exist
            foreach ($config['detector_files'] as $detectorFile) {
                $possiblePaths = [
                    $detectorFile,
                    '../' . $detectorFile,
                    '../../' . $detectorFile,
                    '../../../' . $detectorFile,
                    getcwd() . '/' . $detectorFile,
                    dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__) . '/' . $detectorFile,
                ];

                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $bootstrapFile = $this->findFrameworkBootstrap($config['bootstrap_files']);
                        if ($bootstrapFile) {
                            return [
                                'name' => $name,
                                'bootstrap_file' => $bootstrapFile,
                                'init_code' => $config['init_code']
                            ];
                        }
                    }
                }
            }
        }

        return ['name' => 'none', 'bootstrap_file' => null, 'init_code' => ''];
    }

    /**
     * Find framework bootstrap file from possible paths
     *
     * @param array $possibleFiles Array of possible bootstrap file paths
     * @return string|null Path to bootstrap file or null if not found
     */
    private function findFrameworkBootstrap(array $possibleFiles): ?string
    {
        $basePaths = [
            getcwd(),
            dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__),
            __DIR__ . '/../..',
            __DIR__ . '/../../..',
            __DIR__ . '/../../../..',
            __DIR__ . '/../../../../..',
        ];

        foreach ($basePaths as $basePath) {
            foreach ($possibleFiles as $file) {
                $fullPath = $basePath . '/' . $file;
                if (file_exists($fullPath)) {
                    return realpath($fullPath);
                }
            }
        }

        return null;
    }

    /**
     * Spawn background process with enhanced error handling
     *
     * @param string $taskFile Path to the task file to execute
     * @throws \RuntimeException If process spawning fails
     */
    private function spawnBackgroundProcess(string $taskFile): void
    {
        $phpBinary = $this->getPhpBinary();
        $taskId = basename($taskFile, '.php');

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "start /B \"\" \"{$phpBinary}\" \"{$taskFile}\" 2>nul";
            $process = popen($cmd, 'r');
            if ($process === false) {
                throw new \RuntimeException("Failed to spawn Windows background process for task: {$taskId}");
            }
            pclose($process);
        } else {
            // Unix/Linux systems
            $cmd = "\"{$phpBinary}\" \"{$taskFile}\" > /dev/null 2>&1 &";
            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \RuntimeException("Failed to spawn Unix background process for task: {$taskId}, return code: {$returnCode}");
            }
        }

        error_log("🚀 Background process spawned for task: {$taskId}");
    }

    /**
     * Get PHP binary path with enhanced detection
     *
     * @return string Path to PHP binary
     */
    private function getPhpBinary(): string
    {
        // First try the defined constant
        if (defined('PHP_BINARY') && is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }

        $possiblePaths = [
            'php',
            'php.exe',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/php/bin/php',
            'C:\\php\\php.exe',
            'C:\\Program Files\\PHP\\php.exe',
        ];

        foreach ($possiblePaths as $path) {
            // Check if path is directly executable
            if (is_executable($path)) {
                return $path;
            }

            // Use system's which/where command
            $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null';
            $result = shell_exec("{$which} {$path} 2>{$nullDevice}");

            if ($result && trim($result)) {
                $foundPath = trim($result);
                if (is_executable($foundPath)) {
                    return $foundPath;
                }
            }
        }

        // Final fallback
        return 'php';
    }

    /**
     * Log task-specific events
     *
     * @param string $taskId Task identifier
     * @param string $level Log level
     * @param string $message Log message
     */
    private function logTaskEvent(string $taskId, string $level, string $message): void
    {
        if (!$this->enableDetailedLogging) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] [{$taskId}] {$message}" . PHP_EOL;

        if (file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
            error_log("Failed to write to log file: {$this->logFile}");
        }
    }

    /**
     * Log system events
     *
     * @param string $level Log level
     * @param string $message Log message
     */
    private function logEvent(string $level, string $message): void
    {
        if (!$this->enableDetailedLogging) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] [SYSTEM] {$message}" . PHP_EOL;

        if (file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
            error_log("Failed to write to log file: {$this->logFile}");
        }
    }

    /**
     * Get log file path
     *
     * @return string Path to the main log file
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /**
     * Get log directory path
     *
     * @return string Path to the log directory
     */
    public function getLogDirectory(): string
    {
        return $this->logDir;
    }

    /**
     * Get temporary directory path
     *
     * @return string Path to the temporary directory
     */
    public function getTempDirectory(): string
    {
        return $this->tempDir;
    }

    /**
     * Check if detailed logging is enabled
     *
     * @return bool True if detailed logging is enabled
     */
    public function isDetailedLoggingEnabled(): bool
    {
        return $this->enableDetailedLogging;
    }

    /**
     * Enable or disable detailed logging
     *
     * @param bool $enabled Whether to enable detailed logging
     */
    public function setDetailedLogging(bool $enabled): void
    {
        $this->enableDetailedLogging = $enabled;

        if ($enabled) {
            $this->logEvent('INFO', 'Detailed logging enabled');
        }
    }

    /**
     * Get task registry information
     *
     * @return array Internal task registry
     */
    public function getTaskRegistry(): array
    {
        return $this->taskRegistry;
    }

    /**
     * Clear completed tasks from registry (for memory management)
     *
     * @param int $maxAge Maximum age in seconds to keep completed tasks
     * @return int Number of tasks cleared
     */
    public function clearCompletedTasks(int $maxAge = 3600): int
    {
        $cutoffTime = time() - $maxAge;
        $cleared = 0;

        foreach ($this->taskRegistry as $taskId => $info) {
            if ($info['created_at'] < $cutoffTime) {
                $status = $this->getTaskStatus($taskId);
                if (in_array($status['status'], ['COMPLETED', 'ERROR', 'NOT_FOUND'])) {
                    unset($this->taskRegistry[$taskId]);
                    $cleared++;
                }
            }
        }

        if ($cleared > 0) {
            $this->logEvent('INFO', "Cleared {$cleared} completed tasks from registry");
        }

        return $cleared;
    }

    /**
     * Get health check information
     *
     * @return array Health check data
     */
    public function getHealthCheck(): array
    {
        $health = [
            'status' => 'healthy',
            'checks' => [],
            'timestamp' => time()
        ];

        // Check directories are writable
        $health['checks']['temp_directory'] = [
            'status' => is_writable($this->tempDir) ? 'ok' : 'error',
            'path' => $this->tempDir,
            'writable' => is_writable($this->tempDir)
        ];

        $health['checks']['log_directory'] = [
            'status' => is_writable($this->logDir) ? 'ok' : 'error',
            'path' => $this->logDir,
            'writable' => is_writable($this->logDir)
        ];

        // Check PHP binary
        $phpBinary = $this->getPhpBinary();
        $health['checks']['php_binary'] = [
            'status' => is_executable($phpBinary) ? 'ok' : 'warning',
            'path' => $phpBinary,
            'executable' => is_executable($phpBinary)
        ];

        // Check log file
        $health['checks']['log_file'] = [
            'status' => (file_exists($this->logFile) && is_writable($this->logFile)) ? 'ok' : 'warning',
            'path' => $this->logFile,
            'exists' => file_exists($this->logFile),
            'writable' => file_exists($this->logFile) ? is_writable($this->logFile) : null,
            'size' => file_exists($this->logFile) ? filesize($this->logFile) : 0
        ];

        // Check serialization manager
        $health['checks']['serialization'] = [
            'status' => 'ok',
            'available_serializers' => $this->serializationManager->getSerializerInfo()
        ];

        // Overall health status
        $hasErrors = false;
        foreach ($health['checks'] as $check) {
            if ($check['status'] === 'error') {
                $hasErrors = true;
                break;
            }
        }

        if ($hasErrors) {
            $health['status'] = 'error';
        } else {
            $hasWarnings = false;
            foreach ($health['checks'] as $check) {
                if ($check['status'] === 'warning') {
                    $hasWarnings = true;
                    break;
                }
            }
            if ($hasWarnings) {
                $health['status'] = 'warning';
            }
        }

        return $health;
    }

    /**
     * Export task data for external monitoring systems
     *
     * @param array $taskIds Specific task IDs to export (empty for all)
     * @return array Exportable task data
     */
    public function exportTaskData(array $taskIds = []): array
    {
        $allTasks = $this->getAllTasksStatus();

        if (!empty($taskIds)) {
            $allTasks = array_filter($allTasks, function ($taskId) use ($taskIds) {
                return in_array($taskId, $taskIds);
            }, ARRAY_FILTER_USE_KEY);
        }

        return [
            'export_timestamp' => time(),
            'export_date' => date('Y-m-d H:i:s'),
            'summary' => $this->getTasksSummary(),
            'tasks' => $allTasks,
            'system_info' => $this->getStats(),
            'health_check' => $this->getHealthCheck()
        ];
    }

    /**
     * Import task data (for testing or migration)
     *
     * @param array $data Previously exported task data
     * @return bool Success status
     */
    public function importTaskData(array $data): bool
    {
        if (!isset($data['tasks']) || !is_array($data['tasks'])) {
            return false;
        }

        $imported = 0;
        foreach ($data['tasks'] as $taskId => $taskData) {
            $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.status';

            if (file_put_contents($statusFile, json_encode($taskData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
                $imported++;
            }
        }

        $this->logEvent('INFO', "Imported {$imported} tasks from external data");

        return $imported > 0;
    }

    /**
     * Destructor - cleanup and final logging
     */
    public function __destruct()
    {
        if ($this->enableDetailedLogging && !empty($this->taskRegistry)) {
            $this->logEvent('INFO', 'Background process executor shutdown - ' . count($this->taskRegistry) . ' tasks in registry');
        }
    }
}
