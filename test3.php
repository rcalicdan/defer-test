<?php

use Library\Defer\Defer;

require 'vendor/autoload.php';

Defer::global(function () {
    echo "Hello, World!\n";
});

throw new ErrorException('Test Error');