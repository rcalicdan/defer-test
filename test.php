<?php

use Library\Defer\Defer;
use Library\Defer\Parallel;
use Library\Defer\Process;

require 'vendor/autoload.php';

$start_time = microtime(true);

Parallel::all([
    'task1' => fn() => sleep(1),
    'task2' => fn() => sleep(1),
    'task3' => fn() => sleep(1),
    'task4' => fn() => sleep(1),
    'task5' => fn() => sleep(1),
],2);


$end_time = microtime(true);

$execution_time = $end_time - $start_time;

echo "Execution time: " . $execution_time . " seconds";
