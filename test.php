<?php
use Library\Defer\Defer;

require 'vendor/autoload.php';

Defer::terminate(function () {
    file_put_contents('defer_test.log', date('Y-m-d H:i:s') . " - Terminate function executed\n", FILE_APPEND);
    sleep(5);
    file_put_contents('defer_test.log', date('Y-m-d H:i:s') . " - After 5 second sleep\n", FILE_APPEND);
});

file_put_contents('defer_test.log', date('Y-m-d H:i:s') . " - Script started\n", FILE_APPEND);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defer Test</title>
</head>
<body>
    <h1>Defer Terminate Test</h1>
    <p>Script executed at: <?php echo date('Y-m-d H:i:s'); ?></p>
    <p>Check the 'defer_test.log' file to see if the terminate function runs after this page loads.</p>
    
    <script>
    window.onload = function() {
        console.log('Page loaded at:', new Date().toISOString());
    }
    </script>
</body>
</html>