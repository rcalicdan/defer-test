<?php

namespace Library\Defer\Process;

use Library\Defer\Config\ConfigLoader;
use Library\Defer\Logging\BackgroundLogger;
use Library\Defer\Serialization\CallbackSerializationManager;
use Library\Defer\Serialization\SerializationException;
use Library\Defer\Utilities\SystemUtilities;
use Symfony\Component\Process\Process;

/**
 * Manages the creation and execution of background processes using Symfony Process
 */
class ProcessManager
{
    private ConfigLoader $config;
    private SystemUtilities $systemUtils;
    private BackgroundLogger $logger;
    private ?bool $backgroundProcessingSupported = null;
    private array $environmentLimitations = [];
    private string $platformType = 'unknown';

    public function __construct(
        ConfigLoader $config,
        SystemUtilities $systemUtils,
        BackgroundLogger $logger
    ) {
        $this->config = $config;
        $this->systemUtils = $systemUtils;
        $this->logger = $logger;

        // Detect environment capabilities on initialization
        $this->detectEnvironmentCapabilities();
    }

    /**
     * Enhanced environment detection with Railway-specific checks
     */
    private function detectEnvironmentCapabilities(): void
    {
        $this->environmentLimitations = [];

        // Detect platform type
        $this->platformType = $this->detectPlatformType();

        // Platform-specific restrictions
        switch ($this->platformType) {
            case 'railway':
                // Railway often blocks background processes
                $this->environmentLimitations['platform_restriction'] = 'Railway background process limitations';
                $this->backgroundProcessingSupported = false;
                break;

            case 'vercel':
                // Vercel is serverless, no background processes
                $this->environmentLimitations['platform_restriction'] = 'Vercel serverless environment';
                $this->backgroundProcessingSupported = false;
                break;

            case 'netlify':
                // Netlify functions don't support background processes
                $this->environmentLimitations['platform_restriction'] = 'Netlify serverless functions';
                $this->backgroundProcessingSupported = false;
                break;

            case 'heroku':
                // Heroku may work but has limitations
                $this->environmentLimitations['platform_warning'] = 'Heroku may have process limitations';
                $this->backgroundProcessingSupported = $this->testBasicProcessCapability();
                break;

            case 'shared_hosting':
                $this->environmentLimitations['platform_restriction'] = 'Shared hosting limitations';
                $this->backgroundProcessingSupported = false;
                break;

            case 'local':
            case 'vps':
            case 'dedicated':
            default:
                $this->backgroundProcessingSupported = $this->testBasicProcessCapability();
                break;
        }

        // Check disabled functions regardless of platform
        $disabledFunctions = array_map('trim', explode(',', ini_get('disable_functions')));
        $criticalFunctions = ['exec', 'shell_exec', 'proc_open', 'proc_close', 'popen'];
        $disabledCriticalFunctions = array_intersect($criticalFunctions, $disabledFunctions);

        if (!empty($disabledCriticalFunctions)) {
            $this->environmentLimitations['disabled_functions'] = $disabledCriticalFunctions;
            $this->backgroundProcessingSupported = false;
        }

        // Check Symfony Process availability
        if (!class_exists(Process::class)) {
            $this->environmentLimitations['no_symfony_process'] = true;
            $this->backgroundProcessingSupported = false;
        }

        // Log detection results
        $this->logger->logEvent('INFO', "Platform detected: {$this->platformType}");
        if ($this->backgroundProcessingSupported) {
            $this->logger->logEvent('INFO', 'Background processing supported');
        } else {
            $this->logger->logEvent('WARNING', 'Background processing not supported: ' . json_encode($this->environmentLimitations));
        }
    }

