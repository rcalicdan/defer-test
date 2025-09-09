<?php

require 'vendor/autoload.php';
require 'session.php';

use Library\Defer\Defer;
use Rcalicdan\FiberAsync\Api\DB;
use Rcalicdan\FiberAsync\Api\File;
use Rcalicdan\FiberAsync\Api\Task;

function submitBackgroundTask()
{
    Defer::terminate(function () {
        sleep(5);
        Task::run(function () {
           await(DB::rawExecute("CREATE TABLE IF NOT EXISTS `test` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL, PRIMARY KEY (`id`))"));

           await(DB::table('test')->insert(['name' => 'test']));
        });
    });

    flash("success", "submitted succesfully");
    header("Location: /test.php", true, 301);
}

match ($_SERVER["REQUEST_METHOD"]) {
    "POST" => submitBackgroundTask(),
    default => throw new Exception("Invalid Response")
};
