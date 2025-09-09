<?php

namespace Library\Defer;

use Library\Defer\Handlers\ProcessDeferHandler;
use Library\Defer\Utilities\DeferInstance;

/**
 * Static defer utility with reliable function scope management
 */
class Defer
{
    /**
     * @var ProcessDeferHandler|null Global defer handler
     */
    private static ?ProcessDeferHandler $globalHandler = null;

    /**
     * Create a new function-scoped defer instance
     *
     * @return DeferInstance Function-scoped defer instance
     */
    public static function scope(): DeferInstance
    {
        return new DeferInstance;
    }

    /**
     * Global-scoped defer - executes at script shutdown
     *
     * @param callable $callback The callback to defer
     */
    public static function global(callable $callback): void
    {
        if (self::$globalHandler === null) {
            self::$globalHandler = new ProcessDeferHandler;
        }

        self::$globalHandler->defer($callback);
    }

    /**
     * Terminate-scoped defer - executes after response is sent (like Laravel's defer)
     * 
     * This is similar to Laravel's terminable middleware and defer() helper.
     * Callbacks are executed after the HTTP response has been sent to the client
     * or after the main CLI script execution completes.
     *
     * @param callable $callback The callback to execute after response
     */
    public static function terminate(callable $callback): void
    {
        if (self::$globalHandler === null) {
            self::$globalHandler = new ProcessDeferHandler;
        }

        self::$globalHandler->terminate($callback);
    }

    /**
     * Reset state (useful for testing)
     */
    public static function reset(): void
    {
        self::$globalHandler = null;
    }

    /**
     * Get defer statistics
     *
     * @return array Statistics about defer usage and environment
     */
    public static function getStats(): array
    {
        if (self::$globalHandler === null) {
            return [
                'global_defers' => 0,
                'terminate_callbacks' => 0,
                'memory_usage' => memory_get_usage(true),
            ];
        }

        return self::$globalHandler->getStats();
    }
}