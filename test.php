<?php

use Library\Defer\Defer;
use Library\Defer\Parallel;

require 'vendor/autoload.php';

$start_time = microtime(true);

Defer::awaitTask(Defer::background(function () {
    sleep(1);
    echo "Task 1 completed\n";
}));
$end_time = microtime(true);

$execution_time = $end_time - $start_time;

echo "Execution time: " . $execution_time . " seconds";
