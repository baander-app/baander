<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Yaml\Yaml;

final class RateLimiterCommandsTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testListShowsEveryConfiguredLimiterAndItsPool(): void
    {
        $tester = $this->command('app:rate-limiter:list');

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        foreach ($this->configuredLimiterNames() as $name) {
            self::assertStringContainsString($name, $tester->getDisplay());
            self::assertStringContainsString('cache.rate_limiter.' . $name, $tester->getDisplay());
        }
    }

    public function testClearResetsOnlyTheNamedLimiter(): void
    {
        $key = 'u27-cli-' . bin2hex(random_bytes(4));
        $this->exhaust('batch_cover_extract', $key);
        $this->exhaust('config_check', $key);

        $tester = $this->command('app:rate-limiter:clear');

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'batch_cover_extract']), $tester->getDisplay());
        self::assertTrue($this->limiter('batch_cover_extract')->create($key)->consume()->isAccepted());
        self::assertFalse($this->limiter('config_check')->create($key)->consume()->isAccepted());
    }

    public function testClearAllResetsEveryLimiter(): void
    {
        $key = 'u27-cli-' . bin2hex(random_bytes(4));
        $this->exhaust('batch_cover_extract', $key);
        $this->exhaust('config_check', $key);

        $tester = $this->command('app:rate-limiter:clear');

        self::assertSame(Command::SUCCESS, $tester->execute(['--all' => true]), $tester->getDisplay());
        self::assertTrue($this->limiter('batch_cover_extract')->create($key)->consume()->isAccepted());
        self::assertTrue($this->limiter('config_check')->create($key)->consume()->isAccepted());
    }

    public function testClearUnknownLimiterFails(): void
    {
        $tester = $this->command('app:rate-limiter:clear');

        self::assertSame(Command::FAILURE, $tester->execute(['name' => 'no_such_limiter']));
        self::assertStringContainsString('Unknown rate limiter "no_such_limiter"', $tester->getDisplay());
    }

    public function testClearJsonPrintsTheApiPayloadForOneLimiter(): void
    {
        $tester = $this->command('app:rate-limiter:clear');

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'batch_cover_extract', '--json' => true]), $tester->getDisplay());
        self::assertSame(['cleared' => true, 'limiter' => 'batch_cover_extract'], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testClearAllJsonPrintsTheApiPayload(): void
    {
        $tester = $this->command('app:rate-limiter:clear');

        self::assertSame(Command::SUCCESS, $tester->execute(['--all' => true, '--json' => true]), $tester->getDisplay());
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['cleared']);
        self::assertEqualsCanonicalizing($this->configuredLimiterNames(), $data['limiters']);
    }

    public function testClearJsonKeepsFailuresOffStdout(): void
    {
        $tester = $this->command('app:rate-limiter:clear');

        self::assertSame(Command::FAILURE, $tester->execute(['name' => 'no_such_limiter', '--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Unknown rate limiter', $tester->getErrorOutput());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find($name));
    }

    private function exhaust(string $name, string $key): void
    {
        $limiter = $this->limiter($name)->create($key);
        $limiter->consume(10000);

        self::assertFalse($limiter->consume()->isAccepted(), sprintf('Limiter "%s" was not exhausted.', $name));
    }

    private function limiter(string $name): RateLimiterFactoryInterface
    {
        $factory = static::getContainer()->get('limiter.' . $name);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        return $factory;
    }

    /** @return list<string> */
    private function configuredLimiterNames(): array
    {
        $config = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/framework.yaml');

        return array_keys($config['framework']['rate_limiter']);
    }
}
