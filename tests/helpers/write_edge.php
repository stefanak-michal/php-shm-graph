<?php

declare(strict_types=1);

/**
 * Child-process helper: creates a GraphDB, writes one Edge, then exits.
 * Shared memory is NOT destroyed so it survives process exit.
 *
 * Usage: php write_edge.php <storagePath>
 * Outputs the new edge ID on stdout.
 */

require __DIR__ . '/../../vendor/autoload.php';

use StefanakMichal\PhpShmGraph\Graph;

$storagePath = $argv[1] ?? sys_get_temp_dir();

$db   = new Graph($storagePath);
$node1 = $db->addNode(['Person'], ['name' => 'Alice', 'age' => 30]);
$node2 = $db->addNode(['Person'], ['name' => 'Bob', 'age' => 25]);
$edge = $db->addEdge($node1->id, $node2->id, 'KNOWS', ['since' => 2020]);

echo $edge->id;
// Intentionally NOT calling $db->destroy() — shared memory must survive after this process exits.
