<?php

require 'vendor/autoload.php';

use Library\Defer\Parallel;
use Rcalicdan\FiberAsync\Api\Task;

$start_time = microtime(true);

Task::run(function () {
    await(all([
        parallelize(function () {
            all([
                delay(4),
                delay(4),
                delay(4),
                delay(4),
                parallelize(fn() => sleep(4))
            ])->await();
        })
    ]));
});

$parallel_time = microtime(true) - $start_time;
echo "Parallel time: " . round($parallel_time, 2) . " seconds\n";
// print_r($results);
