<?php

use Library\Defer\Defer;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Promise;

require 'vendor/autoload.php';

$start_time = microtime(true);

$results = Task::run(function () {
    $deferPromise = async(function () {
        $heavyTask1 = Defer::background(function () {
            $start_time = microtime(true);
            for($i=0; $i<=1000000000; $i++){
               //count from 0 to a billion
            }
            $end_time = microtime(true);
            return "Heavy Task 1 Complete. Time taken: " . ($end_time - $start_time) . " seconds";
        });

        $heavyTask2 = Defer::background(function () {
            $start_time = microtime(true);
            for($i=0; $i<=1000000000; $i++){
               //count from 0 to a billion
            }
            $end_time = microtime(true);
            return "Heavy Task 2 Complete. Time taken: " . ($end_time - $start_time) . " seconds";
        });

        return Defer::awaitTaskAll([
            "heavyTask1" => $heavyTask1,
            "heavyTask2" => $heavyTask2,
        ]);
    });



    $promiseAllResult = Promise::all([
        'deferTasks' => $deferPromise, 
        'non-blocking task 1' => delay(2)->then(function () {
            return 'non-blocking task 1 result';
        }),
        'non-blocking task 2' => delay(10)->then(function () {
            return 'non-blocking task 2 result';
        }),
    ]);

    $allResults = await($promiseAllResult);

    return array_merge($allResults);
});

$end_time = microtime(true);
$execution_time = $end_time - $start_time;
echo "Execution time: " . $execution_time . " seconds\n\n";
print_r($results);