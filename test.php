<?php

require 'vendor/autoload.php';

use Library\Defer\Defer;
use Library\Defer\Parallel;
use Library\Defer\Process;

$start_time = microtime(true);
Parallel::all([
    fn() => sleep(1),
    fn() => sleep(1),
]);
$end_time = microtime(true);
$execution_time = $end_time - $start_time;
echo "Execution time: " . $execution_time . " seconds\n";

echo json_encode(Defer::getHandler()->getBackgroundExecutor()->getTemporaryFileStats(), JSON_PRETTY_PRINT);
