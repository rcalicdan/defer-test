<?php

use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Timer;

require 'vendor/autoload.php';
require 'helpers.php';
require 'arrays.php';
$start_time = microtime(true);


$results = Task::run(function () {
    $task = [
        'task1' => parallelize(function () {
            sleep(2);
            return 'task1 result';
        }),
        'task2' => parallelize(function () {
            sleep(1);
            return 'task2 result';
        }),
        'task3' => delay(5)->then(fn() => "task 3 done"),
        'task4' => parallelize(fn() => sleep(1))->then(fn() => "hello world")
    ];

    return await(all($task));
});

$end_time = microtime(true);
$execution_time = $end_time - $start_time;
echo "Execution time: " . $execution_time . " seconds\n\n";
print_r($results);
