<?php

namespace Library\Defer\Handlers;

use Library\Defer\Serialization\CallbackSerializationManager;
use Library\Defer\Serialization\SerializationException;

class ProcessDeferHandler
{
    /**
     * @var array<callable> Global defers
     */
    private static array $globalStack = [];

    /**
     * @var array<callable> Terminate callbacks (executed after response)
     */
    private static array $terminateStack = [];

    /**
     * @var bool Whether handlers are registered
     */
    private static bool $handlersRegistered = false;

    /**
     * @var bool Whether terminate handlers are registered
     */
    private static bool $terminateHandlersRegistered = false;

    /**
     * @var SignalRegistryHandler|null Signal handler registry instance
     */
    private static ?SignalRegistryHandler $signalHandler = null;

    /**
     * @var string Temporary directory for background task serialization
     */
    private static string $tempDir;

    /**
     * @var CallbackSerializationManager Serialization manager
     */
    private CallbackSerializationManager $serializationManager;

    public function __construct()
    {
        $this->registerShutdownHandlers();
        self::$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'defer_tasks';
        $this->serializationManager = new CallbackSerializationManager();
        $this->ensureTempDirectory();
    }

    /**
     * Ensure temporary directory exists
     */
    private function ensureTempDirectory(): void
    {
        if (!is_dir(self::$tempDir)) {
            mkdir(self::$tempDir, 0755, true);
        }
    }

    /**
     * Create a function-scoped defer handler
     *
     * @return FunctionScopeHandler New function-scoped handler
     */
    public static function createFunctionDefer(): FunctionScopeHandler
    {
        return new FunctionScopeHandler;
    }

    /**
     * Add a global defer
     *
     * @param callable $callback The callback to defer
     */
    public function defer(callable $callback): void
    {
        $this->addToGlobalStack($callback);
    }

    /**
     * Add a terminate callback to be executed after response is sent
     * Now supports true background execution via separate PHP process
     *
     * @param callable $callback The callback to execute after response
     * @param bool $forceBackground Force background execution even in FastCGI environments
     * @param array $context Additional context data to pass to background task
     */
    public function terminate(callable $callback, bool $forceBackground = false, array $context = []): void
    {
        // Check if we should use background process execution
        if ($forceBackground || $this->shouldUseBackgroundExecution()) {
            $this->executeInBackground($callback, $context);
        } else {
            // Use traditional FastCGI method if available
            $this->addToTerminateStack($callback);
            $this->registerTerminateHandlers();
        }
    }

    /**
     * Determine if we should use background process execution
     *
     * @return bool True if background execution should be used
     */
    private function shouldUseBackgroundExecution(): bool
    {
        return PHP_SAPI === 'cli-server' || // Built-in dev server
            PHP_SAPI === 'cli' ||        // CLI environment
            !function_exists('fastcgi_finish_request') || // No FastCGI
            !$this->isFastCgiEnvironment(); // Not in FastCGI environment
    }

    /**
     * Execute callback in a true background process
     *
     * @param callable $callback The callback to execute
     * @param array $context Additional context data
     */
    private function executeInBackground(callable $callback, array $context = []): void
    {
        try {
            // Validate that callback and context can be serialized
            if (!$this->serializationManager->canSerializeCallback($callback)) {
                throw new SerializationException('Callback cannot be serialized for background execution');
            }

            if (!empty($context) && !$this->serializationManager->canSerializeContext($context)) {
                throw new SerializationException('Context cannot be serialized for background execution');
            }

            // Create background task script
            $taskId = uniqid('defer_', true);
            $taskFile = self::$tempDir . DIRECTORY_SEPARATOR . $taskId . '.php';

            $this->createBackgroundTaskScript($taskFile, $callback, $context);

            // Execute in background process
            $this->spawnBackgroundProcess($taskFile);
        } catch (\Throwable $e) {
            error_log('Background execution failed, falling back to shutdown function: ' . $e->getMessage());
            // Fallback to shutdown function
            $this->addToTerminateStack($callback);
            $this->registerTerminateHandlers();
        }
    }

    /**
     * Create a PHP script file for background execution
     *
     * @param string $taskFile Path to the task file
     * @param callable $callback The callback to serialize
     * @param array $context Additional context data
     * @throws \RuntimeException If script creation fails
     */
    private function createBackgroundTaskScript(string $taskFile, callable $callback, array $context = []): void
    {
        try {
            $serializedCallback = $this->serializationManager->serializeCallback($callback);
            $serializedContext = $this->serializationManager->serializeContext($context);
        } catch (SerializationException $e) {
            throw new \RuntimeException('Failed to serialize callback or context: ' . $e->getMessage(), 0, $e);
        }

        $taskId = basename($taskFile, '.php');
        $autoloadPath = $this->findAutoloadPath();

        $script = $this->generateBackgroundScript($taskId, $serializedCallback, $serializedContext, $autoloadPath);

        if (file_put_contents($taskFile, $script) === false) {
            throw new \RuntimeException("Failed to create background task file: {$taskFile}");
        }

        chmod($taskFile, 0755); // Make executable
    }

