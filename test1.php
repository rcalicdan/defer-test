<?php

use Library\Defer\Defer;

require 'vendor/autoload.php';

$start_time = microtime(true);

function heavyTask($name, $sleepTime)
{
    sleep($sleepTime);
    return "Heavy Task $name Complete";
}
$results = Defer::awaitTaskAll([
    'task1' => function () {
        sleep(2);
        return 'task1 result';
    },
    'task2' => function () {
        sleep(2);
        return 'task2 result';
    },
    'task3' => function () {
        sleep(2);
        return 'task3 result';
    },
], maxConcurrentTasks:2);

$end_time = microtime(true);
$execution_time = $end_time - $start_time;
echo "Execution time: " . $execution_time . " seconds\n\n";
print_r($results);