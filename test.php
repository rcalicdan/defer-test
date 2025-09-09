<?php

use Library\Defer\Defer;

require 'vendor/autoload.php';

$tasks = [
    'task1' => Defer::background(function () {
        sleep(1);
        return 'Task 1 completed';
    }),
    'task2' => Defer::background(function () {
        sleep(1);
        throw new Exception('Task 2 failed');
    }),
    'task3' => Defer::background(function () {
        sleep(1);
        return 'Task 3 completed';
    }),
];

$start_time = microtime(true);

$results = Defer::awaitTaskAllSettled($tasks);
echo "Execution Time: " . (microtime(true) - $start_time) . " seconds\n";

foreach ($results as $key => $result) {
    if ($result['status'] === 'fulfilled') {
        echo "{$key}: ✅ {$result['value']}\n";
    } else {
        echo "{$key}: ❌ {$result['reason']}\n";
    }
}