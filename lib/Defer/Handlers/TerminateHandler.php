<?php

namespace Library\Defer\Handlers;

class TerminateHandler
{
    /**
     * @var array<callable> Terminate callbacks (executed after response)
     */
    private array $terminateStack = [];

    /**
     * @var bool Whether terminate handlers are registered
     */
    private bool $handlersRegistered = false;

    /**
     * Add a terminate callback (traditional method)
     *
     * @param callable $callback The callback to add
     */
    public function addCallback(callable $callback): void
    {
        if (count($this->terminateStack) >= 50) {
            array_shift($this->terminateStack);
        }

        $this->terminateStack[] = $callback;
        $this->registerHandlers();
    }

    /**
     * Register terminate handlers based on environment
     */
    private function registerHandlers(): void
    {
        if ($this->handlersRegistered) {
            return;
        }

        if ($this->isFastCgiEnvironment()) {
            $this->registerFastCgiHandler();
        } elseif (PHP_SAPI === 'cli') {
            $this->registerCliHandler();
        } elseif (PHP_SAPI === 'cli-server') {
            $this->registerDevServerHandler();
        } else {
            $this->registerFallbackHandler();
        }

        $this->handlersRegistered = true;
    }

    /**
     * Check if running in FastCGI environment
     */
    private function isFastCgiEnvironment(): bool
    {
        return PHP_SAPI === 'fpm-fcgi' ||
            PHP_SAPI === 'cgi-fcgi' ||
            function_exists('fastcgi_finish_request');
    }

    /**
     * Register FastCGI terminate handler
     */
    private function registerFastCgiHandler(): void
    {
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $this->executeCallbacks();
        });
    }

    /**
     * Register CLI terminate handler
     */
    private function registerCliHandler(): void
    {
        register_tick_function(function () {
            static $executed = false;

            if (!$executed && $this->isScriptEnding()) {
                $executed = true;
                $this->executeCallbacks();
            }
        });

        declare(ticks=100);
    }

    /**
     * Register development server handler
     */
    private function registerDevServerHandler(): void
    {
        register_shutdown_function(function () {
            if (ob_get_level() > 0) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            flush();

            $this->executeCallbacks();
        });
    }

    /**
     * Register fallback terminate handler
     */
    private function registerFallbackHandler(): void
    {
        if (ob_get_level() === 0) {
            ob_start();
        }

        register_shutdown_function(function () {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $this->executeCallbacks();
        });
    }

    /**
     * Check if script is ending (for CLI)
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
    public function executeCallbacks(): void
    {
        if (empty($this->terminateStack)) {
            return;
        }

        try {
            foreach ($this->terminateStack as $index => $callback) {
                try {
                    if (is_callable($callback)) {
                        $callback();
                    }
                } catch (\Throwable $e) {
                    error_log('Terminate callback error: ' . $e->getMessage());
                } finally {
                    unset($this->terminateStack[$index]);
                }
            }
        } finally {
            $this->terminateStack = [];
        }
    }

    /**
     * Get terminate callbacks count
     */
    public function getCallbackCount(): int
    {
        return count($this->terminateStack);
    }

    /**
     * Get environment information
     */
    public function getEnvironmentInfo(): array
    {
        return [
            'sapi' => PHP_SAPI,
            'fastcgi' => $this->isFastCgiEnvironment(),
            'fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
            'output_buffering' => ob_get_level() > 0,
        ];
    }

    /**
     * Determine if background execution should be used
     */
    public function shouldUseBackgroundExecution(): bool
    {
        return PHP_SAPI === 'cli-server' || // Built-in dev server
            PHP_SAPI === 'cli' ||        // CLI environment
            !function_exists('fastcgi_finish_request') || // No FastCGI
            !$this->isFastCgiEnvironment(); // Not in FastCGI environment
    }
}