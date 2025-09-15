<?php

use Library\Defer\AsyncProcess;
use Library\Defer\Defer;

require 'vendor/autoload.php';

// Traditional usage with automatic Promise resolution
$taskId = Defer::background(function($context) {
    // File operations are now non-blocking
    $content = file_get_contents('/path/to/input.txt');
    $processed = strtoupper($content);
    file_put_contents('/path/to/output.txt', $processed);
    return strlen($processed);
});

// Use Promise-based monitoring
AsyncProcess::monitorAsync($taskId, 30, function($status) {
    echo "Status update: {$status['status']} - {$status['message']}\n";
})
->then(function($finalStatus) {
    echo "Task completed with status: {$finalStatus['status']}\n";
})
->catch(function($error) {
    echo "Task failed: " . $error->getMessage() . "\n";
});

// Await with Promise
AsyncProcess::awaitAsync($taskId, 60)
    ->then(function($result) {
        echo "Task result: {$result}\n";
    })
    ->catch(function($error) {
        echo "Task error: " . $error->getMessage() . "\n";
    });
