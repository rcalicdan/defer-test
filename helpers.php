<?php

use Library\Defer\Defer;
use Library\Defer\Parallel;

require 'vendor/autoload.php';

function parallel(array $tasks)
{
    return async(fn() => Parallel::all($tasks));
}

function parallelize(string $task)
{
    return async(fn() => Defer::awaitTask($task));
}
