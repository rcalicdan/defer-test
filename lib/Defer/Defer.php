<?php

namespace Library\Defer;

use Library\Defer\Handlers\ProcessDeferHandler;
use Library\Defer\Utilities\DeferInstance;
use Library\Defer\Utilities\LazyTask;

/**
 * Static defer utility focused on task scheduling
 */
class Defer
{
    private static ?ProcessDeferHandler $globalHandler = null;

    /**
     * Create a new function-scoped defer instance
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
     * Terminate-scoped defer - executes after response is sent
     */
    public static function terminate(callable $callback, bool $forceBackground = false, array $context = []): ?string
    {
        return self::getHandler()->terminate($callback, $forceBackground, $context);
    }

    /**
     * Execute a background task
     */
    public static function background(callable $callback, array $context = []): string
    {
        return self::getHandler()->executeBackground($callback, $context);
    }

    /**
     * Create a lazy background task
     */
    public static function lazy(callable $callback, array $context = []): string
    {
        return LazyTask::create($callback, $context);
    }

    /**
     * Reset state (useful for testing)
     */
    public static function reset(): void
    {
        self::$globalHandler = null;
    }

    public static function getHandler(): ProcessDeferHandler
    {
        if (self::$globalHandler === null) {
            self::$globalHandler = new ProcessDeferHandler;
        }

        return self::$globalHandler;
    }
}
