<?php
declare(strict_types=1);

namespace StefanakMichal\PhpShmGraph\Tests;

use PHPUnit\Framework\TestCase;
use StefanakMichal\PhpShmGraph\Graph;

abstract class TestLayer extends TestCase
{
    protected string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir();

        // Clean up any shared memory left by a previous failed run.
        try {
            $db = new Graph($this->storagePath);
            $db->destroy();
        } catch (\Throwable) {
            // Nothing to clean up — that is fine.
        }
    }

    protected function tearDown(): void
    {
        try {
            $db = new Graph($this->storagePath);
            $db->destroy();
        } catch (\Throwable) {
            // Already destroyed or never created.
        }
    }
}
