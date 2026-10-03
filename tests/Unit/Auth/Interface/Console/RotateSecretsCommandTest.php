<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Exception\OAuthTokenCacheInvalidationFailed;
use App\Auth\Application\Port\OAuthSecretBundleInterface;
use App\Auth\Application\Port\OAuthTokenInvalidatorInterface;
use App\Auth\Interface\Console\RotateSecretsCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RotateSecretsCommandTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'unknown action' => [['action' => 'rotate', '--directory' => '/private/bundle']];
        yield 'missing directory' => [['action' => 'prepare']];
        yield 'missing offline assertion' => [['action' => 'invalidate', '--directory' => '/private/bundle']];
        yield 'offline on prepare' => [['action' => 'prepare', '--directory' => '/private/bundle', '--offline' => true]];
        yield 'size on validate' => [['action' => 'validate', '--directory' => '/private/bundle', '--key-size' => '2048']];
        yield 'weak size' => [['action' => 'prepare', '--directory' => '/private/bundle', '--key-size' => '1024']];
        yield 'coercible size' => [['action' => 'prepare', '--directory' => '/private/bundle', '--key-size' => '2048garbage']];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputNeverTouchesBundleOrTokens(array $input): void
    {
        $bundles = $this->createMock(OAuthSecretBundleInterface::class);
        $bundles->expects(self::never())->method('prepare');
        $bundles->expects(self::never())->method('validate');
        $tokens = $this->createMock(OAuthTokenInvalidatorInterface::class);
        $tokens->expects(self::never())->method('invalidate');
        $tester = new CommandTester(new RotateSecretsCommand($bundles, $tokens));
        self::assertSame(Command::INVALID, $tester->execute($input));
    }

    public function testPrepareValidatesCompletedBundleWithoutInvalidatingTokens(): void
    {
        $events = [];
        $bundles = $this->createMock(OAuthSecretBundleInterface::class);
        $bundles->expects(self::once())->method('prepare')->with('/private/bundle', 4096)
            ->willReturnCallback(static function () use (&$events): void { $events[] = 'prepare'; });
        $bundles->expects(self::once())->method('validate')->with('/private/bundle')
            ->willReturnCallback(static function () use (&$events): void { $events[] = 'validate'; });
        $tokens = $this->createMock(OAuthTokenInvalidatorInterface::class);
        $tokens->expects(self::never())->method('invalidate');
        $tester = new CommandTester(new RotateSecretsCommand($bundles, $tokens));
        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'prepare', '--directory' => '/private/bundle', '--key-size' => '4096']));
        self::assertSame(['prepare', 'validate'], $events);
        self::assertStringContainsString('/private/bundle/oauth.env', $tester->getDisplay());
    }

    public function testInvalidBundlePreventsDatabaseMutationAndDoesNotPrintExceptionSecrets(): void
    {
        $bundles = $this->createMock(OAuthSecretBundleInterface::class);
        $bundles->expects(self::once())->method('validate')->willThrowException(new RuntimeException('secret-material'));
        $tokens = $this->createMock(OAuthTokenInvalidatorInterface::class);
        $tokens->expects(self::never())->method('invalidate');
        $tester = new CommandTester(new RotateSecretsCommand($bundles, $tokens));
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'invalidate', '--directory' => '/private/bundle', '--offline' => true]));
        self::assertMatchesRegularExpression('/No token invalidation was\s+attempted/', $tester->getDisplay());
        self::assertStringNotContainsString('secret-material', $tester->getDisplay());
    }

    public function testInvalidationRunsAfterValidationAndExplainsCutover(): void
    {
        $validated = false;
        $bundles = $this->createMock(OAuthSecretBundleInterface::class);
        $bundles->expects(self::once())->method('validate')->willReturnCallback(static function () use (&$validated): void { $validated = true; });
        $tokens = $this->createMock(OAuthTokenInvalidatorInterface::class);
        $tokens->expects(self::once())->method('invalidate')->willReturnCallback(static function () use (&$validated): int {
            self::assertTrue($validated);
            return 7;
        });
        $tester = new CommandTester(new RotateSecretsCommand($bundles, $tokens));
        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'invalidate', '--directory' => '/private/bundle', '--offline' => true]));
        self::assertStringContainsString('Invalidated 7 OAuth rows', $tester->getDisplay());
        self::assertStringContainsString('Do not repeat invalidate after service resumes', $tester->getDisplay());
    }

    /** @return iterable<string, array{RuntimeException, string}> */
    public static function invalidationFailures(): iterable
    {
        yield 'database uncertain' => [new RuntimeException('secret-material'), 'Token invalidation is unconfirmed'];
        yield 'cache failed after commit' => [new OAuthTokenCacheInvalidationFailed(7), 'Database invalidation committed'];
    }

    #[DataProvider('invalidationFailures')]
    public function testFailureReportsRecoveryPhase(RuntimeException $failure, string $message): void
    {
        $bundles = $this->createMock(OAuthSecretBundleInterface::class);
        $bundles->expects(self::once())->method('validate');
        $tokens = $this->createMock(OAuthTokenInvalidatorInterface::class);
        $tokens->expects(self::once())->method('invalidate')->willThrowException($failure);
        $tester = new CommandTester(new RotateSecretsCommand($bundles, $tokens));
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'invalidate', '--directory' => '/private/bundle', '--offline' => true]));
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertStringContainsString('offline', $tester->getDisplay());
        self::assertStringNotContainsString('secret-material', $tester->getDisplay());
    }
}
