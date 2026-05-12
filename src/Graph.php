<?php

namespace StefanakMichal\PhpShmGraph;

class Graph
{
    private const META_MAX_NODE_ID    = 1;
    private const META_MAX_EDGE_ID    = 2;
    private const META_NODE_FREE      = 3;
    private const META_EDGE_FREE      = 4;
    private const META_NODE_SEG_COUNT = 5;
    private const META_EDGE_SEG_COUNT = 6;

    private const META_SIZE = 65536; // 64 KB

    private string $nodesFile;
    private string $edgesFile;

    private \SysvSharedMemory $meta;
    private \SysvSemaphore $sem;

    /** @var array<int, \SysvSharedMemory> Lazily attached node segments, keyed by index */
    private array $nodeSegments = [];

    /** @var array<int, \SysvSharedMemory> Lazily attached edge segments, keyed by index */
    private array $edgeSegments = [];

    private int $nodeSegCount = 0;
    private int $edgeSegCount = 0;

    private bool $destroyed = false;

    public function __construct(
        private readonly string $storagePath = __DIR__,
        private readonly int $segmentSize = 8 * 1024 * 1024
    ) {
        $this->nodesFile = $storagePath . DIRECTORY_SEPARATOR . 'php_graph_nodes';
        $this->edgesFile = $storagePath . DIRECTORY_SEPARATOR . 'php_graph_edges';

        foreach ([$this->nodesFile, $this->edgesFile] as $file) {
            if (!file_exists($file) && !touch($file)) {
                throw new \RuntimeException("Cannot create dummy file: $file");
            }
        }

        $metaKey = ftok(__FILE__, 'M');
        $semKey  = ftok(__FILE__, 'L');

        if ($metaKey === -1 || $semKey === -1) {
            throw new \RuntimeException('ftok failed');
        }

        $sem = sem_get($semKey, 1, 0666, 1);
        if ($sem === false) {
            throw new \RuntimeException('sem_get failed');
        }
        $this->sem = $sem;

        $meta = shm_attach($metaKey, self::META_SIZE, 0666);
        if ($meta === false) {
            throw new \RuntimeException('shm_attach failed for meta segment');
        }
        $this->meta = $meta;

        if (!shm_has_var($this->meta, self::META_MAX_NODE_ID)) {
            shm_put_var($this->meta, self::META_MAX_NODE_ID, 0);
            shm_put_var($this->meta, self::META_MAX_EDGE_ID, 0);
            shm_put_var($this->meta, self::META_NODE_FREE, []);
            shm_put_var($this->meta, self::META_EDGE_FREE, []);
            shm_put_var($this->meta, self::META_NODE_SEG_COUNT, 0);
            shm_put_var($this->meta, self::META_EDGE_SEG_COUNT, 0);
        } else {
            $this->nodeSegCount = shm_get_var($this->meta, self::META_NODE_SEG_COUNT);
            $this->edgeSegCount = shm_get_var($this->meta, self::META_EDGE_SEG_COUNT);
        }
    }

    // ─── Key helpers ─────────────────────────────────────────────────────────

    private function nodeSegKey(int $idx): int
    {
        return ftok($this->nodesFile, chr($idx + 1));
    }

    private function edgeSegKey(int $idx): int
    {
        return ftok($this->edgesFile, chr($idx + 1));
    }

    // ─── Lazy segment access ─────────────────────────────────────────────────

    private function getNodeSegment(int $idx): \SysvSharedMemory
    {
        if (!isset($this->nodeSegments[$idx])) {
            $seg = shm_attach($this->nodeSegKey($idx), $this->segmentSize, 0666);
            if ($seg === false) {
                throw new \RuntimeException("Failed to attach node segment $idx");
            }
            $this->nodeSegments[$idx] = $seg;
        }
        return $this->nodeSegments[$idx];
    }

    private function getEdgeSegment(int $idx): \SysvSharedMemory
    {
        if (!isset($this->edgeSegments[$idx])) {
            $seg = shm_attach($this->edgeSegKey($idx), $this->segmentSize, 0666);
            if ($seg === false) {
                throw new \RuntimeException("Failed to attach edge segment $idx");
            }
            $this->edgeSegments[$idx] = $seg;
        }
        return $this->edgeSegments[$idx];
    }

    // ─── Locking ─────────────────────────────────────────────────────────────

    private function lock(): void
    {
        sem_acquire($this->sem);
    }

    private function unlock(): void
    {
        sem_release($this->sem);
    }

    // ─── ID allocator ────────────────────────────────────────────────────────

    private function allocateNodeId(): int
    {
        $free = shm_get_var($this->meta, self::META_NODE_FREE);
        if (!empty($free)) {
            $id = array_shift($free);
            shm_put_var($this->meta, self::META_NODE_FREE, $free);
            return $id;
        }
        $max = shm_get_var($this->meta, self::META_MAX_NODE_ID) + 1;
        shm_put_var($this->meta, self::META_MAX_NODE_ID, $max);
        return $max;
    }

