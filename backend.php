<?php

require 'vendor/autoload.php';
require 'session.php';

use Library\Defer\Defer;


function submitBackgroundTask()
{
    Defer::terminate(function () {
        sleep(5);
        file_put_contents('hello world.txt', "test");
    });

    flash("success", "submitted succesfully");
    header("Location: /test.php", true, 301);
}

match ($_SERVER["REQUEST_METHOD"]) {
    "POST" => submitBackgroundTask(),
    default => throw new Exception("Invalid Response")
};
