<?php
declare(strict_types=1);

namespace StefanakMichal\PhpShmGraph\Tests;

use StefanakMichal\PhpShmGraph\Graph;

class NodeTest extends TestLayer
{
    public function testNodePersistsAfterWritingProcessExits(): void
    {
        $helperScript = __DIR__ . '/helpers/write_node.php';

        // ── Process 1 ─────────────────────────────────────────────────────────
        // Spawn a separate PHP process that creates GraphDB, stores a Node, and
        // exits *without* calling destroy(), leaving shared memory intact.
        $cmd    = PHP_BINARY . ' ' . escapeshellarg($helperScript)
                . ' ' . escapeshellarg($this->storagePath) . ' 2>&1';
        $output = trim((string) shell_exec($cmd));

        $this->assertNotEmpty($output, 'Child process produced no output');
        $this->assertMatchesRegularExpression(
            '/^\d+$/',
            $output,
            "Expected a numeric node ID from the child process, got: $output"
        );

        $nodeId = (int) $output;
        $this->assertGreaterThan(0, $nodeId, 'Node ID must be positive');

        // ── Process 2 (this process) ──────────────────────────────────────────
        // Attach to the shared memory segments that the child process left behind.
        // No child process is running at this point.
        $db   = new Graph($this->storagePath);
        $node = $db->getNode($nodeId);

        $this->assertNotNull(
            $node,
            "Node {$nodeId} must be retrievable from shared memory after the writing process has exited"
        );
        $this->assertInstanceOf(
            \StefanakMichal\PhpShmGraph\Node::class,
            $node,
            "Expected getNode() to return a Node instance for node ID {$nodeId}"
        );
        $this->assertSame($nodeId, $node->id);
        $this->assertContains('Person', $node->labels);
        $this->assertSame('Alice', $node->properties['name']);
        $this->assertSame(30, $node->properties['age']);
    }

    public function testNodeUpdatePersistsAcrossProcesses(): void
    {
        $writeScript  = __DIR__ . '/helpers/write_node.php';
        $updateScript = __DIR__ . '/helpers/update_node.php';

        // ── Process 1: write the initial node ────────────────────────────────
        $cmd    = PHP_BINARY . ' ' . escapeshellarg($writeScript)
                . ' ' . escapeshellarg($this->storagePath) . ' 2>&1';
        $output = trim((string) shell_exec($cmd));

        $this->assertMatchesRegularExpression('/^\d+$/', $output, "write_node.php: $output");
        $nodeId = (int) $output;

        // ── Process 2: update the node and exit ───────────────────────────────
        $cmd    = PHP_BINARY . ' ' . escapeshellarg($updateScript)
                . ' ' . escapeshellarg($this->storagePath)
                . ' ' . $nodeId . ' 2>&1';
        $output = trim((string) shell_exec($cmd));

        $this->assertSame((string) $nodeId, $output, "update_node.php: $output");

        // ── Process 3 (this process): verify the update is visible ────────────
        $db   = new Graph($this->storagePath);
        $node = $db->getNode($nodeId);

        $this->assertNotNull($node, "Node {$nodeId} must still exist after update");
        $this->assertSame($nodeId, $node->id);
        $this->assertContains('Person', $node->labels);
        $this->assertSame('Bob', $node->properties['name']);
        $this->assertSame(25, $node->properties['age']);
    }

    public function testNodeRemovalPersistsAcrossProcesses(): void
    {
        $writeScript  = __DIR__ . '/helpers/write_node.php';
        $removeScript = __DIR__ . '/helpers/remove_node.php';

        // ── Process 1: write the node ─────────────────────────────────────────
        $cmd    = PHP_BINARY . ' ' . escapeshellarg($writeScript)
                . ' ' . escapeshellarg($this->storagePath) . ' 2>&1';
        $output = trim((string) shell_exec($cmd));

        $this->assertMatchesRegularExpression('/^\d+$/', $output, "write_node.php: $output");
        $nodeId = (int) $output;

        // ── Process 2: remove the node and exit ───────────────────────────────
        $cmd    = PHP_BINARY . ' ' . escapeshellarg($removeScript)
                . ' ' . escapeshellarg($this->storagePath)
                . ' ' . $nodeId . ' 2>&1';
        $output = trim((string) shell_exec($cmd));

        $this->assertSame('removed', $output, "remove_node.php: $output");

        // ── Process 3 (this process): verify the node is gone ────────────────
        $db   = new Graph($this->storagePath);
        $node = $db->getNode($nodeId);

        $this->assertNull($node, "Node {$nodeId} must be absent from shared memory after removal");
    }

    public function testUpdateNonExistentNodeThrowsException(): void
    {
        $db = new Graph($this->storagePath);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/i');

        $db->updateNode(999999, ['Label'], ['key' => 'value']);
    }
}
