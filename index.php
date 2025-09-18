<?php

use Library\Defer\Parallel;

require 'vendor/autoload.php';

$startTime = microtime(true);
Parallel::all([
    fn() => print "Hello World",
    fn() => print "Hello World",
    fn() => print "Hello World",
    fn() => print "Hello World",
]);
echo 'cost time: ' . (microtime(true) - $startTime) . 's';
