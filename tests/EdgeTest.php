<?php
declare(strict_types=1);

namespace StefanakMichal\PhpShmGraph\Tests;

use StefanakMichal\PhpShmGraph\Graph;

class EdgeTest extends TestLayer
{
    public function testEdgePersistsAfterWritingProcessExits(): void
    {
        $helperScript = __DIR__ . '/helpers/write_edge.php';

        // ── Process 1 ─────────────────────────────────────────────────────────
        // Spawn a separate PHP process that creates GraphDB, stores an Edge, and
        // exits *without* calling destroy(), leaving shared memory intact.
        $cmd    = PHP_BINARY . ' ' . escapeshellarg($helperScript)
                . ' ' . escapeshellarg($this->storagePath) . ' 2>&1';
        $output = trim((string) shell_exec($cmd));

        $this->assertNotEmpty($output, 'Child process produced no output');
        $this->assertMatchesRegularExpression(
            '/^\d+$/',
            $output,
            "Expected a numeric edge ID from the child process, got: $output"
        );

        $edgeId = (int) $output;
        $this->assertGreaterThan(0, $edgeId, 'Edge ID must be positive');

        // ── Process 2 (this process) ──────────────────────────────────────────
        // Attach to the shared memory segments that the child process left behind.
        // No child process is running at this point.
        $db   = new Graph($this->storagePath);
        $edge = $db->getEdge($edgeId);

        $this->assertNotNull(
            $edge,
            "Edge {$edgeId} must be retrievable from shared memory after the writing process has exited"
        );
        $this->assertInstanceOf(
            \StefanakMichal\PhpShmGraph\Edge::class,
            $edge,
            "Expected getEdge() to return an Edge instance for edge ID {$edgeId}"
        );
        $this->assertSame($edgeId, $edge->id);
        $this->assertSame('KNOWS', $edge->type);
        $this->assertSame(2020, $edge->properties['since']);
    }

    public function testEdgeUpdatePersistsAcrossProcesses(): void
    {
        $writeScript  = __DIR__ . '/helpers/write_edge.php';
        $updateScript = __DIR__ . '/helpers/update_edge.php';

        // ── Process 1: write the initial edge ─────────────────────────────────
        $cmd    = PHP_BINARY . ' ' . escapeshellarg($writeScript)
                . ' ' . escapeshellarg($this->storagePath) . ' 2>&1';
        $output = trim((string) shell_exec($cmd));

        $this->assertMatchesRegularExpression('/^\d+$/', $output, "write_edge.php: $output");
        $edgeId = (int) $output;

        // Read the node IDs from shared memory so we can pass them to the update helper.
        $db   = new Graph($this->storagePath);
        $edge = $db->getEdge($edgeId);
        $this->assertNotNull($edge);

        $fromNodeId = $edge->from;
        $toNode = $db->getNode($edge->to);
        $this->assertNotNull($toNode);

        $newFromNode = $db->addNode(['Person'], ['name' => 'Josh', 'age' => 40]);

        // ── Process 2: update the edge and exit ───────────────────────────────
        $cmd    = PHP_BINARY . ' ' . escapeshellarg($updateScript)
                . ' ' . escapeshellarg($this->storagePath)
                . ' ' . $edgeId
                . ' ' . $newFromNode->id
                . ' ' . $toNode->id . ' 2>&1';
        $output = trim((string) shell_exec($cmd));

        $this->assertSame((string) $edgeId, $output, "update_edge.php: $output");

        // ── Process 3 (this process): verify the update is visible ────────────
        $db   = new Graph($this->storagePath);
        $edge = $db->getEdge($edgeId);

        $this->assertNotNull($edge, "Edge {$edgeId} must still exist after update");
        $this->assertSame($edgeId, $edge->id);
        $this->assertSame('LIKES', $edge->type);
        $this->assertSame(2024, $edge->properties['since']);
        $this->assertSame($newFromNode->id, $edge->from);

        $fromNode = $db->getNode($fromNodeId);
        $this->assertNotNull($fromNode);
        $this->assertNotContains($edgeId, $fromNode->outgoing, "Edge {$edgeId} must no longer be outgoing from node {$fromNode->id}");
    }

    public function testEdgeRemovalPersistsAcrossProcesses(): void
    {
        $writeScript  = __DIR__ . '/helpers/write_edge.php';
        $removeScript = __DIR__ . '/helpers/remove_edge.php';

        // ── Process 1: write the edge ─────────────────────────────────────────
        $cmd    = PHP_BINARY . ' ' . escapeshellarg($writeScript)
                . ' ' . escapeshellarg($this->storagePath) . ' 2>&1';
        $output = trim((string) shell_exec($cmd));

        $this->assertMatchesRegularExpression('/^\d+$/', $output, "write_edge.php: $output");
        $edgeId = (int) $output;

        // ── Process 2: remove the edge and exit ───────────────────────────────
        $cmd    = PHP_BINARY . ' ' . escapeshellarg($removeScript)
                . ' ' . escapeshellarg($this->storagePath)
                . ' ' . $edgeId . ' 2>&1';
        $output = trim((string) shell_exec($cmd));

        $this->assertSame('removed', $output, "remove_edge.php: $output");

        // ── Process 3 (this process): verify the edge is gone ────────────────
        $db   = new Graph($this->storagePath);
        $edge = $db->getEdge($edgeId);

        $this->assertNull($edge, "Edge {$edgeId} must be absent from shared memory after removal");
    }

    public function testUpdateNonExistentEdgeThrowsException(): void
    {
        $db = new Graph($this->storagePath);

        // We need two real nodes for the signature; the edge ID is bogus.
        $node1 = $db->addNode(['A'], []);
        $node2 = $db->addNode(['B'], []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/i');

        $db->updateEdge(999999, $node1->id, $node2->id, 'TYPE', []);
    }
}
