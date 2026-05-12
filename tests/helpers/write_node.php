<?php
declare(strict_types=1);

/**
 * Child-process helper: creates a GraphDB, writes one Node, then exits.
 * Shared memory is NOT destroyed so it survives process exit.
 *
 * Usage: php write_node.php <storagePath>
 * Outputs the new node ID on stdout.
 */

require __DIR__ . '/../../vendor/autoload.php';

use StefanakMichal\PhpShmGraph\Graph;

$storagePath = $argv[1] ?? sys_get_temp_dir();

$db   = new Graph($storagePath);
$node = $db->addNode(['Person'], ['name' => 'Alice', 'age' => 30]);

echo $node->id;
// Intentionally NOT calling $db->destroy() — shared memory must survive after this process exits.
