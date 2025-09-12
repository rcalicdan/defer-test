<?php

use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';
require 'helpers.php';


$start_time = microtime(true);


$results = Task::run(function () {
    return await(Promise::all([
        async(function () {
            $start_time = microtime(true);
            await(delay(5));
            $end_time = microtime(true);
            $io_time = $end_time - $start_time;
            return"i/o Task 1 completed in " . round($io_time, 2) . " seconds\n";
        }),
        async(function () {
            $start_time = microtime(true);
            await(delay(5));
            $end_time = microtime(true);
            $io_time = $end_time - $start_time;
            return"i/o Task 2 completed in " . round($io_time, 2) . " seconds\n";
        }),
        parallelize(function () {
            $start_time = microtime(true);
            for ($i = 0; $i <= 1_000_000_000; $i++) {
            }
            $end_time = microtime(true);
            $cpu_time = $end_time - $start_time;
            return"cpu Task 1 completed in " . round($cpu_time, 2) . " seconds\n";
        }),
        parallelize(function () {
            $start_time = microtime(true);
            for ($i = 0; $i <= 1_000_000_000; $i++) {
            }
            $end_time = microtime(true);
            $cpu_time = $end_time - $start_time;
            return"cpu Task 2 completed in " . round($cpu_time, 2) . " seconds\n";
        }),
    ]));
});
$end_time = microtime(true);
$parallel_time = $end_time - $start_time;
echo "Parallel time: " . round($parallel_time, 2) . " seconds\n";

print_r($results);
