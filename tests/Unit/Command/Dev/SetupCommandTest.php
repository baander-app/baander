<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\Dev;

use App\Command\Dev\SetupCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class SetupCommandTest extends TestCase
{
    private KernelInterface&Stub $kernel;
    private Filesystem&Stub $filesystem;

    protected function setUp(): void
    {
        $this->kernel = $this->createStub(KernelInterface::class);
        $this->filesystem = $this->createStub(Filesystem::class);
    }

    public function testConfigureSetsNameAndDescription(): void
    {
        $command = $this->createCommand();

        $this->assertSame('app:dev:setup', $command->getName());
        $this->assertSame(
            'Bootstrap development environment: run migrations, generate OAuth keys, seed OAuth clients, create dev users.',
            $command->getDescription(),
        );
    }

    public function testConfigureExposesFreshAndSkipKeysOptions(): void
    {
        $definition = $this->createCommand()->getDefinition();

        $this->assertTrue($definition->hasOption('fresh'));
        $this->assertTrue($definition->hasOption('skip-keys'));

        $fresh = $definition->getOption('fresh');
        $skipKeys = $definition->getOption('skip-keys');

        // Both are value-less boolean flags.
        $this->assertFalse($fresh->acceptValue());
        $this->assertFalse($skipKeys->acceptValue());
        $this->assertFalse($fresh->getDefault());
        $this->assertFalse($skipKeys->getDefault());

        // Short-cut aliases.
        $this->assertSame('f', $fresh->getShortcut());
        $this->assertSame('k', $skipKeys->getShortcut());
    }

    /** @return iterable<string, array{string, array<string, bool>, list<string>}> */
    public static function failedStages(): iterable
    {
        yield 'fresh drop' => [
            'doctrine:schema:drop',
            ['--fresh' => true],
            ['doctrine:schema:drop'],
        ];
        yield 'migration' => [
            'doctrine:migrations:migrate',
            [],
            ['doctrine:migrations:migrate'],
        ];
        yield 'OAuth keys' => [
            'app:oauth:generate-keys',
            [],
            [
                'doctrine:migrations:migrate',
                'app:oauth:generate-keys',
            ],
        ];
        yield 'OAuth clients' => [
            'app:auth:setup-clients',
            [],
            [
                'doctrine:migrations:migrate',
                'app:oauth:generate-keys',
                'app:auth:setup-clients',
            ],
        ];
        yield 'dev users' => [
            'app:dev:create-users',
            [],
            [
                'doctrine:migrations:migrate',
                'app:oauth:generate-keys',
                'app:auth:setup-clients',
                'app:dev:create-users',
            ],
        ];
    }

    /**
     * @param array<string, bool> $options
     * @param list<string> $expectedStages
     */
    #[DataProvider('failedStages')]
    public function testFailedSubprocessStopsSetup(string $failedStage, array $options, array $expectedStages): void
    {
        $directory = sys_get_temp_dir() . '/baander-setup-' . bin2hex(random_bytes(8));
        $log = $directory . '/calls.log';
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory . '/bin');
        $filesystem->mkdir($directory . '/cache');
        $this->kernel->method('getCacheDir')->willReturn($directory . '/cache');

        $script = str_replace(
            ['__LOG__', '__FAIL__'],
            [var_export($log, true), var_export($failedStage, true)],
            <<<'PHP'
<?php

$stage = $argv[1] ?? '';
file_put_contents(__LOG__, $stage . PHP_EOL, FILE_APPEND);

if ($stage === __FAIL__) {
    fwrite(STDERR, 'intentional setup failure');
    exit(17);
}
PHP,
        );
        file_put_contents($directory . '/bin/console', $script);

        try {
            $tester = new CommandTester(new SetupCommand($this->kernel, $filesystem, $directory));

            self::assertSame(Command::FAILURE, $tester->execute($options));
            self::assertSame($expectedStages, file($log, FILE_IGNORE_NEW_LINES));
            self::assertStringContainsString('intentional setup failure', $tester->getDisplay());
            self::assertStringNotContainsString('setup complete', $tester->getDisplay());
            if ($failedStage === 'doctrine:schema:drop') {
                self::assertDirectoryExists($directory . '/cache');
            }
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testMatchingKeysSurviveResumedSetup(): void
    {
        $directory = sys_get_temp_dir() . '/baander-resume-' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir([$directory . '/bin', $directory . '/config/secrets/oauth']);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);
        file_put_contents($directory . '/config/secrets/oauth/private.key', $private);
        file_put_contents($directory . '/config/secrets/oauth/public.key', $details['key']);
        $log = $directory . '/commands.jsonl';
        file_put_contents($directory . '/bin/console', '<?php file_put_contents(' . var_export($log, true) . ', json_encode($argv) . PHP_EOL, FILE_APPEND);');
        try {
            $tester = new CommandTester(new SetupCommand($this->kernel, $filesystem, $directory));
            self::assertSame(Command::SUCCESS, $tester->execute(['--origin' => 'https://dev.baander.app']));
            $calls = file_get_contents($log);
            self::assertIsString($calls);
            self::assertStringNotContainsString('app:oauth:generate-keys', $calls);
            self::assertStringContainsString('--origin=https:', $calls);
            self::assertSame($private, file_get_contents($directory . '/config/secrets/oauth/private.key'));
        } finally {
            $filesystem->remove($directory);
        }
    }

    private function createCommand(): SetupCommand
    {
        return new SetupCommand(
            $this->kernel,
            $this->filesystem,
            sys_get_temp_dir() . '/baander-project',
        );
    }
}