    private function allocateEdgeId(): int
    {
        $free = shm_get_var($this->meta, self::META_EDGE_FREE);
        if (!empty($free)) {
            $id = array_shift($free);
            shm_put_var($this->meta, self::META_EDGE_FREE, $free);
            return $id;
        }
        $max = shm_get_var($this->meta, self::META_MAX_EDGE_ID) + 1;
        shm_put_var($this->meta, self::META_MAX_EDGE_ID, $max);
        return $max;
    }

    private function freeNodeId(int $id): void
    {
        $free   = shm_get_var($this->meta, self::META_NODE_FREE);
        $free[] = $id;
        sort($free);
        shm_put_var($this->meta, self::META_NODE_FREE, $free);
    }

    private function freeEdgeId(int $id): void
    {
        $free   = shm_get_var($this->meta, self::META_EDGE_FREE);
        $free[] = $id;
        sort($free);
        shm_put_var($this->meta, self::META_EDGE_FREE, $free);
    }

    // ─── Segment management ──────────────────────────────────────────────────

    private function addNodeSegment(): \SysvSharedMemory
    {
        $idx = $this->nodeSegCount;
        $seg = $this->getNodeSegment($idx);
        $this->nodeSegCount++;
        shm_put_var($this->meta, self::META_NODE_SEG_COUNT, $this->nodeSegCount);
        return $seg;
    }

    private function addEdgeSegment(): \SysvSharedMemory
    {
        $idx = $this->edgeSegCount;
        $seg = $this->getEdgeSegment($idx);
        $this->edgeSegCount++;
        shm_put_var($this->meta, self::META_EDGE_SEG_COUNT, $this->edgeSegCount);
        return $seg;
    }

    // ─── Internal write helpers ───────────────────────────────────────────────

    private function putNode(Node $node): void
    {
        for ($i = 0; $i < $this->nodeSegCount; $i++) {
            $seg = $this->getNodeSegment($i);
            if (shm_has_var($seg, $node->id)) {
                shm_remove_var($seg, $node->id);
                break;
            }
        }

        for ($i = 0; $i < $this->nodeSegCount; $i++) {
            if (@shm_put_var($this->getNodeSegment($i), $node->id, $node)) {
                return;
            }
        }

        $newSeg = $this->addNodeSegment();
        if (!@shm_put_var($newSeg, $node->id, $node)) {
            throw new \RuntimeException("Node {$node->id} is too large for a single segment");
        }
    }

    private function putEdge(Edge $edge): void
    {
        for ($i = 0; $i < $this->edgeSegCount; $i++) {
            $seg = $this->getEdgeSegment($i);
            if (shm_has_var($seg, $edge->id)) {
                shm_remove_var($seg, $edge->id);
                break;
            }
        }

        for ($i = 0; $i < $this->edgeSegCount; $i++) {
            if (@shm_put_var($this->getEdgeSegment($i), $edge->id, $edge)) {
                return;
            }
        }

        $newSeg = $this->addEdgeSegment();
        if (!@shm_put_var($newSeg, $edge->id, $edge)) {
            throw new \RuntimeException("Edge {$edge->id} is too large for a single segment");
        }
    }

