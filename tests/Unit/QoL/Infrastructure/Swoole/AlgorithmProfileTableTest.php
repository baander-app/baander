<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure\Swoole;

use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\QoL\Infrastructure\Swoole\AlgorithmProfileTable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AlgorithmProfileTableTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-qol-profile-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testWithoutASavedProfileTheBalancedProfileApplies(): void
    {
        $table = new AlgorithmProfileTable($this->directory, new NullLogger());
        $table->boot();

        self::assertSame(AlgorithmProfile::Balanced, $table->get());
    }

    public function testAChangeIsSavedToItsOwnFileAndLoadedAtTheNextBoot(): void
    {
        $table = new AlgorithmProfileTable($this->directory, new NullLogger());
        $table->boot();

        $table->set(AlgorithmProfile::Aggressive);

        self::assertSame(AlgorithmProfile::Aggressive, $table->get());
        self::assertSame('{"profile":"aggressive"}', file_get_contents($this->directory . '/algorithm_profile.json'));
        self::assertSame(['algorithm_profile.json'], array_map('basename', glob($this->directory . '/*') ?: []));
        $restarted = new AlgorithmProfileTable($this->directory, new NullLogger());
        $restarted->boot();
        self::assertSame(AlgorithmProfile::Aggressive, $restarted->get());
    }

    public function testAnUnreadableSavedProfileFallsBackToBalancedWithAWarning(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/algorithm_profile.json', '{"profile":"turbo"}');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $table = new AlgorithmProfileTable($this->directory, $logger);

        $table->boot();

        self::assertSame(AlgorithmProfile::Balanced, $table->get());
    }

    public function testOutsideTheServerTheProfileIsHeldInThisProcess(): void
    {
        $table = new AlgorithmProfileTable($this->directory, new NullLogger());

        $table->set(AlgorithmProfile::Conservative);

        self::assertSame(AlgorithmProfile::Conservative, $table->get());
    }
}
