<?php

declare(strict_types=1);

namespace Yankewei\StdioToWs\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use Yankewei\StdioToWs\Process\ProcessManager;

final class ProcessManagerTest extends TestCase
{
    public function testCreateAndGetProcess(): void
    {
        $manager = new ProcessManager();
        $process = $manager->createProcess('conn-1', 'echo test', null, []);

        self::assertNotNull($process);

        $retrieved = $manager->getProcess('conn-1');
        self::assertSame($process, $retrieved);
    }

    public function testGetNonExistentProcess(): void
    {
        $manager = new ProcessManager();
        $process = $manager->getProcess('non-existent');

        self::assertNull($process);
    }

    public function testRemoveProcess(): void
    {
        $manager = new ProcessManager();
        $manager->createProcess('conn-1', 'echo test', null, []);

        self::assertNotNull($manager->getProcess('conn-1'));

        $manager->removeProcess('conn-1');

        self::assertNull($manager->getProcess('conn-1'));
    }

    public function testCount(): void
    {
        $manager = new ProcessManager();

        self::assertSame(0, $manager->count());

        $manager->createProcess('conn-1', 'echo test', null, []);
        self::assertSame(1, $manager->count());

        $manager->createProcess('conn-2', 'echo test2', null, []);
        self::assertSame(2, $manager->count());

        $manager->removeProcess('conn-1');
        self::assertSame(1, $manager->count());
    }

    public function testCloseAll(): void
    {
        $manager = new ProcessManager();
        $manager->createProcess('conn-1', 'echo test1', null, []);
        $manager->createProcess('conn-2', 'echo test2', null, []);
        $manager->createProcess('conn-3', 'echo test3', null, []);

        self::assertSame(3, $manager->count());

        $manager->closeAll();

        self::assertSame(0, $manager->count());
    }

    public function testMultipleConnectionsIsolation(): void
    {
        $manager = new ProcessManager();

        $process1 = $manager->createProcess('conn-1', 'echo first', '/tmp', ['ENV' => '1']);
        $process2 = $manager->createProcess('conn-2', 'echo second', '/var', ['ENV' => '2']);

        // Each connection should have independent process
        self::assertNotSame($process1, $process2);
        self::assertSame($process1, $manager->getProcess('conn-1'));
        self::assertSame($process2, $manager->getProcess('conn-2'));
    }
}
