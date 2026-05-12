<?php
declare(strict_types=1);

/**
 * Child-process helper: attaches to an existing GraphDB and removes a Node, then exits.
 * Shared memory is NOT destroyed so the removal survives process exit.
 *
 * Usage: php remove_node.php <storagePath> <nodeId>
 * Outputs "removed" on stdout.
 */

require __DIR__ . '/../../vendor/autoload.php';

use StefanakMichal\PhpShmGraph\Graph;

$storagePath = $argv[1] ?? sys_get_temp_dir();
$nodeId      = (int) ($argv[2] ?? 0);

$db = new Graph($storagePath);
$db->removeNode($nodeId);

echo 'removed';
