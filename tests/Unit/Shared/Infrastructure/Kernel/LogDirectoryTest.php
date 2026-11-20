<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Kernel;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class LogDirectoryTest extends TestCase
{
    public function testDeploymentLogDirectoryOverridesTheContainerLocalDefault(): void
    {
        $previous = $_ENV['BAANDER_LOG_DIR'] ?? null;
        try {
            $_ENV['BAANDER_LOG_DIR'] = '/var/log/baander/worker/boot-123';
            self::assertSame('/var/log/baander/worker/boot-123', new Kernel('test', false)->getLogDir());
            $_ENV['BAANDER_LOG_DIR'] = '';
            $kernel = new Kernel('test', false);
            self::assertSame($kernel->getProjectDir() . '/var/log', $kernel->getLogDir());
        } finally {
            if ($previous === null) {
                unset($_ENV['BAANDER_LOG_DIR']);
            } else {
                $_ENV['BAANDER_LOG_DIR'] = $previous;
            }
        }
    }

    public function testRelativeDeploymentLogDirectoryIsRejected(): void
    {
        $previous = $_ENV['BAANDER_LOG_DIR'] ?? null;
        try {
            $_ENV['BAANDER_LOG_DIR'] = '../another-instance';
            $this->expectException(\LogicException::class);
            new Kernel('test', false)->getLogDir();
        } finally {
            if ($previous === null) {
                unset($_ENV['BAANDER_LOG_DIR']);
            } else {
                $_ENV['BAANDER_LOG_DIR'] = $previous;
            }
        }
    }
}
