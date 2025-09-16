<?php

use Library\Defer\Parallel;

require __DIR__ . '/vendor/autoload.php';



$start = microtime(true);
Parallel::all([
    fn() =>  sleep(1),
    fn() => sleep(1),
    fn() => sleep(1),
    fn() => sleep(1),
]);
$endtime = microtime(true);
echo "Total Time: " . $endtime - $start . " seconds";
