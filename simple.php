<?php

use Library\Defer\Defer;
use Library\Defer\Parallel;

require 'vendor/autoload.php';

$start_time = microtime(true);

$results = Parallel::all([
    'task_A' => function () {
        sleep(2);
        return "Task A (slept for 2 seconds) finished successfully.";
    },
    'task_B' => function () {
        sleep(3);
        return "Task B (slept for 3 seconds) finished successfully.";
    }
]);

$result_A = $results['task_A'];
$result_B = $results['task_B'];

$end_time = microtime(true);
$total_duration = $end_time - $start_time;

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defer Library - Parallel Execution Test</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">

        <!-- Header -->
        <div class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-t-2xl p-8 text-center">
            <h1 class="text-4xl font-bold mb-2">Defer Library Test</h1>
            <p class="text-xl opacity-90">Parallel Execution Demonstration</p>
        </div>

        <!-- Main Content -->
        <div class="bg-white rounded-b-2xl shadow-2xl overflow-hidden">
            <div class="p-8">

                <!-- Test Setup -->
                <div class="mb-8">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                        <span class="bg-indigo-100 text-indigo-600 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold mr-3">1</span>
                        Test Setup
                    </h2>
                    <div class="bg-gray-50 border-l-4 border-indigo-500 p-6 rounded-r-lg">
                        <ol class="list-decimal list-inside space-y-2 text-gray-700">
                            <li>Dispatched <span class="font-semibold text-indigo-600">Task A</span> - background job taking <span class="font-semibold">2 seconds</span></li>
                            <li>Immediately dispatched <span class="font-semibold text-purple-600">Task B</span> - background job taking <span class="font-semibold">3 seconds</span></li>
                            <li>Main script waited for both tasks using <code class="bg-gray-200 px-2 py-1 rounded text-sm">Defer::awaitTaskAll()</code></li>
                        </ol>
                    </div>
                </div>

                <!-- Expected Outcome -->
                <div class="mb-8">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Expected Outcome</h3>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                            <h4 class="font-semibold text-red-800 mb-2">Sequential Execution</h4>
                            <p class="text-red-700 text-sm">Total time: <span class="font-bold">5+ seconds</span> (2s + 3s + overhead)</p>
                        </div>
                        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                            <h4 class="font-semibold text-green-800 mb-2">Parallel Execution</h4>
                            <p class="text-green-700 text-sm">Total time: <span class="font-bold">~3 seconds</span> (longest task + overhead)</p>
                        </div>
                    </div>
                </div>

                <hr class="border-gray-300 my-8">

                <!-- Results Section -->
                <div class="mb-8">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                        <span class="bg-green-100 text-green-600 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold mr-3">✓</span>
                        Live Results
                    </h2>

                    <!-- Task Results -->
                    <div class="grid md:grid-cols-2 gap-6 mb-6">
                        <div class="bg-indigo-50 border-l-4 border-indigo-500 p-4 rounded-r-lg">
                            <h4 class="font-semibold text-indigo-800 mb-2">Task A Result</h4>
                            <p class="text-gray-700"><?php echo htmlspecialchars($result_A); ?></p>
                        </div>
                        <div class="bg-purple-50 border-l-4 border-purple-500 p-4 rounded-r-lg">
                            <h4 class="font-semibold text-purple-800 mb-2">Task B Result</h4>
                            <p class="text-gray-700"><?php echo htmlspecialchars($result_B); ?></p>
                        </div>
                    </div>

                    <!-- Timing Display -->
                    <div class="bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-xl p-6 text-center">
                        <h3 class="text-lg font-semibold mb-2">Total Page Load Time</h3>
                        <div class="text-5xl font-bold mb-2"><?php echo number_format($total_duration, 2); ?>s</div>
                        <p class="opacity-90">Measured from start to finish</p>
                    </div>
                </div>

                <hr class="border-gray-300 my-8">

                <!-- Conclusion -->
                <div class="text-center">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6">Conclusion</h2>

                    <?php if ($total_duration < 4): ?>
                        <div class="bg-green-50 border-2 border-green-200 rounded-xl p-6">
                            <div class="text-6xl mb-4">🎉</div>
                            <h3 class="text-2xl font-bold text-green-800 mb-4">Success!</h3>
                            <p class="text-green-700 text-lg mb-4">
                                Total time was approximately <span class="font-bold"><?php echo number_format($total_duration, 2); ?> seconds</span>, not 5+ seconds.
                            </p>
                            <div class="bg-white border border-green-300 rounded-lg p-4 inline-block">
                                <p class="text-green-800">
                                    <span class="font-semibold">Proof of parallel execution:</span> Task A and Task B ran simultaneously
                                    in separate processes, even from a single web request.
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="bg-red-50 border-2 border-red-200 rounded-xl p-6">
                            <div class="text-6xl mb-4">⚠️</div>
                            <h3 class="text-2xl font-bold text-red-800 mb-4">Sequential Execution Detected</h3>
                            <p class="text-red-700 text-lg mb-4">
                                Total time was <span class="font-bold"><?php echo number_format($total_duration, 2); ?> seconds</span> - over 4 seconds.
                            </p>
                            <div class="bg-white border border-red-300 rounded-lg p-4 inline-block">
                                <p class="text-red-800">
                                    This indicates the tasks may have run sequentially rather than in parallel.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- Footer -->
        <div class="text-center mt-8 text-gray-600">
            <p class="text-sm">Powered by the Defer Library | Test completed at <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>

    </div>
</body>

</html>