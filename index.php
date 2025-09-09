<?php

use Library\Defer\Defer;

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

    $stats = Defer::getStats();
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
    // Show comprehensive environment information
    $stats = Defer::getStats();
    
    echo "<div style='padding: 20px; font-family: Arial, sans-serif;'>";
    echo "<h1>🔍 Defer Environment Diagnostics</h1>";
    echo "<p>This page shows detailed environment information to diagnose defer functionality.</p>";
    
    // Basic PHP Environment
    echo "<h3>📋 PHP Environment:</h3>";
    echo "<div style='background: #f0f8ff; padding: 15px; border-radius: 5px; font-family: monospace; margin-bottom: 20px;'>";
    echo "<strong>PHP Version:</strong> " . PHP_VERSION . "<br>";
    echo "<strong>PHP SAPI:</strong> " . PHP_SAPI . "<br>";
    echo "<strong>OS Family:</strong> " . PHP_OS_FAMILY . "<br>";
    echo "<strong>OS:</strong> " . php_uname() . "<br>";
    echo "<strong>Server Software:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Not Available') . "<br>";
    echo "<strong>Gateway Interface:</strong> " . ($_SERVER['GATEWAY_INTERFACE'] ?? 'Not Available') . "<br>";
    echo "</div>";
    
    // FastCGI Detection
    echo "<h3>🚀 FastCGI Detection:</h3>";
    echo "<div style='background: #f0fff0; padding: 15px; border-radius: 5px; font-family: monospace; margin-bottom: 20px;'>";
    echo "<strong>Current SAPI:</strong> " . PHP_SAPI . "<br>";
    echo "<strong>Is FPM-FCGI:</strong> " . (PHP_SAPI === 'fpm-fcgi' ? '✅ Yes' : '❌ No') . "<br>";
    echo "<strong>Is CGI-FCGI:</strong> " . (PHP_SAPI === 'cgi-fcgi' ? '✅ Yes' : '❌ No') . "<br>";
    echo "<strong>fastcgi_finish_request() exists:</strong> " . (function_exists('fastcgi_finish_request') ? '✅ Yes' : '❌ No') . "<br>";
    
    // Check CGI environment variables
    $cgiVars = [
        'FCGI_ROLE' => $_SERVER['FCGI_ROLE'] ?? 'Not Set',
        'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? 'Not Set',
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'Not Set',
        'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? 'Not Set',
        'CONTENT_LENGTH' => $_SERVER['CONTENT_LENGTH'] ?? 'Not Set',
    ];
    
    foreach ($cgiVars as $var => $value) {
        echo "<strong>{$var}:</strong> {$value}<br>";
    }
    echo "</div>";
    
    // Output Buffering
    echo "<h3>📤 Output Buffering:</h3>";
    echo "<div style='background: #fff8dc; padding: 15px; border-radius: 5px; font-family: monospace; margin-bottom: 20px;'>";
    echo "<strong>Current OB Level:</strong> " . ob_get_level() . "<br>";
    echo "<strong>OB Status:</strong><br>";
    $obStatus = ob_get_status(true);
    if (empty($obStatus)) {
        echo "  No output buffers active<br>";
    } else {
        foreach ($obStatus as $i => $buffer) {
            echo "  Buffer {$i}: {$buffer['name']} (Level: {$buffer['level']}, Size: {$buffer['buffer_size']})<br>";
        }
    }
    echo "<strong>Output Handler:</strong> " . (ini_get('output_handler') ?: 'None') . "<br>";
    echo "<strong>Implicit Flush:</strong> " . (ini_get('implicit_flush') ? 'On' : 'Off') . "<br>";
    echo "</div>";
    
    // Process Information
    echo "<h3>⚙️ Process Information:</h3>";
    echo "<div style='background: #ffe4e1; padding: 15px; border-radius: 5px; font-family: monospace; margin-bottom: 20px;'>";
    echo "<strong>Process ID:</strong> " . (function_exists('getmypid') ? getmypid() : 'Not Available') . "<br>";
    echo "<strong>Parent Process ID:</strong> " . (function_exists('posix_getppid') ? posix_getppid() : 'Not Available') . "<br>";
    echo "<strong>User ID:</strong> " . (function_exists('posix_getuid') ? posix_getuid() : 'Not Available') . "<br>";
    echo "<strong>Group ID:</strong> " . (function_exists('posix_getgid') ? posix_getgid() : 'Not Available') . "<br>";
    echo "</div>";
    
    // Server Variables
    echo "<h3>🌐 Server Variables (HTTP Related):</h3>";
    echo "<div style='background: #f5f5dc; padding: 15px; border-radius: 5px; font-family: monospace; margin-bottom: 20px; max-height: 200px; overflow-y: auto;'>";
    $httpVars = array_filter($_SERVER, function($key) {
        return strpos($key, 'HTTP_') === 0 || 
               in_array($key, ['REQUEST_URI', 'QUERY_STRING', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT']);
    }, ARRAY_FILTER_USE_KEY);
    
    foreach ($httpVars as $key => $value) {
        echo "<strong>{$key}:</strong> " . htmlspecialchars($value) . "<br>";
    }
    echo "</div>";
    
    // Function Availability
    echo "<h3>🔧 Function Availability:</h3>";
    echo "<div style='background: #e6e6fa; padding: 15px; border-radius: 5px; font-family: monospace; margin-bottom: 20px;'>";
    $functions = [
        'fastcgi_finish_request',
        'apache_setenv', 
        'connection_aborted',
        'connection_status',
        'ignore_user_abort',
        'register_shutdown_function',
        'pcntl_signal',
        'posix_getpid',
        'posix_getppid',
        'sapi_windows_set_ctrl_handler'
    ];
    
    foreach ($functions as $func) {
        $available = function_exists($func) ? '✅' : '❌';
        echo "<strong>{$func}:</strong> {$available}<br>";
    }
    echo "</div>";
    
    // Defer Stats
    echo "<h3>📊 Defer Library Stats:</h3>";
    echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 5px; font-family: monospace; margin-bottom: 20px;'>";
    echo "<strong>Global Defers:</strong> " . $stats['global_defers'] . "<br>";
    echo "<strong>Terminate Callbacks:</strong> " . $stats['terminate_callbacks'] . "<br>";
    echo "<strong>Memory Usage:</strong> " . number_format($stats['memory_usage'] / 1024 / 1024, 2) . " MB<br>";
    echo "<strong>Detected FastCGI:</strong> " . ($stats['environment']['fastcgi'] ? '✅ Yes' : '❌ No') . "<br>";
    echo "<strong>fastcgi_finish_request Available:</strong> " . ($stats['environment']['fastcgi_finish_request'] ? '✅ Yes' : '❌ No') . "<br>";
    echo "<strong>Output Buffering Active:</strong> " . ($stats['environment']['output_buffering'] ? '✅ Yes' : '❌ No') . "<br>";
    echo "</div>";
    
    // Test Form
    echo "<div class='form-container'>";
    echo "<h3>🧪 Test Defer Functionality:</h3>";
    echo "<p>Click the button below to test if terminate callbacks execute after the response is sent.</p>";
    echo "<form method='post' onsubmit='startTiming()'>";
    echo "<button type='submit'>🚀 Test Defer Terminate</button>";
    echo "</form>";
    echo "<div id='status'></div>";
    echo "</div>";
    
    echo "</div>";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defer Environment Diagnostics</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
            background-color: #fafafa;
        }
        .form-container {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
        h3 {
            color: #333;
            border-bottom: 2px solid #007cba;
            padding-bottom: 5px;
        }
        .diagnostic-section {
            margin-bottom: 25px;
        }
    </style>
    <script>
        let startTime;
        
        function startTiming() {
            startTime = Date.now();
            document.getElementById('status').innerHTML = 
                '<div style="color: #666;">⏳ Request sent at ' + new Date().toLocaleTimeString() + ', waiting for response...</div>';
        }
        
        window.onload = function() {
            <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
            const endTime = Date.now();
            const responseTime = endTime - (startTime || endTime);
            
            document.getElementById('timing-result').innerHTML = 
                '<div class="timing-info">' +
                '<h4>✅ Response Timing Analysis</h4>' +
                '<p><strong>Response received at:</strong> ' + new Date().toLocaleTimeString() + '</p>' +
                '<p>If you see this response <strong>immediately</strong> (not after 5 seconds), then the defer mechanism has a problem.</p>' +
                '<p><strong>Expected behavior:</strong> Immediate response, background task runs separately.</p>' +
                '</div>';
            
            // Countdown timer for file check
            let countdown = 8;
            const countdownElement = document.createElement('div');
            countdownElement.style.cssText = 'background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0; border: 1px solid #ffeaa7;';
            document.body.appendChild(countdownElement);
            
            const timer = setInterval(() => {
                countdown--;
                if (countdown > 0) {
                    countdownElement.innerHTML = `<strong>⏰ Check txt.txt file in ${countdown} seconds...</strong><br><small>The background task should create this file after the 5-second delay.</small>`;
                } else {
                    countdownElement.innerHTML = '<strong>🔍 Check txt.txt file now!</strong><br><small>If the file exists, the terminate callback executed successfully.</small>';
                    countdownElement.style.background = '#d4edda';
                    countdownElement.style.borderColor = '#c3e6cb';
                    clearInterval(timer);
                }
            }, 1000);
            <?php endif; ?>
        };
    </script>
</head>
<body>
    <div id="timing-result"></div>
    
    <div style="background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #007cba;">
        <h4>🔍 Diagnostic Information</h4>
        <p>This page shows comprehensive environment details to help diagnose why defer terminate callbacks might not be working as expected.</p>
        <p><strong>Key things to look for:</strong></p>
        <ul>
            <li><strong>FastCGI Environment:</strong> Should be "Yes" for proper defer functionality</li>
            <li><strong>fastcgi_finish_request:</strong> Should be "Available"</li>
            <li><strong>Response Timing:</strong> Should be immediate, not delayed by background tasks</li>
        </ul>
    </div>
</body>
</html>