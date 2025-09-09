<?php

use Library\Defer\Defer;

require 'vendor/autoload.php';

$task1 = Defer::background(function () {
    $startTime = microtime(true);
    sleep(5);
    $totalTime = microtime(true) - $startTime;
    file_put_contents('txts.txt', "Hello World from terminate callback txts.txt - " . date('Y-m-d H:i:s') . " - " . number_format($totalTime, 3) . " seconds");
    return "txts.txt";
});

$task2 = Defer::background(function () {
    $startTime = microtime(true);
    sleep(5);
    $totalTime = microtime(true) - $startTime;
    file_put_contents('txts1.txt', "Hello World from terminate callback txts1.txt - " . date('Y-m-d H:i:s') . " - " . number_format($totalTime, 3) . " seconds");
    return "txts1.txt";
});


$startTime = microtime(true);
$content = Defer::awaitTask($task1);
$content1 = Defer::awaitTask($task2);
$totalTime = microtime(true) - $startTime;
echo "Total time: " . number_format($totalTime, 3) . " seconds\n";


echo $content . PHP_EOL;
echo $content1 . PHP_EOL;