    /**
     * Detect hosting platform type
     */
    private function detectPlatformType(): string
    {
        // Railway detection
        if (getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_PROJECT_NAME') || getenv('RAILWAY_SERVICE_NAME')) {
            return 'railway';
        }

        // Vercel detection
        if (getenv('VERCEL') || getenv('NOW_REGION') || getenv('VERCEL_ENV')) {
            return 'vercel';
        }

        // Netlify detection
        if (getenv('NETLIFY') || getenv('NETLIFY_BUILD_BASE')) {
            return 'netlify';
        }

        // Heroku detection
        if (getenv('DYNO') || getenv('HEROKU_APP_NAME')) {
            return 'heroku';
        }

        // Shared hosting indicators
        if (getenv('SHARED_HOSTING') || getenv('CPANEL_USER') || getenv('PLESK_USER')) {
            return 'shared_hosting';
        }

        // Check hostname for hosting providers
        $hostname = gethostname();
        if (strpos($hostname, 'railway') !== false) return 'railway';
        if (strpos($hostname, 'vercel') !== false) return 'vercel';
        if (strpos($hostname, 'heroku') !== false) return 'heroku';
        if (strpos($hostname, 'godaddy') !== false) return 'shared_hosting';
        if (strpos($hostname, 'hostgator') !== false) return 'shared_hosting';

        // Local development detection
        if (
            in_array(gethostname(), ['localhost', '127.0.0.1']) ||
            PHP_SAPI === 'cli-server' ||
            getenv('APP_ENV') === 'local'
        ) {
            return 'local';
        }

        return 'unknown';
    }

