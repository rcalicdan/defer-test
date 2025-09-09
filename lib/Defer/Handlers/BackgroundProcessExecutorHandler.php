<?php

namespace Library\Defer\Handlers;

use Library\Defer\Serialization\CallbackSerializationManager;
use Library\Defer\Serialization\SerializationException;

/**
 * Handles background process execution for deferred tasks
 */
class BackgroundProcessExecutorHandler
{
    private CallbackSerializationManager $serializationManager;
    private string $tempDir;

    public function __construct(?CallbackSerializationManager $serializationManager = null)
    {
        $this->serializationManager = $serializationManager ?: new CallbackSerializationManager();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'defer_tasks';
        $this->ensureTempDirectory();
    }

    /**
     * Execute callback in a true background process
     *
     * @param callable $callback The callback to execute
     * @param array $context Additional context data
     * @throws SerializationException If serialization fails
     * @throws \RuntimeException If background execution fails
     */
    public function execute(callable $callback, array $context = []): void
    {
        $this->validateSerialization($callback, $context);

        $taskId = uniqid('defer_', true);
        $taskFile = $this->tempDir . DIRECTORY_SEPARATOR . $taskId . '.php';

        $this->createBackgroundTaskScript($taskFile, $callback, $context);
        $this->spawnBackgroundProcess($taskFile);
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

            $results['success'] = true;
            $results['stats']['php_binary'] = $this->getPhpBinary();
            $results['stats']['temp_dir'] = $this->tempDir;
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
        return [
            'temp_dir' => $this->tempDir,
            'php_binary' => $this->getPhpBinary(),
            'serialization' => [
                'available_serializers' => $this->serializationManager->getSerializerInfo(),
            ],
            'environment' => [
                'os_family' => PHP_OS_FAMILY,
                'sapi' => PHP_SAPI,
            ],
        ];
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
     * Ensure temporary directory exists
     */
    private function ensureTempDirectory(): void
    {
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
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

        chmod($taskFile, 0755);
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
            __DIR__ . '/../../../../vendor/autoload.php',
            __DIR__ . '/../../../vendor/autoload.php',
            __DIR__ . '/../../vendor/autoload.php',
            dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__) . '/vendor/autoload.php',
            getcwd() . '/vendor/autoload.php',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return realpath($path);
            }
        }

        return 'vendor/autoload.php';
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
            $cmd = "start /B \"\" \"{$phpBinary}\" \"{$taskFile}\" 2>nul";
            $process = popen($cmd, 'r');
            if ($process) {
                pclose($process);
            }
        } else {
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

        return 'php';
    }
}