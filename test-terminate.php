<?php

require 'vendor/autoload.php';

use Library\Defer\Handlers\TerminateHandler;

class MockTerminateHandler extends TerminateHandler
{
    private int $mockStatusCode;

    public function __construct(int $statusCode)
    {
        $this->mockStatusCode = $statusCode;
    }

    protected function getHttpResponseCode(): int
    {
        return $this->mockStatusCode;
    }
}

class CleanTerminateTest
{
    private int $testCount = 0;
    private int $passCount = 0;

    public function run(): void
    {
        echo "🧪 Clean Defer::terminate() Tests\n";
        echo "==================================\n\n";

        $this->testBasicFunctionality();
        $this->testAlwaysFlag();
        $this->testMultipleCallbacks();
        
        $this->printResults();
    }

    private function testBasicFunctionality(): void
    {
        echo "📋 Basic Status Code Tests\n";

        // Test 200 - should execute
        $this->runTest('200 Status - Should Execute', function () {
            $executed = false;
            $handler = new MockTerminateHandler(200);
            
            $handler->addCallback(function () use (&$executed) {
                $executed = true;
            });
            
            $handler->executeCallbacks();
            
            return $executed === true;
        });

        // Test 404 - should not execute
        $this->runTest('404 Status - Should Not Execute', function () {
            $executed = false;
            $handler = new MockTerminateHandler(404);
            
            $handler->addCallback(function () use (&$executed) {
                $executed = true;
            });
            
            $handler->executeCallbacks();
            
            return $executed === false;
        });

        // Test 500 - should not execute
        $this->runTest('500 Status - Should Not Execute', function () {
            $executed = false;
            $handler = new MockTerminateHandler(500);
            
            $handler->addCallback(function () use (&$executed) {
                $executed = true;
            });
            
            $handler->executeCallbacks();
            
            return $executed === false;
        });

        echo "\n";
    }

    private function testAlwaysFlag(): void
    {
        echo "🔄 Always Flag Tests\n";

        // Test always=true with 404
        $this->runTest('404 Status with always=true - Should Execute', function () {
            $executed = false;
            $handler = new MockTerminateHandler(404);
            
            $handler->addCallback(function () use (&$executed) {
                $executed = true;
            }, true);
            
            $handler->executeCallbacks();
            
            return $executed === true;
        });

        // Test always=true with 500
        $this->runTest('500 Status with always=true - Should Execute', function () {
            $executed = false;
            $handler = new MockTerminateHandler(500);
            
            $handler->addCallback(function () use (&$executed) {
                $executed = true;
            }, true);
            
            $handler->executeCallbacks();
            
            return $executed === true;
        });

        echo "\n";
    }

    private function testMultipleCallbacks(): void
    {
        echo "📚 Multiple Callbacks Test\n";

        $this->runTest('Mixed always flags with 404', function () {
            $results = [];
            $handler = new MockTerminateHandler(404);
            
            // Should not execute (always=false, error status)
            $handler->addCallback(function () use (&$results) {
                $results[] = 'should_not_execute';
            }, false);
            
            // Should execute (always=true)
            $handler->addCallback(function () use (&$results) {
                $results[] = 'should_execute';
            }, true);
            
            // Should not execute (always=false, error status)  
            $handler->addCallback(function () use (&$results) {
                $results[] = 'should_not_execute_2';
            }, false);
            
            $handler->executeCallbacks();
            
            // Should only have one result: 'should_execute'
            return count($results) === 1 && $results[0] === 'should_execute';
        });

        echo "\n";
    }

    private function runTest(string $name, callable $test): bool
    {
        $this->testCount++;
        
        try {
            $result = $test();
            
            if ($result) {
                echo "  ✅ {$name}\n";
                $this->passCount++;
                return true;
            } else {
                echo "  ❌ {$name}\n";
                return false;
            }
        } catch (Throwable $e) {
            echo "  ❌ {$name} - Exception: {$e->getMessage()}\n";
            return false;
        }
    }

    private function printResults(): void
    {
        echo "=====================================\n";
        echo "📊 Test Results\n";
        echo "=====================================\n";
        echo "Total Tests: {$this->testCount}\n";
        echo "Passed: {$this->passCount}\n";
        echo "Failed: " . ($this->testCount - $this->passCount) . "\n";
        
        $successRate = ($this->passCount / $this->testCount) * 100;
        echo "Success Rate: " . number_format($successRate, 1) . "%\n\n";
        
        if ($successRate == 100) {
            echo "🎉 All tests passed! Your terminate functionality is working correctly.\n";
        } else {
            echo "❌ Some tests failed. Check your TerminateHandler implementation.\n";
        }
    }
}

// Run the clean test
$tester = new CleanTerminateTest();
$tester->run();