    /**
     * Test basic process capability
     */
    private function testBasicProcessCapability(): bool
    {
        if (!class_exists(Process::class)) {
            return false;
        }

        try {
            $phpBinary = $this->systemUtils->getPhpBinary();
            $process = new Process([$phpBinary, '--version']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Exception $e) {
            $this->logger->logEvent('WARNING', 'Basic process test failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get platform information
     */
    public function getPlatformInfo(): array
    {
        return [
            'platform_type' => $this->platformType,
            'background_processing_supported' => $this->backgroundProcessingSupported,
            'limitations' => $this->environmentLimitations,
            'environment_vars' => [
                'RAILWAY_ENVIRONMENT' => getenv('RAILWAY_ENVIRONMENT'),
                'RAILWAY_PROJECT_NAME' => getenv('RAILWAY_PROJECT_NAME'),
                'RAILWAY_SERVICE_NAME' => getenv('RAILWAY_SERVICE_NAME'),
                'VERCEL' => getenv('VERCEL'),
                'NETLIFY' => getenv('NETLIFY'),
                'DYNO' => getenv('DYNO'),
                'HEROKU_APP_NAME' => getenv('HEROKU_APP_NAME'),
            ],
            'hostname' => gethostname(),
            'sapi' => PHP_SAPI,
        ];
    }

    public function isBackgroundProcessingSupported(): bool
    {
        return $this->backgroundProcessingSupported ?? false;
    }

    public function getEnvironmentLimitations(): array
    {
        return $this->environmentLimitations;
    }

    /**
     * Enhanced spawn with platform-aware fallback
     */
    public function spawnBackgroundTask(
        string $taskId,
        callable $callback,
        array $context,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager
    ): void {
        // For Railway and similar platforms, immediately fall back to synchronous execution
        if (!$this->isBackgroundProcessingSupported()) {
            $reason = $this->environmentLimitations['platform_restriction'] ?? 'Environment limitations';
            $this->logger->logTaskEvent($taskId, 'FALLBACK', "Platform: {$this->platformType} - {$reason}, executing synchronously");
            $this->executeSynchronously($taskId, $callback, $context);
            return;
        }

        // Try background execution for supported platforms
        $taskFile = $this->systemUtils->getTempDirectory() . DIRECTORY_SEPARATOR . $taskId . '.php';
        $statusFile = $this->logger->getLogDirectory() . DIRECTORY_SEPARATOR . $taskId . '.status';
        $memoryLimit = $this->config->get('process.memory_limit', '512M');
        $timeout = (int) $this->config->get('process.timeout', 0);

        try {
            $this->createBackgroundTaskScript(
                $taskFile,
                $callback,
                $context,
                $taskId,
                $statusFile,
                $memoryLimit,
                $timeout,
                $frameworkInfo,
                $serializationManager
            );

            $this->spawnBackgroundProcess($taskFile, $timeout);
        } catch (\Throwable $e) {
            if (file_exists($taskFile)) {
                unlink($taskFile);
            }

            // Fallback to synchronous execution
            $this->logger->logTaskEvent($taskId, 'FALLBACK', 'Background spawn failed, falling back to synchronous: ' . $e->getMessage());
            $this->executeSynchronously($taskId, $callback, $context);
        }
    }

    /**
     * Enhanced synchronous execution with proper status tracking
     */
    private function executeSynchronously(string $taskId, callable $callback, array $context): void
    {
        $statusFile = $this->logger->getLogDirectory() . DIRECTORY_SEPARATOR . $taskId . '.status';
        $startTime = microtime(true);
        $pid = getmypid();

        // Create initial status
        $this->updateTaskStatus($statusFile, 'RUNNING', 'Executing synchronously', [
            'task_id' => $taskId,
            'timestamp' => time(),
            'pid' => $pid,
            'execution_mode' => 'synchronous',
            'platform' => $this->platformType,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            // Capture output
            ob_start();

            $reflection = new \ReflectionFunction($callback instanceof \Closure ? $callback : \Closure::fromCallable($callback));
            $paramCount = $reflection->getNumberOfParameters();

            $result = $paramCount > 0 && !empty($context) ? $callback($context) : $callback();

            $output = ob_get_clean();
            $duration = microtime(true) - $startTime;

            // Update status with completion
            $resultInfo = [
                'task_id' => $taskId,
                'status' => 'COMPLETED',
                'message' => "Task completed synchronously in " . number_format($duration, 3) . " seconds",
                'timestamp' => time(),
                'duration' => $duration,
                'execution_time' => $duration,
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'memory_final' => memory_get_usage(true),
                'pid' => $pid,
                'execution_mode' => 'synchronous',
                'platform' => $this->platformType,
                'created_at' => date('Y-m-d H:i:s', time() - $duration),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($result !== null) {
                $resultInfo['result'] = $result;
                $resultInfo['result_type'] = gettype($result);

                // Handle large results
                if (is_string($result) && strlen($result) > 1000) {
                    $resultInfo['result_truncated'] = true;
                    $resultInfo['result'] = substr($result, 0, 1000) . '... (truncated)';
                    $resultInfo['result_length'] = strlen($result);
                } elseif (is_array($result) && count($result) > 50) {
                    $resultInfo['result_truncated'] = true;
                    $resultInfo['result'] = array_slice($result, 0, 50);
                    $resultInfo['result_count'] = count($result);
                }
            }

            if (!empty($output)) {
                $resultInfo['output'] = $output;
                $resultInfo['output_length'] = strlen($output);
                $resultInfo['output_lines'] = substr_count($output, "\n") + 1;
            }

            file_put_contents($statusFile, json_encode($resultInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            if (ob_get_level()) {
                ob_end_clean();
            }

            $errorInfo = [
                'task_id' => $taskId,
                'status' => 'ERROR',
                'message' => 'Synchronous execution failed: ' . $e->getMessage(),
                'timestamp' => time(),
                'duration' => microtime(true) - $startTime,
                'pid' => $pid,
                'execution_mode' => 'synchronous',
                'platform' => $this->platformType,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_code' => $e->getCode(),
                'stack_trace' => $e->getTraceAsString(),
                'created_at' => date('Y-m-d H:i:s', time() - (microtime(true) - $startTime)),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            file_put_contents($statusFile, json_encode($errorInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            throw $e;
        }
    }

    /**
     * Helper to update task status
     */
    private function updateTaskStatus(string $statusFile, string $status, string $message, array $extra = []): void
    {
        $statusData = array_merge([
            'status' => $status,
            'message' => $message,
        ], $extra);

        file_put_contents($statusFile, json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Enhanced test capabilities with platform-specific testing
     */
    public function testCapabilities(bool $verbose, CallbackSerializationManager $serializationManager): array
    {
        $results = [
            'success' => false,
            'errors' => [],
            'warnings' => [],
            'stats' => [],
            'platform_info' => $this->getPlatformInfo(),
        ];

        if ($verbose) {
            echo "🧪 Testing capabilities on platform: {$this->platformType}\n";
            echo "Environment: " . PHP_SAPI . " on " . PHP_OS_FAMILY . "\n";
            echo "Hostname: " . gethostname() . "\n";

            if (!empty($this->environmentLimitations)) {
                echo "⚠️  Platform limitations detected:\n";
                foreach ($this->environmentLimitations as $limitation => $details) {
                    echo "   - {$limitation}: " . (is_array($details) ? implode(', ', $details) : $details) . "\n";
                }
            }
        }

        // Test serialization (always works)
        $testCallback = function () {
            return 'test';
        };
        if ($serializationManager->canSerializeCallback($testCallback)) {
            if ($verbose) echo "✅ Callback serialization: OK\n";
            $results['stats']['callback_serialization'] = true;
        } else {
            $results['errors'][] = 'Callback serialization failed';
            if ($verbose) echo "❌ Callback serialization: FAILED\n";
        }

        // Platform-specific testing
        if ($this->isBackgroundProcessingSupported()) {
            if ($verbose) echo "✅ Background processing: SUPPORTED\n";
            $results['stats']['background_processing'] = true;
        } else {
            if ($verbose) echo "⚠️  Background processing: NOT SUPPORTED (will use synchronous execution)\n";
            $results['warnings'][] = "Platform {$this->platformType} does not support background processing";
            $results['stats']['background_processing'] = false;
        }

        // Test synchronous execution (always available)
        try {
            $testResult = $this->testSynchronousExecution($verbose);
            $results['stats']['synchronous_execution'] = $testResult['success'];
            if ($testResult['success']) {
                if ($verbose) echo "✅ Synchronous execution: OK\n";
            } else {
                $results['errors'][] = 'Synchronous execution test failed: ' . $testResult['error'];
                if ($verbose) echo "❌ Synchronous execution: FAILED\n";
            }
        } catch (\Exception $e) {
            $results['errors'][] = 'Synchronous execution test exception: ' . $e->getMessage();
            if ($verbose) echo "❌ Synchronous execution: EXCEPTION\n";
        }

        $results['success'] = empty($results['errors']);

        return $results;
    }

    /**
     * Test synchronous execution
     */
    private function testSynchronousExecution(bool $verbose): array
    {
        try {
            $testCallback = function () {
                return 'Synchronous test successful: ' . date('Y-m-d H:i:s');
            };

            $result = $testCallback();

            if ($verbose) {
                echo "  Sync test result: $result\n";
            }

            return ['success' => true, 'result' => $result];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a PHP script file for background execution (unchanged)
     */
    private function createBackgroundTaskScript(
        string $taskFile,
        callable $callback,
        array $context,
        string $taskId,
        string $statusFile,
        string $memoryLimit,
        int $timeout,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager
    ): void {
        try {
            $serializedCallback = $serializationManager->serializeCallback($callback);
            $serializedContext = $serializationManager->serializeContext($context);
        } catch (SerializationException $e) {
            throw new \RuntimeException('Failed to serialize callback or context: ' . $e->getMessage(), 0, $e);
        }

        $autoloadPath = $this->systemUtils->findAutoloadPath();
        $script = $this->generateBackgroundScript(
            $taskId,
            $serializedCallback,
            $serializedContext,
            $autoloadPath,
            $statusFile,
            $memoryLimit,
            $timeout,
            $frameworkInfo
        );

        if (file_put_contents($taskFile, $script, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to create background task file: {$taskFile}");
        }

        if (!chmod($taskFile, 0755)) {
            $this->logger->logEvent('WARNING', "Could not set executable permissions on task file: {$taskFile}");
        }
    }

    /**
     * Spawn background process using Symfony Process
     */
    private function spawnBackgroundProcess(string $taskFile, int $timeout): void
    {
        $phpBinary = $this->systemUtils->getPhpBinary();
        $taskId = basename($taskFile, '.php');

        // Build PHP command with optimizations
        $phpArgs = [
            $phpBinary,
            '-d',
            'opcache.enable_cli=1',
            '-d',
            'opcache.jit_buffer_size=100M',
            '-d',
            'opcache.jit=tracing',
            $taskFile
        ];

        try {
            // Create Symfony Process
            $process = new Process($phpArgs);

            // Set timeout if specified (0 means no timeout)
            if ($timeout > 0) {
                $process->setTimeout($timeout);
            } else {
                $process->setTimeout(null); // No timeout
            }

            // Set working directory to current directory
            $process->setWorkingDirectory(getcwd());

            // Start the process in background
            $process->start();

            // Log process start
            $this->logger->logTaskEvent(
                $taskId,
                'PROCESS_STARTED',
                "Symfony Process started with PID: " . ($process->getPid() ?? 'unknown')
            );

            // Don't wait for the process - let it run in background
            // The process will handle its own status updates and cleanup

        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to spawn background process for task: {$taskId}. Error: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Generate the background script template (keeping the same as before)
     */
    private function generateBackgroundScript(
        string $taskId,
        string $serializedCallback,
        string $serializedContext,
        string $autoloadPath,
        string $statusFile,
        string $memoryLimit,
        int $timeout,
        array $frameworkInfo
    ): string {
        // This method remains exactly the same as in your original code
        // I'm keeping it unchanged to maintain all the existing functionality
        $generatedAt = date('Y-m-d H:i:s');
        $frameworkName = $frameworkInfo['name'] ?? 'none';
        $frameworkBootstrap = $frameworkInfo['bootstrap_file'] ?? '';
        $frameworkInitCode = $frameworkInfo['init_code'] ?? '';
        $escapedBootstrapFile = addslashes($frameworkBootstrap);

        $loggingEnabled = $this->config->get('logging.enabled', true);
        $logDirectory = $this->config->get('logging.directory', null);
        $loggingEnabledStr = $loggingEnabled ? 'true' : 'false';
        $logDirectoryStr = $logDirectory ? "'" . addslashes($logDirectory) . "'" : 'null';

        return <<<PHP
<?php
/**
 * Background task with automatic cleanup (Symfony Process)
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
\$taskFile = __FILE__;

// Configuration from parent process (embedded at generation time)
\$LOGGING_ENABLED = {$loggingEnabledStr};
\$LOG_DIRECTORY = {$logDirectoryStr};

// Check if status file should be cleaned up after task completion
function shouldCleanupStatusFile() {
    global \$LOGGING_ENABLED, \$LOG_DIRECTORY;
    return !\$LOGGING_ENABLED || empty(\$LOG_DIRECTORY);
}

// Cleanup function for immediate cleanup (errors, signals)
function performImmediateCleanup() {
    global \$taskFile, \$statusFile;
    
    // Always clean up task file
    for (\$attempt = 0; \$attempt < 5; \$attempt++) {
        if (!file_exists(\$taskFile)) {
            break;
        }
        
        if (@unlink(\$taskFile)) {
            break;
        }
        
        usleep(100000); // 100ms
        
        \$tempName = \$taskFile . '.delete.' . time() . '.' . \$attempt;
        if (@rename(\$taskFile, \$tempName)) {
            @unlink(\$tempName);
            break;
        }
    }
    
    // Only delete status file immediately on errors/signals if cleanup is needed
    if (shouldCleanupStatusFile() && file_exists(\$statusFile)) {
        @unlink(\$statusFile);
    }
}

// Delayed cleanup function for successful completion
function performDelayedCleanup() {
    global \$taskFile, \$statusFile;
    
    // Always clean up task file
    for (\$attempt = 0; \$attempt < 5; \$attempt++) {
        if (!file_exists(\$taskFile)) {
            break;
        }
        
        if (@unlink(\$taskFile)) {
            break;
        }
        
        usleep(10000); // 10ms
        
        \$tempName = \$taskFile . '.delete.' . time() . '.' . \$attempt;
        if (@rename(\$taskFile, \$tempName)) {
            @unlink(\$tempName);
            break;
        }
    }
    
    // For successful completion, wait a bit longer before cleanup to allow status file reading
    if (shouldCleanupStatusFile() && file_exists(\$statusFile)) {
        // Wait 200ms to ensure any awaiting process can read the status file
        usleep(200000); // 200ms
        @unlink(\$statusFile);
    }
}

// Register cleanup for graceful shutdown
register_shutdown_function('performDelayedCleanup');

// Handle signals for immediate cleanup
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() { performImmediateCleanup(); exit(0); });
    pcntl_signal(SIGINT, function() { performImmediateCleanup(); exit(0); });
}

function updateTaskStatus(\$status, \$message = '', \$extra = []) {
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
        'updated_at' => date('Y-m-d H:i:s')
    ], \$extra);
    
    if (!empty(\$capturedOutput)) {
        \$statusData['output'] = \$capturedOutput;
    }
    
    file_put_contents(\$statusFile, json_encode(\$statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function logError(\$e) {
    global \$capturedOutput;
    
    \$errorInfo = [
        'error_message' => \$e->getMessage(),
        'error_file' => \$e->getFile(),
        'error_line' => \$e->getLine(),
        'error_code' => \$e->getCode(),
        'stack_trace' => \$e->getTraceAsString()
    ];
    
    if (!empty(\$capturedOutput)) {
        \$errorInfo['output'] = \$capturedOutput;
    }
    
    updateTaskStatus('ERROR', 'Task failed: ' . \$e->getMessage(), \$errorInfo);
}

function captureOutput(\$buffer) {
    global \$capturedOutput;
    \$capturedOutput .= \$buffer;
    return \$buffer;
}

try {
    updateTaskStatus('RUNNING', 'Task started execution via Symfony Process');
    
    if (file_exists('{$autoloadPath}')) {
        require_once '{$autoloadPath}';
        updateTaskStatus('RUNNING', 'Autoloader loaded successfully');
    } else {
        throw new RuntimeException('Autoloader not found at: {$autoloadPath}');
    }

    if ('{$frameworkName}' !== 'none' && '{$escapedBootstrapFile}' !== '') {
        \$bootstrapFile = '{$escapedBootstrapFile}';
        if (file_exists(\$bootstrapFile)) {
            try {
                {$frameworkInitCode}
                updateTaskStatus('RUNNING', '{$frameworkName} framework bootstrap loaded successfully');
            } catch (Throwable \$bootstrapError) {
                updateTaskStatus('RUNNING', 'Framework bootstrap failed, continuing: ' . \$bootstrapError->getMessage());
            }
        }
    }
    
    \$context = {$serializedContext};
    \$callback = {$serializedCallback};
    
    if (!is_callable(\$callback)) {
        throw new RuntimeException('Deserialized callback is not callable');
    }
    
    ob_start('captureOutput');
    updateTaskStatus('RUNNING', 'Executing callback');
    
    try {
        \$reflection = new ReflectionFunction(\$callback instanceof Closure ? \$callback : Closure::fromCallable(\$callback));
        \$paramCount = \$reflection->getNumberOfParameters();
        
        \$result = \$paramCount > 0 && !empty(\$context) ? \$callback(\$context) : \$callback();
        ob_end_flush();
        
    } catch (Throwable \$callbackError) {
        ob_end_flush();
        throw \$callbackError;
    }
    
    \$duration = microtime(true) - \$startTime;
    \$resultInfo = [
        'execution_time' => \$duration,
        'memory_final' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true)
    ];
    
    if (\$result !== null) {
        \$resultInfo['result'] = \$result;
        \$resultInfo['result_type'] = gettype(\$result);
        
        if (is_string(\$result) && strlen(\$result) > 1000) {
            \$resultInfo['result_truncated'] = true;
            \$resultInfo['result'] = substr(\$result, 0, 1000) . '... (truncated)';
            \$resultInfo['result_length'] = strlen(\$result);
        } elseif (is_array(\$result) && count(\$result) > 50) {
            \$resultInfo['result_truncated'] = true;
            \$resultInfo['result'] = array_slice(\$result, 0, 50);
            \$resultInfo['result_count'] = count(\$result);
        }
    }
    
    if (!empty(\$capturedOutput)) {
        \$resultInfo['output_length'] = strlen(\$capturedOutput);
        \$resultInfo['output_lines'] = substr_count(\$capturedOutput, "\n") + 1;
        
        if (strlen(\$capturedOutput) > 2000) {
            \$resultInfo['output_truncated'] = true;
            \$resultInfo['full_output'] = \$capturedOutput;
        }
    }
    
    updateTaskStatus('COMPLETED', "Task completed successfully in " . number_format(\$duration, 3) . " seconds", \$resultInfo);
    
} catch (Throwable \$e) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    logError(\$e);
    performImmediateCleanup();
    exit(1);
} finally {
    while (ob_get_level()) {
        ob_end_clean();
    }
}

exit(0);
PHP;
    }
}
