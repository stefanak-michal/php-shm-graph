<?php
declare(strict_types=1);

/**
 * Child-process helper: attaches to an existing GraphDB and updates a Node, then exits.
 * Shared memory is NOT destroyed so changes survive process exit.
 *
 * Usage: php update_node.php <storagePath> <nodeId>
 * Outputs the node ID on stdout.
 */

require __DIR__ . '/../../vendor/autoload.php';

use StefanakMichal\PhpShmGraph\Graph;

$storagePath = $argv[1] ?? sys_get_temp_dir();
$nodeId      = (int) ($argv[2] ?? 0);

$db = new Graph($storagePath);
$db->updateNode($nodeId, ['Person'], ['name' => 'Bob', 'age' => 25]);

echo $nodeId;
