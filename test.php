<?php

use Library\Defer\Defer;
use Library\Defer\Parallel;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Promise;

require 'vendor/autoload.php';
require 'helpers.php';
$start_time = microtime(true);

$results = Task::run(function () {
    $cpuTask1 = parallelize(Defer::lazy(function () {
        $start_time = microtime(true);
        for ($i = 0; $i <= 1000000000; $i++) {
        }
        $end_time = microtime(true);

        return "Heavy Task 1 Complete. Time taken: " . ($end_time - $start_time) . " seconds";
    }));

    $cpuTask2 = parallelize(Defer::lazy(function () {
        $start_time = microtime(true);
        for ($i = 0; $i <= 1000000000; $i++) {
        }
        $end_time = microtime(true);

        return "Heavy Task 1 Complete. Time taken: " . ($end_time - $start_time) . " seconds";
    }));




    $promiseAllResult = Promise::all([
        'cpuTask1' => $cpuTask1,
        'cpuTask2' => $cpuTask2,
        'non-blocking task 1' => delay(2)->then(function () {
            return 'non-blocking task 1 result';
        }),
        'non-blocking task 2' => delay(10)->then(function () {
            return 'non-blocking task 2 result';
        }),
    ]);

    return await($promiseAllResult);
});

// parallelize(Defer::background(function () {
//     sleep(10);
//     file_put_contents('test.txt', 'test');
// }));
 
$end_time = microtime(true);
$execution_time = $end_time - $start_time;
echo "Execution time: " . $execution_time . " seconds\n\n";
print_r($results);
