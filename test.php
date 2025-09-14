<?php

require 'vendor/autoload.php';

use Library\Defer\Parallel;

$start_time = microtime(true);
Parallel::all([
    fn() => sleep(5),
    fn() => sleep(5),
]);
$end_time = microtime(true);
$execution_time = $end_time - $start_time;
echo "Execution time: " . $execution_time . " seconds";
