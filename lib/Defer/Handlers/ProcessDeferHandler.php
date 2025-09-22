<?php

namespace Library\Defer\Handlers;

class ProcessDeferHandler
{
    /**
     * @var array<callable> Global defers
     */
    private static array $globalStack = [];

    /**
     * @var bool Whether handlers are registered
     */
    private static bool $handlersRegistered = false;

    /**
     * @var SignalRegistryHandler|null Signal handler registry instance
     */
    private static ?SignalRegistryHandler $signalHandler = null;

    /**
     * @var TerminateHandler Terminate handler instance
     */
    private TerminateHandler $terminateHandler;

    /**
     * @var BackgroundTaskManager Background task manager
     */
    private BackgroundTaskManager $backgroundTaskManager;

    public function __construct()
    {
        $this->registerShutdownHandlers();
        $this->terminateHandler = new TerminateHandler();
        $this->backgroundTaskManager = new BackgroundTaskManager();
    }

    /**
     * Create a function-scoped defer handler
     */
    public static function createFunctionDefer(): FunctionScopeHandler
    {
        return new FunctionScopeHandler;
    }

    /**
     * Add a global defer
     */
    public function defer(callable $callback): void
    {
        $this->addToGlobalStack($callback);
    }

    /**
     * Add a terminate callback (executes after response is sent)
     *
     * @param callable $callback The callback to execute
     * @param bool $always Whether to execute even on 4xx/5xx status codes
     */
    public function terminate(callable $callback, bool $always = false): void
    {
        $this->terminateHandler->addCallback($callback, $always);
    }

    /**
     * Execute callback in background and return task ID
     */
    public function executeBackground(callable $callback, array $context = []): string
    {
        return $this->backgroundTaskManager->execute($callback, $context);
    }

    public function getTaskStatus(string $taskId): array
    {
        return $this->backgroundTaskManager->getTaskStatus($taskId);
    }

    public function getAllTasksStatus(): array
    {
        return $this->backgroundTaskManager->getAllTasksStatus();
    }

    public function getTasksSummary(): array
    {
        return $this->backgroundTaskManager->getTasksSummary();
    }

    public function getRecentLogs(int $limit = 100): array
    {
        return $this->backgroundTaskManager->getRecentLogs($limit);
    }

    public function cleanupOldTasks(int $maxAgeHours = 24): int
    {
        return $this->backgroundTaskManager->cleanupOldTasks($maxAgeHours);
    }

    public function getBackgroundExecutor(): BackgroundProcessExecutorHandler
    {
        return $this->backgroundTaskManager->getBackgroundExecutor();
    }

    public function getLogDirectory(): string
    {
        return $this->backgroundTaskManager->getLogDirectory();
    }

    /**
     * Manual execution of terminate callbacks (for testing)
     */
    public function executeTerminate(): void
    {
        $this->terminateHandler->executeCallbacks();
    }

    /**
     * Add callback to global stack
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
     * Enhanced statistics
     */
    public function getStats(): array
    {
        $baseStats = [
            'global_defers' => count(self::$globalStack),
            'terminate_callbacks' => $this->terminateHandler->getCallbackCount(),
            'memory_usage' => memory_get_usage(true),
        ];

        $backgroundStats = $this->backgroundTaskManager->getStats();
        $environmentStats = $this->terminateHandler->getEnvironmentInfo();

        return array_merge($baseStats, [
            'background' => $backgroundStats,
            'environment' => $environmentStats,
        ]);
    }

    /**
     * Get signal handling capabilities info
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
     */
    public function testBackgroundExecution(bool $verbose = false): array
    {
        return $this->backgroundTaskManager->testCapabilities($verbose);
    }
}