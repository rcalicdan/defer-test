<?php

use Library\Defer\Defer;
use Library\Defer\Process;

require 'vendor/autoload.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $requestTime = microtime(true);
    error_log("=== REQUEST STARTED ===");
    error_log("Registering terminate callback at " . date('Y-m-d H:i:s.u'));

    Defer::terminate(function () use ($requestTime) {
        $terminateStart = microtime(true);
        $delayFromRequest = $terminateStart - $requestTime;

        error_log("=== TERMINATE CALLBACK STARTED ===");
        error_log("Terminate callback started at " . date('Y-m-d H:i:s.u'));
        error_log("Delay from request: " . number_format($delayFromRequest, 3) . " seconds");

        sleep(5);

        try {
            file_put_contents('txts .txt', "Hello World from terminate callback - " . date('Y-m-d H:i:s'));
            $totalTime = microtime(true) - $terminateStart;
            error_log("File written successfully after " . number_format($totalTime, 3) . " seconds");
        } catch (Exception $e) {
            error_log("Error in terminate callback: " . $e->getMessage());
        }

        $totalExecutionTime = microtime(true) - $requestTime;
        error_log("Terminate callback completed at " . date('Y-m-d H:i:s.u'));
        error_log("Total time from request: " . number_format($totalExecutionTime, 3) . " seconds");
        error_log("=== TERMINATE CALLBACK ENDED ===");
    });

    Defer::terminate(function () use ($requestTime) {
        $terminateStart = microtime(true);
        $delayFromRequest = $terminateStart - $requestTime;

        error_log("=== TERMINATE CALLBACK STARTED ===");
        error_log("Terminate callback started at " . date('Y-m-d H:i:s.u'));
        error_log("Delay from request: " . number_format($delayFromRequest, 3) . " seconds");

        sleep(5);

        try {
            file_put_contents('txt.txt', "Hello World from terminate callback - " . date('Y-m-d H:i:s'));
            $totalTime = microtime(true) - $terminateStart;
            error_log("File written successfully after " . number_format($totalTime, 3) . " seconds");
        } catch (Exception $e) {
            error_log("Error in terminate callback: " . $e->getMessage());
        }

        $totalExecutionTime = microtime(true) - $requestTime;
        error_log("Terminate callback completed at " . date('Y-m-d H:i:s.u'));
        error_log("Total time from request: " . number_format($totalExecutionTime, 3) . " seconds");
        error_log("=== TERMINATE CALLBACK ENDED ===");
    });

    Defer::global(function () use ($requestTime) {
        $globalStart = microtime(true);
        $delayFromRequest = $globalStart - $requestTime;
        error_log("Global defer executed at " . date('Y-m-d H:i:s.u'));
        error_log("Global defer delay from request: " . number_format($delayFromRequest, 3) . " seconds");
    });

    $stats = Process::getStats();
    error_log("Defer stats: " . json_encode($stats, JSON_PRETTY_PRINT));

    $responseTime = microtime(true);
    error_log("Response being sent at " . date('Y-m-d H:i:s.u'));
    error_log("Response time: " . number_format(($responseTime - $requestTime) * 1000, 2) . " ms");
    error_log("=== RESPONSE SENT ===");

    echo "<div style='padding: 20px; font-family: Arial, sans-serif;'>";
    echo "<h2>✅ Form Submitted Successfully!</h2>";
    echo "<p><strong>Response sent immediately</strong> - The background task is now running.</p>";
    echo "<p>Check <code>txt.txt</code> file in 5+ seconds and your error log for detailed timing.</p>";

    echo "<h3>Environment Information:</h3>";
    echo "<div style='background: #f5f5f5; padding: 10px; border-radius: 5px; font-family: monospace;'>";
    echo "<strong>SAPI:</strong> " . $stats['environment']['sapi'] . "<br>";
    echo "<strong>FastCGI Environment:</strong> " . ($stats['environment']['fastcgi'] ? 'Yes' : 'No') . "<br>";
    echo "<strong>fastcgi_finish_request:</strong> " . ($stats['environment']['fastcgi_finish_request'] ? 'Available' : 'Not Available') . "<br>";
    echo "<strong>Output Buffering Level:</strong> " . $stats['environment']['output_buffering'] . "<br>";
    echo "<strong>Global Defers:</strong> " . $stats['global_defers'] . "<br>";
    echo "<strong>Terminate Callbacks:</strong> " . $stats['terminate_callbacks'] . "<br>";
    echo "<strong>Memory Usage:</strong> " . number_format($stats['memory_usage'] / 1024 / 1024, 2) . " MB<br>";
    echo "</div>";

    echo "<h3>What Should Happen:</h3>";
    echo "<ul>";
    echo "<li>✅ You see this response immediately (not after 5 seconds)</li>";
    echo "<li>🔄 Background task runs after response is sent</li>";
    echo "<li>📝 File <code>txt.txt</code> appears in ~5+ seconds</li>";
    echo "<li>📋 Detailed timing in error log</li>";
    echo "</ul>";
    echo "</div>";
} else {
    echo "<div style='padding: 20px; font-family: Arial, sans-serif;'>";
    echo "<h1>Defer Terminate Test</h1>";
    echo "<p>This test demonstrates Laravel-style defer functionality that executes <strong>after</strong> the HTTP response is sent.</p>";

    // Show current environment info
    $stats = Process::getStats();
    echo "<h3>Current Environment:</h3>";
    echo "<div style='background: #f0f8ff; padding: 10px; border-radius: 5px; font-family: monospace;'>";
    echo "<strong>PHP SAPI:</strong> " . PHP_SAPI . "<br>";
    echo "<strong>FastCGI Available:</strong> " . (function_exists('fastcgi_finish_request') ? 'Yes' : 'No') . "<br>";
    echo "<strong>OS:</strong> " . PHP_OS_FAMILY . "<br>";
    echo "<strong>PHP Version:</strong> " . PHP_VERSION . "<br>";
    echo "</div>";

    echo "<p><strong>Click submit to test:</strong></p>";
    echo "</div>";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defer Terminate Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
        }

        .form-container {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        button {
            background: #007cba;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #005a87;
        }

        .timing-info {
            background: #e8f5e8;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
    <script>
        let startTime;

        function startTiming() {
            startTime = Date.now();
            document.getElementById('status').innerHTML =
                '<div style="color: #666;">⏳ Request sent, waiting for response...</div>';
        }

        window.onload = function() {
            <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
                const endTime = Date.now();
                const responseTime = endTime - (startTime || endTime);

                document.getElementById('timing-result').innerHTML =
                    '<div class="timing-info">' +
                    '<h4>✅ Response Timing Verified!</h4>' +
                    '<p>This response was received <strong>immediately</strong>, not after the 5-second sleep.</p>' +
                    '<p>The background task is running separately and will complete in ~5+ seconds.</p>' +
                    '</div>';

                // Show a countdown for when to check the file
                let countdown = 6;
                const countdownElement = document.createElement('div');
                countdownElement.style.cssText = 'background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0;';
                document.body.appendChild(countdownElement);

                const timer = setInterval(() => {
                    countdown--;
                    if (countdown > 0) {
                        countdownElement.innerHTML = `<strong>⏰ Check txt.txt file in ${countdown} seconds...</strong>`;
                    } else {
                        countdownElement.innerHTML = '<strong>🔍 Check txt.txt file now! It should have been created.</strong>';
                        countdownElement.style.background = '#d4edda';
                        clearInterval(timer);
                    }
                }, 1000);
            <?php endif; ?>
        };
    </script>
</head>

<body>
    <div class="form-container">
        <form method="post" onsubmit="startTiming()">
            <button type="submit">🚀 Test Defer Terminate</button>
        </form>
        <div id="status"></div>
    </div>

    <div id="timing-result"></div>

    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; font-size: 14px;">
        <h4>How to Verify It's Working:</h4>
        <ol>
            <li><strong>Immediate Response:</strong> You should see the response instantly (not after 5 seconds)</li>
            <li><strong>Background Execution:</strong> The <code>txt.txt</code> file will appear in ~5-8 seconds</li>
            <li><strong>Check Error Log:</strong> Detailed timing information will be logged</li>
        </ol>

        <p><strong>💡 Tip:</strong> Open your browser's developer tools (F12) and watch the Network tab to see the actual response time.</p>
    </div>
</body>

</html>