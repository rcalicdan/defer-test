<?php

use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

$start = microtime(true);
$mainPid = getmypid();
echo "Main process PID: " . $mainPid . "\n";

$results = Task::run(function () {
    return await(Promise::all([
        // Process 1: Multiple I/O tasks within same process
        parallelize(function () {
            return Task::run(function () {
                $processPid = getmypid();
                return await(Promise::all([
                    async(function () use ($processPid) {
                        $start = microtime(true);
                        await(delay(3));
                        return "Process $processPid - Async Task A: " . number_format(microtime(true) - $start, 4) . "s";
                    }),
                    async(function () use ($processPid) {
                        $start = microtime(true);
                        await(delay(2));
                        return "Process $processPid - Async Task B: " . number_format(microtime(true) - $start, 4) . "s";
                    }),
                    async(function () use ($processPid) {
                        $start = microtime(true);
                        await(delay(1));
                        return "Process $processPid - Async Task C: " . number_format(microtime(true) - $start, 4) . "s";
                    }),
                ]));
            });
        }),
        
        // Process 2: Multiple I/O tasks within same process
        parallelize(function () {
            return Task::run(function () {
                $processPid = getmypid();
                return await(Promise::all([
                    async(function () use ($processPid) {
                        $start = microtime(true);
                        await(delay(2.5));
                        return "Process $processPid - Async Task X: " . number_format(microtime(true) - $start, 4) . "s";
                    }),
                    async(function () use ($processPid) {
                        $start = microtime(true);
                        await(delay(1.5));
                        return "Process $processPid - Async Task Y: " . number_format(microtime(true) - $start, 4) . "s";
                    }),
                    async(function () use ($processPid) {
                        $start = microtime(true);
                        await(delay(3.5));
                        return "Process $processPid - Async Task Z: " . number_format(microtime(true) - $start, 4) . "s";
                    }),
                ]));
            });
        }),
        
        // Process 3: Mixed CPU + I/O work
        parallelize(function () {
            return Task::run(function () {
                $processPid = getmypid();
                return await(Promise::all([
                    async(function () use ($processPid) {
                        $start = microtime(true);
                        await(delay(1));
                        return "Process $processPid - Quick I/O: " . number_format(microtime(true) - $start, 4) . "s";
                    }),
                    async(function () use ($processPid) {
                        $start = microtime(true);
                        // Some CPU work + I/O
                        $count = 0;
                        for ($i = 0; $i < 100000000; $i++) {
                            $count++;
                        }
                        await(delay(0.5));
                        return "Process $processPid - CPU+I/O (count: " . number_format($count) . "): " . number_format(microtime(true) - $start, 4) . "s";
                    }),
                ]));
            });
        }),
    ]));
});

$totalTime = microtime(true) - $start;

echo "\n=== RESULTS ===\n";
echo "Total execution time: " . number_format($totalTime, 4) . " seconds\n\n";

// Flatten results since we have nested arrays
foreach ($results as $processResults) {
    foreach ($processResults as $result) {
        echo $result . "\n";
    }
    echo "\n";
}

echo "=== ANALYSIS ===\n";
echo "This demonstrates:\n";
echo "✅ Multi-process: Each parallelize() creates a separate process\n";
echo "✅ Multi-threading: Each process runs its own fiber-based event loop\n";  
echo "✅ Concurrent I/O: Multiple async tasks per process run concurrently\n";
echo "✅ Parallel execution: All processes run simultaneously\n";