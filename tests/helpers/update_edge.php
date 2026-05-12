<?php
declare(strict_types=1);

/**
 * Child-process helper: attaches to an existing GraphDB and updates an Edge, then exits.
 * Shared memory is NOT destroyed so changes survive process exit.
 *
 * Usage: php update_edge.php <storagePath> <edgeId> <fromNodeId> <toNodeId>
 * Outputs the edge ID on stdout.
 */

require __DIR__ . '/../../vendor/autoload.php';

use StefanakMichal\PhpShmGraph\Graph;

$storagePath = $argv[1] ?? sys_get_temp_dir();
$edgeId      = (int) ($argv[2] ?? 0);
$fromNodeId  = (int) ($argv[3] ?? 0);
$toNodeId    = (int) ($argv[4] ?? 0);

$db = new Graph($storagePath);
$db->updateEdge($edgeId, $fromNodeId, $toNodeId, 'LIKES', ['since' => 2024]);

echo $edgeId;
