<?php

use Library\Defer\Defer;

require 'vendor/autoload.php';

// Basic usage (existing functionality preserved)
Defer::terminate(function() {
    // This will run after response is sent
    file_put_contents('/tmp/log.txt', 'Task completed');
});

// Force background execution with monitoring
$taskId = Defer::terminate(function($context) {
    // Long-running task
    sleep(30);
    return "Processed " . $context['items'] . " items";
}, true, ['items' => 100]);

// Monitor the task
if ($taskId) {
    echo "Task ID: $taskId\n";
    
    // Check status
    $status = Defer::getTaskStatus($taskId);
    echo "Status: " . $status['status'] . "\n";
    
    // Monitor with progress updates
    $result = Defer::monitorTask($taskId, 60, function($status) {
        echo "Task update: " . $status['message'] . "\n";
    });
    
    // Or wait for completion
    try {
        $result = Defer::awaitTask($taskId, 60);
        echo "Task result: $result\n";
    } catch (\RuntimeException $e) {
        echo "Task failed: " . $e->getMessage() . "\n";
    }
}

// Get dashboard data for monitoring UI
$dashboard = Defer::getDashboardData();
echo "Active tasks: " . count($dashboard['active_tasks']) . "\n";
echo "Total completed: " . $dashboard['summary']['completed'] . "\n";

// Cleanup old tasks
$cleaned = Defer::cleanupOldTasks(24);
echo "Cleaned up $cleaned old task files\n";