    /**
     * Generate the background script template
     *
     * @param string $taskId Unique task identifier
     * @param string $serializedCallback Serialized callback code
     * @param string $serializedContext Serialized context code
     * @param string $autoloadPath Path to autoloader
     * @return string Complete PHP script content
     */
    private function generateBackgroundScript(string $taskId, string $serializedCallback, string $serializedContext, string $autoloadPath): string
    {
        return <<<PHP
<?php
/**
 * Auto-generated background task script
 * Task ID: {$taskId}
 * Generated at: <?= date('Y-m-d H:i:s') ?>

 * SAPI: <?= PHP_SAPI ?>

 * Background execution enabled
 */

declare(strict_types=1);

// Set execution environment
set_time_limit(0);
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    // Load autoloader
    if (file_exists('{$autoloadPath}')) {
        require_once '{$autoloadPath}';
    } else {
        throw new RuntimeException('Autoloader not found at: {$autoloadPath}');
    }
    
    // Load session management if available (for framework integration)
    \$possibleSessionPaths = [
        dirname(__DIR__, 2) . '/session.php',
        dirname(__DIR__, 3) . '/session.php',
        dirname(\$_SERVER['SCRIPT_FILENAME'] ?? __FILE__) . '/session.php',
    ];
    
    foreach (\$possibleSessionPaths as \$sessionFile) {
        if (file_exists(\$sessionFile)) {
            require_once \$sessionFile;
            break;
        }
    }
    
    // Restore context data
    \$context = {$serializedContext};
    
    // Restore and execute the callback
    \$callback = {$serializedCallback};
    
    if (!is_callable(\$callback)) {
        throw new RuntimeException('Deserialized callback is not callable');
    }
    
    \$startTime = microtime(true);
    error_log("🚀 Background task {$taskId} started at " . date('Y-m-d H:i:s.u') . " (PID: " . getmypid() . ")");
    
    // Execute the callback with context if it accepts parameters
    \$reflection = new ReflectionFunction(\$callback instanceof Closure ? \$callback : Closure::fromCallable(\$callback));
    \$paramCount = \$reflection->getNumberOfParameters();
    
    if (\$paramCount > 0 && !empty(\$context)) {
        \$result = \$callback(\$context);
    } else {
        \$result = \$callback();
    }
    
    \$duration = microtime(true) - \$startTime;
    error_log("✅ Background task {$taskId} completed in " . number_format(\$duration, 3) . " seconds");
    
    // Log result if it's not null
    if (\$result !== null) {
        \$resultStr = is_scalar(\$result) ? (string)\$result : json_encode(\$result, JSON_UNESCAPED_SLASHES);
        error_log("📋 Task {$taskId} result: " . \$resultStr);
    }
    
} catch (Throwable \$e) {
    error_log("❌ Background task {$taskId} error: " . \$e->getMessage() . " in " . \$e->getFile() . ":" . \$e->getLine());
    error_log("📄 Stack trace: " . \$e->getTraceAsString());
    exit(1);
} finally {
    // Clean up task file
    if (file_exists(__FILE__)) {
        @unlink(__FILE__);
    }
    
    error_log("🧹 Background task {$taskId} cleanup completed");
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
            __DIR__ . '/../../../../vendor/autoload.php',     // In vendor/library/defer/handlers
            __DIR__ . '/../../../vendor/autoload.php',       // In lib/defer/handlers
            __DIR__ . '/../../vendor/autoload.php',          // In defer/handlers
            dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__) . '/vendor/autoload.php', // Script directory
            getcwd() . '/vendor/autoload.php',               // Current working directory
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return realpath($path);
            }
        }

        return 'vendor/autoload.php'; // Fallback
    }

    /**
     * Spawn background process with enhanced error handling
     *
     * @param string $taskFile Path to the task file to execute
     */
    private function spawnBackgroundProcess(string $taskFile): void
    {
        $phpBinary = $this->getPhpBinary();

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows background execution with better error handling
            $cmd = "start /B \"\" \"{$phpBinary}\" \"{$taskFile}\" 2>nul";
            $process = popen($cmd, 'r');
            if ($process) {
                pclose($process);
            }
        } else {
            // Unix/Linux background execution
            $cmd = "\"{$phpBinary}\" \"{$taskFile}\" > /dev/null 2>&1 &";
            exec($cmd);
        }

        error_log("🚀 Background process spawned for task: " . basename($taskFile, '.php'));
    }

    /**
     * Get PHP binary path with enhanced detection
     *
     * @return string Path to PHP binary
     */
    private function getPhpBinary(): string
    {
        if (defined('PHP_BINARY') && is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }

        $possiblePaths = [
            'php',
            'php.exe',
            '/usr/bin/php',
            '/usr/local/bin/php',
            'C:\\php\\php.exe',
            '/opt/php/bin/php',
        ];

        foreach ($possiblePaths as $path) {
            if (is_executable($path)) {
                return $path;
            }

            $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null';
            $result = shell_exec("{$which} {$path} 2>{$nullDevice}");
            if ($result) {
                return trim($result);
            }
        }

        return 'php'; // Final fallback
    }

    /**
     * Add callback to terminate stack (traditional method)
     *
     * @param callable $callback The callback to add
     */
    private function addToTerminateStack(callable $callback): void
    {
        if (count(self::$terminateStack) >= 50) {
            array_shift(self::$terminateStack);
        }

        self::$terminateStack[] = $callback;
    }

    /**
     * Register terminate handlers based on environment
     */
    private function registerTerminateHandlers(): void
    {
        if (self::$terminateHandlersRegistered) {
            return;
        }

        if ($this->isFastCgiEnvironment()) {
            $this->registerFastCgiTerminateHandler();
        } elseif (PHP_SAPI === 'cli') {
            $this->registerCliTerminateHandler();
        } elseif (PHP_SAPI === 'cli-server') {
            $this->registerDevServerTerminateHandler();
        } else {
            $this->registerFallbackTerminateHandler();
        }

        self::$terminateHandlersRegistered = true;
    }

    /**
     * Check if running in FastCGI environment
     *
     * @return bool True if FastCGI environment detected
     */
    private function isFastCgiEnvironment(): bool
    {
        return PHP_SAPI === 'fpm-fcgi' ||
            PHP_SAPI === 'cgi-fcgi' ||
            function_exists('fastcgi_finish_request');
    }

    /**
     * Register FastCGI terminate handler (like Laravel's implementation)
     */
    private function registerFastCgiTerminateHandler(): void
    {
        register_shutdown_function(function () {
            // First, try to finish the request if possible
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            // Execute terminate callbacks after response is sent
            $this->executeTerminateCallbacks();
        });
    }

    /**
     * Register CLI terminate handler
     */
    private function registerCliTerminateHandler(): void
    {
        // For CLI, we can use a tick function to execute terminate callbacks
        // when the main script execution is about to end
        register_tick_function(function () {
            static $executed = false;

            if (!$executed && $this->isScriptEnding()) {
                $executed = true;
                $this->executeTerminateCallbacks();
            }
        });

        declare(ticks=100);
    }

    /**
     * Special handler for PHP built-in development server
     */
    private function registerDevServerTerminateHandler(): void
    {
        register_shutdown_function(function () {
            // Try to flush output first
            if (ob_get_level() > 0) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            flush();

            // Execute terminate callbacks
            $this->executeTerminateCallbacks();
        });
    }

    /**
     * Register fallback terminate handler
     */
    private function registerFallbackTerminateHandler(): void
    {
        if (ob_get_level() === 0) {
            ob_start();
        }

        register_shutdown_function(function () {
            // Flush any remaining output
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            // Execute terminate callbacks
            $this->executeTerminateCallbacks();
        });
    }

    /**
     * Check if script is ending (for CLI)
     *
     * @return bool True if script execution is ending
     */
    private function isScriptEnding(): bool
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        return empty($backtrace) ||
            (count($backtrace) === 1 && !isset($backtrace[0]['function']));
    }

    /**
     * Execute all terminate callbacks
     */
    private function executeTerminateCallbacks(): void
    {
        if (empty(self::$terminateStack)) {
            return;
        }

        try {
            // Execute terminate callbacks in FIFO order (different from regular defers)
            foreach (self::$terminateStack as $index => $callback) {
                try {
                    if (is_callable($callback)) {
                        $callback();
                    }
                } catch (\Throwable $e) {
                    error_log('Terminate callback error: ' . $e->getMessage());
                } finally {
                    unset(self::$terminateStack[$index]);
                }
            }
        } finally {
            self::$terminateStack = [];
        }
    }

    /**
     * Add callback to global stack
     *
     * @param callable $callback The callback to add
     */
    private function addToGlobalStack(callable $callback): void
    {
        if (count(self::$globalStack) >= 100) {
            array_shift(self::$globalStack);
        }

        self::$globalStack[] = $callback;
    }

    /**
     * Execute stack in LIFO order
     *
     * @param array $stack The stack to execute
     */
    private function executeStack(array $stack): void
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            try {
                if (is_callable($stack[$i])) {
                    $stack[$i]();
                }
            } catch (\Throwable $e) {
                error_log('Defer error: ' . $e->getMessage());
            } finally {
                unset($stack[$i]);
            }
        }
    }

    /**
     * Execute all pending global defers (shutdown handler)
     */
    public function executeAll(): void
    {
        try {
            // Execute global defers first
            $this->executeStack(self::$globalStack);
        } finally {
            self::$globalStack = [];
        }
    }

    /**
     * Manual execution of terminate callbacks (for testing)
     */
    public function executeTerminate(): void
    {
        $this->executeTerminateCallbacks();
    }

    /**
     * Register shutdown handlers
     */
    private function registerShutdownHandlers(): void
    {
        if (self::$handlersRegistered) {
            return;
        }

        register_shutdown_function(function () {
            try {
                $this->executeAll();
            } catch (\Throwable $e) {
                error_log('Defer shutdown error: ' . $e->getMessage());
            }
        });

        // Register signal handlers for CLI
        if (PHP_SAPI === 'cli') {
            self::$signalHandler = new SignalRegistryHandler([$this, 'executeAll']);
            self::$signalHandler->register();
        }

        self::$handlersRegistered = true;
    }

    /**
     * Enhanced statistics including serialization capabilities
     *
     * @return array Comprehensive statistics
     */
    public function getStats(): array
    {
        $baseStats = [
            'global_defers' => count(self::$globalStack),
            'terminate_callbacks' => count(self::$terminateStack),
            'memory_usage' => memory_get_usage(true),
            'background_execution' => $this->shouldUseBackgroundExecution(),
            'temp_dir' => self::$tempDir,
        ];

        $serializationStats = [
            'serialization' => [
                'available_serializers' => $this->serializationManager->getSerializerInfo(),
                'opis_closure_available' => class_exists('Opis\\Closure\\SerializableClosure'),
            ],
        ];

        return array_merge($baseStats, $serializationStats, [
            'environment' => [
                'sapi' => PHP_SAPI,
                'fastcgi' => $this->isFastCgiEnvironment(),
                'fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
                'output_buffering' => ob_get_level() > 0,
                'php_binary' => $this->getPhpBinary(),
            ],
        ]);
    }

    /**
     * Get signal handling capabilities info
     *
     * @return array Signal handling information
     */
    public function getSignalHandlingInfo(): array
    {
        if (self::$signalHandler) {
            return self::$signalHandler->getCapabilities();
        }

        return [
            'platform' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'methods' => ['Generic fallback (shutdown function)'],
            'capabilities' => ['shutdown_function' => true],
        ];
    }

    /**
     * Test signal handling (for debugging)
     */
    public function testSignalHandling(): void
    {
        echo "Testing defer signal handling capabilities...\n";

        $info = $this->getSignalHandlingInfo();

        echo "Platform: {$info['platform']} ({$info['sapi']})\n";
        echo "Available methods:\n";

        foreach ($info['methods'] as $method) {
            echo "  ✅ {$method}\n";
        }

        echo "\nCapabilities:\n";
        foreach ($info['capabilities'] as $capability => $available) {
            $status = $available ? '✅' : '❌';
            echo "  {$status} {$capability}\n";
        }

        $this->defer(function () {
            echo "\n🎯 Test defer executed successfully!\n";
        });

        echo "\nDefer test registered. Try Ctrl+C or let script finish normally.\n";
    }

    /**
     * Test background execution capabilities
     *
     * @param bool $verbose Whether to output detailed information
     * @return array Test results
     */
    public function testBackgroundExecution(bool $verbose = false): array
    {
        $results = [
            'success' => false,
            'method' => null,
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
                file_put_contents(sys_get_temp_dir() . '/defer_test.txt', 'Background execution test: ' . date('Y-m-d H:i:s'));
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
            $testContext = ['test' => true, 'timestamp' => time()];
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

            // Determine execution method
            if ($this->shouldUseBackgroundExecution()) {
                $results['method'] = 'background_process';
                if ($verbose) {
                    echo "🚀 Using background process execution\n";
                }
            } else {
                $results['method'] = 'fastcgi_terminate';
                if ($verbose) {
                    echo "⚡ Using FastCGI terminate execution\n";
                }
            }

            $results['success'] = true;
            $results['stats']['php_binary'] = $this->getPhpBinary();
            $results['stats']['temp_dir'] = self::$tempDir;
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
            if ($verbose) {
                echo "❌ Test failed: " . $e->getMessage() . "\n";
            }
        }

        return $results;
    }
}
