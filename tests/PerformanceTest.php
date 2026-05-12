<?php
declare(strict_types=1);

namespace StefanakMichal\PhpShmGraph\Tests;

use StefanakMichal\PhpShmGraph\Graph;

class PerformanceTest extends TestLayer
{
    private int $amount = 10000;

    public function testWritePerformance(): void
    {
        $db = new Graph($this->storagePath);

        $startTime = microtime(true);
        for ($i = 0; $i < $this->amount; $i++) {
            $db->addNode(['Person'], ['name' => "Person {$i}", 'age' => 20 + ($i % 30)]);
        }
        $endTime = microtime(true);

        $duration = $endTime - $startTime;
        $this->assertLessThan(
            1,
            $duration,
            "Expected to write {$this->amount} nodes in under 1 second, but took {$duration} seconds"
        );
    }

    public function testReadPerformance(): void
    {
        $db = new Graph($this->storagePath);

        // Pre-populate the graph with nodes.
        for ($i = 0; $i < $this->amount; $i++) {
            $db->addNode(['Person'], ['name' => "Person {$i}", 'age' => 20 + ($i % 30)]);
        }

        $startTime = microtime(true);
        for ($i = 1; $i <= $this->amount; $i++) {
            $node = $db->getNode($i);
            // Access some properties to simulate a realistic read.
            if ($node) {
                $_ = $node->properties['name'];
                $_ = $node->properties['age'];
            }
        }
        $endTime = microtime(true);

        $duration = $endTime - $startTime;
        $this->assertLessThan(
            1,
            $duration,
            "Expected to read {$this->amount} nodes in under 1 second, but took {$duration} seconds"
        );
    }
}
