<?php

use Library\Defer\Defer;
use Library\Defer\Parallel;
use Library\Defer\Process;

require __DIR__ . '/vendor/autoload.php';



$start = microtime(true);
$taskId = Defer::background(function() {
   sleep(4);
   file_put_contents('test.txt', 'hello world');
});
$endtime = microtime(true);
echo "Total Time: " . $endtime - $start . " seconds";
