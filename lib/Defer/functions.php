<?php

use Library\Defer\Defer;
use Library\Defer\Parallel;
use Library\Defer\Process;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

function parallel(array $tasks): PromiseInterface
{
    return async(fn() => Parallel::all($tasks));
}

function awaitParallel(string $task): PromiseInterface
{
    return async(fn() => Process::await($task));
}

function spawn(callable $task, array $context = [])
{
    return Defer::background($task, $context);
}

function parallelize(callable $task, array $context = []): PromiseInterface
{
    return awaitParallel(spawn($task, $context));
}
