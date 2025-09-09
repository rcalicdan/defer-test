<?php

namespace Library\Defer\Handlers;

use Library\Defer\Serialization\CallbackSerializationManager;

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
     * @var CallbackSerializationManager Serialization manager
     */
    private CallbackSerializationManager $serializationManager;

    /**
     * @var BackgroundProcessExecutorHandler Background process executor
     */
    private BackgroundProcessExecutorHandler $backgroundExecutor;

    public function __construct()
    {
        $this->registerShutdownHandlers();
        $this->serializationManager = new CallbackSerializationManager();
        $this->backgroundExecutor = new BackgroundProcessExecutorHandler($this->serializationManager, true, 'test/log.txt');
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
     * Add a terminate callback with optional background execution and monitoring
     */
    public function terminate(callable $callback, bool $forceBackground = false, array $context = []): ?string
    {
        // Check if we should use background process execution
        if ($forceBackground || $this->shouldUseBackgroundExecution()) {
            return $this->executeInBackground($callback, $context);
        } else {
            // Use traditional FastCGI method if available
            $this->addToTerminateStack($callback);
            $this->registerTerminateHandlers();
            return null; // No task ID for traditional execution
        }
    }

    /**
     * Execute callback in background and return task ID
     */
    public function executeBackground(callable $callback, array $context = []): string
    {
        return $this->executeInBackground($callback, $context);
    }

    /**
     * Updated executeInBackground to return task ID
     */
    private function executeInBackground(callable $callback, array $context = []): string
    {
        try {
            $taskId = $this->backgroundExecutor->execute($callback, $context);
            return $taskId;
        } catch (\Throwable $e) {
            error_log('Background execution failed, falling back to shutdown function: ' . $e->getMessage());
            // Fallback to shutdown function
            $this->addToTerminateStack($callback);
            $this->registerTerminateHandlers();
            throw $e; // Re-throw to indicate background execution failed
        }
    }

    /**
     * Delegate to background executor
     */
    public function getTaskStatus(string $taskId): array
    {
        return $this->backgroundExecutor->getTaskStatus($taskId);
    }

    /**
     * Delegate to background executor
     */
    public function getAllTasksStatus(): array
    {
        return $this->backgroundExecutor->getAllTasksStatus();
    }

    /**
     * Delegate to background executor
     */
    public function getTasksSummary(): array
    {
        return $this->backgroundExecutor->getTasksSummary();
    }

    /**
     * Delegate to background executor
     */
    public function getRecentLogs(int $limit = 100): array
    {
        return $this->backgroundExecutor->getRecentLogs($limit);
    }

    /**
     * Delegate to background executor
     */
    public function cleanupOldTasks(int $maxAgeHours = 24): int
    {
        return $this->backgroundExecutor->cleanupOldTasks($maxAgeHours);
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
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $this->executeTerminateCallbacks();
        });
    }

    /**
     * Register CLI terminate handler
     */
    private function registerCliTerminateHandler(): void
    {
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
            if (ob_get_level() > 0) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            flush();

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
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

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

        if (PHP_SAPI === 'cli') {
            self::$signalHandler = new SignalRegistryHandler([$this, 'executeAll']);
            self::$signalHandler->register();
        }

        self::$handlersRegistered = true;
    }

    /**
     * Enhanced statistics including background execution capabilities
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
        ];

        $backgroundStats = $this->backgroundExecutor->getStats();

        return array_merge($baseStats, [
            'background' => $backgroundStats,
            'environment' => [
                'sapi' => PHP_SAPI,
                'fastcgi' => $this->isFastCgiEnvironment(),
                'fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
                'output_buffering' => ob_get_level() > 0,
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
        return $this->backgroundExecutor->testCapabilities($verbose);
    }
}
