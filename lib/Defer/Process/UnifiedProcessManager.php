<?php

namespace Library\Defer\Process;

use Library\Defer\Config\ConfigLoader;
use Library\Defer\Serialization\CallbackSerializationManager;
use Library\Defer\Logging\BackgroundLogger;
use Library\Defer\Utilities\SystemUtilities;

/**
 * Unified Process Manager that coordinates all background process operations
 */
class UnifiedProcessManager
{
    private ConfigLoader $config;
    private CallbackSerializationManager $serializationManager;
    private ProcessManager $processManager;
    private TaskRegistry $taskRegistry;
    private StatusManager $statusManager;
    private BackgroundLogger $logger;
    private SystemUtilities $systemUtils;
    private array $frameworkInfo;

    public function __construct(
        ?CallbackSerializationManager $serializationManager = null,
        ?bool $enableDetailedLogging = null,
        ?string $customLogDir = null
    ) {
        $this->config = ConfigLoader::getInstance();
        $this->serializationManager = $serializationManager ?: new CallbackSerializationManager();
        $this->systemUtils = new SystemUtilities($this->config);
        $this->logger = new BackgroundLogger($this->config, $enableDetailedLogging, $customLogDir);
        $this->statusManager = new StatusManager($this->logger->getLogDirectory());
        $this->taskRegistry = new TaskRegistry();
        $this->processManager = new ProcessManager($this->config, $this->systemUtils, $this->logger);

        $this->frameworkInfo = $this->config->get('bootstrap_framework', true) 
            ? $this->systemUtils->detectFramework() 
            : ['name' => 'none', 'bootstrap_file' => null, 'init_code' => ''];

        $this->logger->logEvent('INFO', 'Unified Process Manager initialized with framework: ' . ($this->frameworkInfo['name'] ?? 'none'));
    }

    /**
     * Execute a task in the background
     */
    public function executeTask(callable $callback, array $context = []): string
    {
        if (!$this->canExecuteTask($callback, $context)) {
            throw new \InvalidArgumentException('Task cannot be serialized for background execution');
        }

        $taskId = $this->systemUtils->generateTaskId();
        
        $this->taskRegistry->registerTask($taskId, $callback, $context);
        $this->statusManager->createInitialStatus($taskId, $callback, $context);

        try {
            $this->processManager->spawnBackgroundTask(
                $taskId,
                $callback,
                $context,
                $this->frameworkInfo,
                $this->serializationManager
            );

            $this->logger->logTaskEvent($taskId, 'SPAWNED', 'Background task spawned successfully');
            return $taskId;
            
        } catch (\Throwable $e) {
            $this->logger->logTaskEvent($taskId, 'ERROR', 'Failed to spawn background task: ' . $e->getMessage());
            $this->statusManager->updateStatus($taskId, 'SPAWN_ERROR', $e->getMessage(), [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    /**
     * Check if a task can be executed in the background
     */
    public function canExecuteTask(callable $callback, array $context = []): bool
    {
        return $this->serializationManager->canSerializeCallback($callback) &&
            (empty($context) || $this->serializationManager->canSerializeContext($context));
    }

    /**
     * Get comprehensive system statistics
     */
    public function getSystemStats(): array
    {
        return [
            'environment' => $this->systemUtils->getEnvironmentInfo(),
            'directories' => [
                'temp_dir' => $this->systemUtils->getTempDirectory(),
                'log_dir' => $this->logger->getLogDirectory(),
            ],
            'framework' => $this->frameworkInfo,
            'logging_enabled' => $this->logger->isDetailedLoggingEnabled(),
            'php_binary' => $this->systemUtils->getPhpBinary(),
            'disk_usage' => $this->systemUtils->getDiskUsage(),
            'serialization' => [
                'available_serializers' => $this->serializationManager->getSerializerInfo(),
            ],
            'registry' => [
                'tracked_tasks' => $this->taskRegistry->getTaskCount(),
            ]
        ];
    }

    /**
     * Perform comprehensive cleanup
     */
    public function performCleanup(int $maxAgeHours = 24): array
    {
        $results = [
            'old_tasks_cleaned' => $this->statusManager->cleanupOldTasks($maxAgeHours, $this->systemUtils->getTempDirectory()),
            'registry_cleaned' => $this->taskRegistry->clearCompletedTasks(3600, $this->statusManager)
        ];

        $this->logger->logEvent('CLEANUP', "Cleanup completed: {$results['old_tasks_cleaned']} old files, {$results['registry_cleaned']} registry entries");
        
        return $results;
    }

    /**
     * Get complete monitoring dashboard data
     */
    public function getDashboardData(): array
    {
        return [
            'summary' => $this->statusManager->getTasksSummary(),
            'recent_tasks' => array_slice($this->statusManager->getAllTasksStatus(), 0, 10, true),
            'system_stats' => $this->getSystemStats(),
            'health_check' => $this->getHealthStatus(),
            'recent_logs' => $this->logger->getRecentLogs(20)
        ];
    }

    /**
     * Get comprehensive health status
     */
    public function getHealthStatus(): array
    {
        return $this->statusManager->getHealthCheck(
            $this->systemUtils,
            $this->logger,
            $this->serializationManager
        );
    }

    /**
     * Run system diagnostics
     */
    public function runDiagnostics(bool $verbose = false): array
    {
        $results = $this->processManager->testCapabilities($verbose, $this->serializationManager);
        $results['system_check'] = $this->getHealthStatus();
        $results['framework_detection'] = $this->frameworkInfo;
        
        return $results;
    }

    public function getTaskStatus(string $taskId): array
    {
        return $this->statusManager->getTaskStatus($taskId);
    }

    public function getAllTasksStatus(): array
    {
        return $this->statusManager->getAllTasksStatus();
    }

    public function getTasksSummary(): array
    {
        return $this->statusManager->getTasksSummary();
    }

    public function getRecentLogs(int $limit = 100): array
    {
        return $this->logger->getRecentLogs($limit);
    }

    public function exportTaskData(array $taskIds = []): array
    {
        return $this->statusManager->exportTaskData($taskIds, $this->getSystemStats());
    }

    public function importTaskData(array $data): bool
    {
        return $this->statusManager->importTaskData($data, $this->logger);
    }

    public function getLogger(): BackgroundLogger
    {
        return $this->logger;
    }

    public function getSystemUtils(): SystemUtilities
    {
        return $this->systemUtils;
    }

    public function getStatusManager(): StatusManager
    {
        return $this->statusManager;
    }

    public function getTaskRegistry(): TaskRegistry
    {
        return $this->taskRegistry;
    }
}