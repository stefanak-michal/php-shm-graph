<?php
declare(strict_types=1);

namespace StefanakMichal\PhpShmGraph\Tests;

use StefanakMichal\PhpShmGraph\Graph;

class SegmentationTest extends TestLayer
{
    public function testNodesOverflowIntoNewSegment(): void
    {
        // A small segment forces overflow after a handful of nodes.
        // Each node with 300 bytes of padding serialises to ~500+ bytes;
        // at 8 KB per segment that accommodates roughly 15 nodes, so 30 nodes
        // guarantees at least 2 segments are allocated.
        $db = new Graph($this->storagePath, 8192);

        $nodeIds = [];
        for ($i = 0; $i < 30; $i++) {
            $node      = $db->addNode(['Label'], ['i' => $i, 'pad' => str_repeat('X', 300)]);
            $nodeIds[] = $node->id;
        }

        // Verify that a second segment was actually allocated.
        $prop     = new \ReflectionProperty(Graph::class, 'nodeSegCount');
        $segCount = $prop->getValue($db);

        $this->assertGreaterThan(
            1,
            $segCount,
            "Expected nodes to overflow into ≥2 segments, got {$segCount}"
        );

        // Every node must still be retrievable regardless of which segment it lives in.
        foreach ($nodeIds as $id) {
            $node = $db->getNode($id);
            $this->assertNotNull($node, "Node {$id} must be readable after segment overflow");
            $this->assertSame($id, $node->id);
        }
    }

    public function testEdgesOverflowIntoNewSegment(): void
    {
        // A small segment forces overflow after a handful of edges.
        $db = new Graph($this->storagePath, 8192);

        $node1 = $db->addNode(['Label'], ['name' => 'Node1']);
        $node2 = $db->addNode(['Label'], ['name' => 'Node2']);

        $edgeIds = [];
        for ($i = 0; $i < 30; $i++) {
            $edge      = $db->addEdge($node1->id, $node2->id, 'TYPE', ['i' => $i, 'pad' => str_repeat('X', 300)]);
            $edgeIds[] = $edge->id;
        }

        // Verify that a second segment was actually allocated.
        $prop     = new \ReflectionProperty(Graph::class, 'edgeSegCount');
        $segCount = $prop->getValue($db);

        $this->assertGreaterThan(
            1,
            $segCount,
            "Expected edges to overflow into ≥2 segments, got {$segCount}"
        );

        // Every edge must still be retrievable regardless of which segment it lives in.
        foreach ($edgeIds as $id) {
            $edge = $db->getEdge($id);
            $this->assertNotNull($edge, "Edge {$id} must be readable after segment overflow");
            $this->assertSame($id, $edge->id);
        }
    }
}
