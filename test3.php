    <?php

    require 'vendor/autoload.php';

    use Library\Defer\Parallel;
    use Rcalicdan\FiberAsync\Api\Task;

    $formatBytes = function ($size, $precision = 2) {
        if ($size <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $base = log($size, 1024);
        $index = min(floor($base), count($units) - 1);
        return round(pow(1024, $base - $index), $precision) . ' ' . $units[$index];
    };

    $start_time = microtime(true);

    $memory_start = memory_get_usage(true);
    Task::run(function () use ($formatBytes) {

        await(all([
            parallelize(function () use ($formatBytes) {
                Task::run(function () use ($formatBytes) {
                    $memory_start = memory_get_usage(true);
                    $timers = array_fill(0, 10000, delay(1));
                    await(all($timers));
                    echo "Task 1 done" . PHP_EOL;
                    $memory_end = memory_get_usage(true);
                    echo "Task 1 memory: " . $formatBytes($memory_end - $memory_start) . PHP_EOL;
                });
            }),
            parallelize(function () use ($formatBytes) {
                Task::run(function () use ($formatBytes) {
                    $memory_start = memory_get_usage(true);
                    $timers = array_fill(0, 10000, delay(1));
                    await(all($timers));
                    echo "Task 2 done" . PHP_EOL;
                    $memory_end = memory_get_usage(true);
                    echo "Task 2 memory: " . $formatBytes($memory_end - $memory_start) . PHP_EOL;
                });
            }),
            parallelize(function () use ($formatBytes) {
                Task::run(function () use ($formatBytes) {
                    $memory_start = memory_get_usage(true);
                    $timers = array_fill(0, 10000, delay(1));
                    await(all($timers));
                    echo "Task 3 done" . PHP_EOL;
                    $memory_end = memory_get_usage(true);
                    echo "Task 3 memory: " . $formatBytes($memory_end - $memory_start) . PHP_EOL;
                });
            }),
            parallelize(function () use ($formatBytes) {
                Task::run(function () use ($formatBytes) {
                    $memory_start = memory_get_usage(true);
                    $timers = array_fill(0, 10000, delay(1));
                    await(all($timers));
                    echo "Task 4 done" . PHP_EOL;
                    $memory_end = memory_get_usage(true);
                    echo "Task 4 memory: " . $formatBytes($memory_end - $memory_start) . PHP_EOL;
                });
            }),
            parallelize(function () use ($formatBytes) {
                Task::run(function () use ($formatBytes) {
                    $memory_start = memory_get_usage(true);
                    $timers = array_fill(0, 10000, delay(1));
                    await(all($timers));
                    echo "Task 4 done" . PHP_EOL;
                    $memory_end = memory_get_usage(true);
                    echo "Task 4 memory: " . $formatBytes($memory_end - $memory_start) . PHP_EOL;
                });
            }),
        ]));
    });

    $parallel_time = microtime(true) - $start_time;
    echo "Parallel time: " . round($parallel_time, 2) . " seconds\n";
    $memory_end = memory_get_usage(true);
    echo "Total memory: " . $formatBytes($memory_end - $memory_start) . PHP_EOL;
    // print_r($results);
