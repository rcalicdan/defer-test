<?php

use Library\Defer\Parallel;

require 'vendor/autoload.php';

$start_time = microtime(true);

Parallel::all([
    fn() => sleep(1),
    fn() => sleep(1),
    fn() => sleep(1),
    fn() => sleep(1),
]);

$end_time = microtime(true);
$execution_time = $end_time - $start_time;
echo "Execution time: " . $execution_time . " seconds\n\n";
