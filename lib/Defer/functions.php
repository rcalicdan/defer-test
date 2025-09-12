<?php

use Library\Defer\Defer;
use Library\Defer\Parallel;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

function parallel(array $tasks): PromiseInterface
{
    return async(fn() => Parallel::all($tasks));
}

function awaitParallel(string $task): PromiseInterface
{
    return async(fn() => Defer::awaitTask($task));
}

function spawn(callable $task, array $context = [])
{
    return Defer::background($task, $context);
}

function parallelize(callable $task, array $context = []): PromiseInterface
{
    return awaitParallel(spawn($task, $context));
}
