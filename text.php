<?php

use Library\Defer\Defer;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';
$startTime = microtime(true);
$result = Task::run(function () {
    return await(Promise::all([
        async(function () {
            await(delay(1));
            return "Task 1 complete";
        }),
        async(function () {
            sleep(1);
            return "Task 2 complete";
        }),
                async(function () {
            sleep(1);
            return "Task 2 complete";
        }),
    ]));
});

$totalTime = microtime(true) - $startTime;
echo "Total time: " . number_format($totalTime, 3) . " seconds\n";
print_r($result);