    private function removeEdgeInternal(int $id, ?int $excludeNodeId): void
    {
        $edge = $this->getEdge($id);
        if ($edge === null) {
            return;
        }

        for ($i = 0; $i < $this->edgeSegCount; $i++) {
            $seg = $this->getEdgeSegment($i);
            if (shm_has_var($seg, $id)) {
                shm_remove_var($seg, $id);
                break;
            }
        }

        $this->freeEdgeId($id);

        if ($edge->from !== $excludeNodeId) {
            $fromNode = $this->getNode($edge->from);
            if ($fromNode !== null) {
                $fromNode->outgoing = array_values(array_filter(
                    $fromNode->outgoing,
                    fn(int $eid) => $eid !== $id
                ));
                $this->putNode($fromNode);
            }
        }

        if ($edge->to !== $excludeNodeId) {
            $toNode = $this->getNode($edge->to);
            if ($toNode !== null) {
                $toNode->incoming = array_values(array_filter(
                    $toNode->incoming,
                    fn(int $eid) => $eid !== $id
                ));
                $this->putNode($toNode);
            }
        }
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    public function addNode(array $labels = [], array $properties = []): Node
    {
        $this->lock();
        try {
            $id   = $this->allocateNodeId();
            $node = new Node($id, $labels, $properties);
            $this->putNode($node);
            return $node;
        } finally {
            $this->unlock();
        }
    }

    public function getNode(int $id): ?Node
    {
        for ($i = 0; $i < $this->nodeSegCount; $i++) {
            $seg = $this->getNodeSegment($i);
            if (shm_has_var($seg, $id)) {
                return shm_get_var($seg, $id);
            }
        }
        return null;
    }

    public function updateNode(int $id, array $labels = [], array $properties = []): void
    {
        $this->lock();
        try {
            $node = $this->getNode($id);
            if ($node === null) {
                throw new \InvalidArgumentException("Node with ID $id does not exist");
            }
            $node->labels = $labels;
            $node->properties = $properties;
            $this->putNode($node);
        } finally {
            $this->unlock();
        }
    }

    public function removeNode(int $id): void
    {
        $this->lock();
        try {
            $node = $this->getNode($id);
            if ($node === null) {
                return;
            }

            // Remove all connected edges, excluding updates back to the node being deleted
            $edgeIds = array_unique(array_merge($node->incoming, $node->outgoing));
            foreach ($edgeIds as $edgeId) {
                $this->removeEdgeInternal($edgeId, $id);
            }

            for ($i = 0; $i < $this->nodeSegCount; $i++) {
                $seg = $this->getNodeSegment($i);
                if (shm_has_var($seg, $id)) {
                    shm_remove_var($seg, $id);
                    break;
                }
            }

            $this->freeNodeId($id);
        } finally {
            $this->unlock();
        }
    }

    public function addEdge(int $from, int $to, string $type, array $properties = []): Edge
    {
        $this->lock();
        try {
            $fromNode = $this->getNode($from);
            $toNode = $this->getNode($to);
            if ($fromNode === null || $toNode === null) {
                throw new \InvalidArgumentException("Both 'from' and 'to' nodes must exist");
            }

            $id   = $this->allocateEdgeId();
            $edge = new Edge($id, $from, $to, $type, $properties);
            $this->putEdge($edge);

            $fromNode->outgoing[] = $id;
            $this->putNode($fromNode);

            $toNode->incoming[] = $id;
            $this->putNode($toNode);

            return $edge;
        } finally {
            $this->unlock();
        }
    }

    public function getEdge(int $id): ?Edge
    {
        for ($i = 0; $i < $this->edgeSegCount; $i++) {
            $seg = $this->getEdgeSegment($i);
            if (shm_has_var($seg, $id)) {
                return shm_get_var($seg, $id);
            }
        }
        return null;
    }

    public function updateEdge(int $id, int $from, int $to, string $type, array $properties = []): void
    {
        $this->lock();
        try {
            $fromNode = $this->getNode($from);
            $toNode = $this->getNode($to);
            if ($fromNode === null || $toNode === null) {
                throw new \InvalidArgumentException("Both 'from' and 'to' nodes must exist");
            }

            $edge = $this->getEdge($id);
            if ($edge === null) {
                throw new \InvalidArgumentException("Edge with ID $id does not exist");
            }

            if ($edge->from !== $from) {
                $oldFromNode = $this->getNode($edge->from);
                if ($oldFromNode !== null) {
                    $oldFromNode->outgoing = array_values(array_diff($oldFromNode->outgoing, [$id]));
                    $this->putNode($oldFromNode);
                }
            }
            if ($edge->to !== $to) {
                $oldToNode = $this->getNode($edge->to);
                if ($oldToNode !== null) {
                    $oldToNode->incoming = array_values(array_diff($oldToNode->incoming, [$id]));
                    $this->putNode($oldToNode);
                }
            }

            $edge->type = $type;
            $edge->from = $from;
            $edge->to = $to;
            $edge->properties = $properties;
            $this->putEdge($edge);

            if (!in_array($id, $fromNode->outgoing)) {
                $fromNode->outgoing[] = $id;
                $this->putNode($fromNode);
            } 

            if (!in_array($id, $toNode->incoming)) {
                $toNode->incoming[] = $id;
                $this->putNode($toNode);
            }
        } finally {
            $this->unlock();
        }
    }

    public function removeEdge(int $id): void
    {
        $this->lock();
        try {
            $this->removeEdgeInternal($id, null);
        } finally {
            $this->unlock();
        }
    }

    // ─── Cleanup ─────────────────────────────────────────────────────────────

    /**
     * Destroys all shared memory segments and the semaphore.
     * After calling this, the Graph instance must not be used.
     * All other processes that have attached to the same segments will lose access.
     */
    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }
        $this->lock();
        try {
            for ($i = 0; $i < $this->nodeSegCount; $i++) {
                shm_remove($this->getNodeSegment($i));
            }
            for ($i = 0; $i < $this->edgeSegCount; $i++) {
                shm_remove($this->getEdgeSegment($i));
            }
            $this->nodeSegments = [];
            $this->edgeSegments = [];
            $this->nodeSegCount = 0;
            $this->edgeSegCount = 0;
            shm_remove($this->meta);
            $this->destroyed = true;
        } finally {
            $this->unlock();
            sem_remove($this->sem);
        }
    }

    public function __destruct()
    {
        if ($this->destroyed) {
            return;
        }
        foreach ($this->nodeSegments as $seg) {
            shm_detach($seg);
        }
        foreach ($this->edgeSegments as $seg) {
            shm_detach($seg);
        }
        shm_detach($this->meta);
        // Note: only detach segments that were actually attached (lazy cache)
    }
}
