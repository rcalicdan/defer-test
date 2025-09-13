<?php

use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

$start = microtime(true);
$mainPid = getmypid();
echo "Main process PID: " . $mainPid . "\n";

$results = Task::run(function () {
    return await(Promise::all([
        // Group async tasks first - event loop schedules these immediately
        async(function () {
            $taskStart = microtime(true);
            $pid = getmypid();
            await(delay(3)); // I/O delay - yields control back
            return [
                'task' => 'Async I/O Task 1',
                'pid' => $pid,
                'count' => 0,
                'time' => microtime(true) - $taskStart
            ];
        }),
        
        async(function () {
            $taskStart = microtime(true);
            $pid = getmypid();
            await(delay(2)); // I/O delay - yields control back
            return [
                'task' => 'Async I/O Task 2',
                'pid' => $pid,
                'count' => 0,
                'time' => microtime(true) - $taskStart
            ];
        }),
        
        // Group parallelize tasks after - these spawn processes
        parallelize(function () {
            $taskStart = microtime(true);
            $pid = getmypid();
            $count = 0;
            for ($i = 1; $i <= 1000000000; $i++) {
                $count++;
            }
            return [
                'task' => 'Parallel CPU Task 1',
                'pid' => $pid,
                'count' => $count,
                'time' => microtime(true) - $taskStart
            ];
        }),
        
        parallelize(function () {
            $taskStart = microtime(true);
            $pid = getmypid();
            $count = 0;
            for ($i = 1; $i <= 500000000; $i++) {
                $count++;
            }
            return [
                'task' => 'Parallel CPU Task 2',
                'pid' => $pid,
                'count' => $count,
                'time' => microtime(true) - $taskStart
            ];
        }),
    ]));
});

$totalTime = microtime(true) - $start;

echo "Total time: " . number_format($totalTime, 4) . " seconds\n\n";

foreach ($results as $result) {
    echo sprintf(
        "%s - PID: %d - Count: %s - Time: %.4f seconds - %s\n",
        $result['task'],
        $result['pid'],
        number_format($result['count']),
        $result['time'],
        ($result['pid'] === $mainPid) ? 'Same process (concurrent)' : 'Different process (parallel)'
    );
}

$maxTime = max(array_column($results, 'time'));
echo "\nLongest task: " . number_format($maxTime, 4) . " seconds\n";
echo "Efficiency: " . number_format($maxTime / $totalTime, 2) . "x speedup\n";