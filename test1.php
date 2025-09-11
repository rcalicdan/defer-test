<?php

use Library\Defer\Defer;
use Library\Defer\Parallel;

require 'vendor/autoload.php';

echo "=== Parallel vs Sequential Test ===\n\n";

$start_time = microtime(true);
Parallel::all([
    'task1' => function () {
        sleep(2);
        return 'task1 result';
    },
    'task2' => function () {
        sleep(2);
        return 'task2 result';
    },
]);
$end_time = microtime(true);
$parallel_time = $end_time - $start_time;
echo "Parallel time: " . round($parallel_time, 2) . " seconds\n";
