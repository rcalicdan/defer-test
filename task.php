<?php

use Rcalicdan\FiberAsync\Api\DB;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Timer;

require 'vendor/autoload.php';

$task1 = async(function () {
    Timer::sleep(2);
    return await(DB::raw("SELECT * FROM users"));
});

$task2 = async(function () {
    Timer::sleep(2);
    return await(DB::raw("SELECT * FROM async_test"));
});

$start_time = microtime(true);
$results = Task::runAll([
    'task1' => $task1,
    'task2' => $task2,
]);
$end_time = microtime(true);
$total_duration = $end_time - $start_time;
echo "Total duration: $total_duration seconds";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>

<body>
    <pre>
        <?php
        var_dump($results);
        echo "Total duration: $total_duration seconds";
        ?>
    </pre>
</body>

</html>