<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\{EngineExecutor, ExecutionContext};
use Baander\Tooling\Resources\ImageLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageLedgerTest extends TestCase
{
    private string $directory;
    private EngineExecutor $executor;
    private Client $client;
    private ImageLedger $ledger;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-image-ledger-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $environment = array_replace(getenv(), ['IMAGE_LEDGER_STATE' => $this->directory . '/state.json', 'IMAGE_LEDGER_RECEIPT' => $this->directory . '/receipt.json']);
        $this->executor = new EngineExecutor(ExecutionContext::bounded(30, $environment));
        $this->client = new Client($this->executor, new Target(__DIR__ . '/Fixtures/image-ledger-docker.php', 'unix:///baander.app/docker.sock', 'image-daemon', null, null), $environment);
        $this->ledger = new ImageLedger($this->directory . '/receipt.json', $this->client);
        AtomicFile::writeJson($this->directory . '/state.json', ['daemonId' => 'image-daemon', 'images' => ['fixture-base:original' => self::image('a'), 'fixture-base:other' => self::image('a'), 'foreign:retained' => self::image('b')], 'mode' => 'clean', 'calls' => []]);
    }

    protected function tearDown(): void
    {
        unset($this->ledger);
        $this->executor->close();
        Collector::remove($this->directory);
    }

    public function testAliasesExposeSelectedImagesAndCleanupOnlyRemovesTheAliasTag(): void
    {
        $alias = $this->ledger->alias('fixture-base', self::id('a'));
        self::assertSame('baander-build-input-' . $this->ledger->token . ':fixture-base', $alias);
        self::assertSame(self::id('a'), AtomicFile::readJson($this->directory . '/receipt.json')['aliases'][$alias]);
        $this->ledger->validateAliases($this->client);
        self::assertTrue($this->ledger->cleanup($this->client)->successful());
        self::assertSame(['fixture-base:original', 'fixture-base:other', 'foreign:retained'], array_keys($this->state()['images']));
        self::assertFileDoesNotExist($this->directory . '/receipt.json');
        self::assertContains(['image', 'rm', $alias], $this->state()['calls']);
        self::assertNotContains(['image', 'rm', self::id('a')], $this->state()['calls']);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function failedTags(): iterable
    {
        yield 'failure before mutation' => ['tag-fails-before', false];
        yield 'lost reply after mutation' => ['tag-fails-after', true];
    }

    #[DataProvider('failedTags')]
    public function testUncertainTaggingIsRecoverable(string $mode, bool $present): void
    {
        $this->change(['mode' => $mode]);
        try {
            $this->ledger->alias('fixture-base', self::id('a'));
            self::fail('Expected tagging failure');
        } catch (\RuntimeException) {
        }
        $receipt = AtomicFile::readJson($this->directory . '/receipt.json');
        $alias = array_key_first($receipt['aliases']);
        self::assertSame($present, isset($this->state()['images'][$alias]));
        unset($this->ledger);
        $this->ledger = new ImageLedger($this->directory . '/receipt.json', $this->client);
        $this->change(['mode' => 'clean']);
        self::assertTrue($this->ledger->cleanup($this->client)->successful());
        self::assertCount(3, $this->state()['images']);
        self::assertFileDoesNotExist($this->directory . '/receipt.json');
    }

    public function testRetargetedAliasIsRefusedAndReceiptRetained(): void
    {
        $alias = $this->ledger->alias('fixture-base', self::id('a'));
        $state = $this->state();
        $state['images'][$alias] = self::image('b');
        $this->change($state);
        try {
            $this->ledger->validateAliases($this->client);
            self::fail('Expected retargeting refusal');
        } catch (\RuntimeException) {
        }
        self::assertFalse($this->ledger->cleanup($this->client)->successful());
        self::assertSame(self::id('b'), $this->state()['images'][$alias]['Id']);
        self::assertFileExists($this->directory . '/receipt.json');
    }

    public function testExistingAliasIsRefusedBeforeAnyMutation(): void
    {
        $alias = 'baander-build-input-' . $this->ledger->token . ':fixture-base';
        $state = $this->state();
        $state['images'][$alias] = self::image('a');
        $this->change($state);
        try {
            $this->ledger->alias('fixture-base', self::id('a'));
            self::fail('Expected occupied alias refusal');
        } catch (\RuntimeException) {
        }
        self::assertSame([], array_filter($this->state()['calls'], static fn(array $call): bool => array_slice($call, 0, 2) === ['image', 'tag']));
    }

    /** @return iterable<string,array{bool}> */
    public static function lostReferences(): iterable
    {
        yield 'source tags removed' => [false];
        yield 'source tags retargeted' => [true];
    }

    #[DataProvider('lostReferences')]
    public function testCleanupRetainsAliasWhenExternalReferencesStopPreservingSource(bool $retarget): void
    {
        $alias = $this->ledger->alias('fixture-base', self::id('a'));
        $state = $this->state();
        foreach (['fixture-base:original', 'fixture-base:other'] as $reference) {
            if ($retarget) {
                $state['images'][$reference] = self::image('b');
            } else {
                unset($state['images'][$reference]);
            }
        }
        $this->change($state);
        self::assertFalse($this->ledger->cleanup($this->client)->successful());
        self::assertSame(self::id('a'), $this->state()['images'][$alias]['Id']);
        self::assertFileExists($this->directory . '/receipt.json');
        self::assertNotContains(['image', 'rm', $alias], $this->state()['calls']);
    }

    public function testExternalRepositoryDigestPreservesSourceWithoutTags(): void
    {
        $digest = 'registry.baander.app/base@sha256:' . str_repeat('d', 64);
        $this->change(['images' => [$digest => self::image('a')]]);
        $alias = $this->ledger->alias('fixture-base', self::id('a'));
        self::assertTrue($this->ledger->cleanup($this->client)->successful());
        self::assertSame(self::id('a'), $this->state()['images'][$digest]['Id']);
        self::assertArrayNotHasKey($alias, $this->state()['images']);
        self::assertSame(0, $this->client->run(['image', 'inspect', self::id('a')])->exitCode);
    }

    public function testSameRepositoryDigestCannotPreserveTheLastAliasTag(): void
    {
        $digest = 'baander-build-input-' . $this->ledger->token . '@sha256:' . str_repeat('d', 64);
        $this->change(['images' => [$digest => self::image('a')]]);
        $failure = null;
        try {
            $this->ledger->alias('fixture-base', self::id('a'));
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        self::assertNotNull($failure);
        self::assertArrayHasKey($digest, $this->state()['images']);
        self::assertFileDoesNotExist($this->directory . '/receipt.json');
        self::assertSame([], array_values(array_filter($this->state()['calls'], static fn(array $call): bool => array_slice($call, 0, 2) === ['image', 'tag'])));
    }

    public function testAnotherOwnedAliasCannotQualifyAsAnExternalPreservingReference(): void
    {
        $first = $this->ledger->alias('fixture-base', self::id('a'));
        $state = $this->state();
        unset($state['images']['fixture-base:original'], $state['images']['fixture-base:other']);
        $this->change($state);
        $failure = null;
        try {
            $this->ledger->alias('controller-base', self::id('a'));
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        self::assertNotNull($failure);
        self::assertSame([$first => self::id('a')], AtomicFile::readJson($this->directory . '/receipt.json')['aliases']);
        self::assertCount(1, array_filter($this->state()['calls'], static fn(array $call): bool => array_slice($call, 0, 2) === ['image', 'tag']));
    }

    public function testDanglingSourceIsRefusedWithoutTaggingOrReceipt(): void
    {
        $this->change(['images' => [], 'dangling' => [self::id('a') => self::image('a')]]);
        $failure = null;
        try {
            $this->ledger->alias('fixture-base', self::id('a'));
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        self::assertNotNull($failure, 'A bare image ID has no preserving reference after alias cleanup');
        self::assertSame([], array_values(array_filter($this->state()['calls'], static fn(array $call): bool => array_slice($call, 0, 2) === ['image', 'tag'])));
        self::assertSame(self::id('a'), $this->state()['dangling'][self::id('a')]['Id']);
        self::assertFileDoesNotExist($this->directory . '/receipt.json');
    }

    /** @return iterable<string,array{string,string,bool}> */
    public static function invalidAliases(): iterable
    {
        yield 'mutable selection' => ['valid', 'fixture-base:original', false];
        yield 'foreign alias namespace' => ['foreign:tag', 'sha256:' . str_repeat('a', 64), false];
        yield 'conflicting owned output' => ['valid', 'sha256:' . str_repeat('a', 64), true];
    }

    #[DataProvider('invalidAliases')]
    public function testInvalidOrConflictingSavedAliasesAreRejected(string $alias, string $id, bool $conflict): void
    {
        $token = $this->ledger->token;
        $alias = $alias === 'valid' ? 'baander-build-input-' . $token . ':fixture-base' : $alias;
        unset($this->ledger);
        AtomicFile::writeJson($this->directory . '/receipt.json', ['version' => 1, 'target' => $this->client->target->toArray(), 'token' => $token, 'images' => $conflict ? [$alias => $id] : [], 'aliases' => [$alias => $id]]);
        $this->expectException(\RuntimeException::class);
        $this->ledger = new ImageLedger($this->directory . '/receipt.json', $this->client);
    }

    private static function id(string $character): string
    {
        return 'sha256:' . str_repeat($character, 64);
    }

    /** @return array{Id:string,Config:array{Labels:array<string,string>}} */
    private static function image(string $character): array
    {
        return ['Id' => self::id($character), 'Config' => ['Labels' => []]];
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        return AtomicFile::readJson($this->directory . '/state.json');
    }

    /** @param array<string,mixed> $values */
    private function change(array $values): void
    {
        AtomicFile::writeJson($this->directory . '/state.json', array_replace($this->state(), $values));
    }
}
