<?php

use Library\Defer\Defer;

require 'vendor/autoload.php';

$start_time = microtime(true);



$task_A_id = Defer::background(function () {
    sleep(2);
    return "Task A (slept for 2 seconds) finished successfully.";
});


$task_B_id = Defer::background(function () {
    sleep(3);
    return "Task B (slept for 3 seconds) finished successfully.";
});



$result_A = Defer::awaitTask($task_A_id);

$result_B = Defer::awaitTask($task_B_id);

$end_time = microtime(true);
$total_duration = $end_time - $start_time;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Defer Library - Parallel Execution Test</title>
</head>
<body style="font-family: sans-serif; line-height: 1.6;">

    <h1>Proof of Parallel Execution in a Web Context</h1>

    <p>This page demonstrates the parallel processing capability of the <strong>Defer</strong> library.</p>

    <h2>Test Setup</h2>
    <ol>
        <li>The page dispatched <strong>Task A</strong>, a background job set to take <strong>2 seconds</strong>.</li>
        <li>It immediately dispatched <strong>Task B</strong>, a background job set to take <strong>3 seconds</strong>.</li>
        <li>The main script then waited for both tasks to complete using <code>Defer::awaitTask()</code>.</li>
    </ol>

    <h3>Expected Outcome</h3>
    <ul>
        <li><strong>If tasks ran sequentially:</strong> The total time would be over 5 seconds (2s for Task A + 3s for Task B + overhead).</li>
        <li><strong>If tasks ran in parallel:</strong> The total time should be just over 3 seconds (the duration of the longest task + overhead).</li>
    </ul>

    <hr>

    <h2>Live Results</h2>
    
    <p>The results from the background tasks have been successfully retrieved:</p>
    <ul>
        <li><strong>Result from Task A:</strong> <?php echo htmlspecialchars($result_A); ?></li>
        <li><strong>Result from Task B:</strong> <?php echo htmlspecialchars($result_B); ?></li>
    </ul>

    <p style="font-size: 1.2em;">
        <strong>Total Page Load Time: <?php echo number_format($total_duration, 2); ?> seconds</strong>
    </p>
    
    <hr>

    <h2>Conclusion</h2>

    <?php if ($total_duration < 4): ?>
        <p style="color: green; font-weight: bold;">
            Success! The total time was approximately 3 seconds, not 5+ seconds.
        </p>
        <p>
            This definitively proves that Task A and Task B were executing at the same time in separate, parallel processes, even though this was initiated from a single web request.
        </p>
    <?php else: ?>
        <p style="color: red; font-weight: bold;">
            Test indicates sequential execution. The total time was over 4 seconds.
        </p>
    <?php endif; ?>

</body>
</html>