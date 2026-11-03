<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Fixtures\Household\{Images, NativeAudit};
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class HouseholdImagesTest extends TestCase
{
    private string $root;
    private ApplicationLayout $layout;
    private string $app;
    private string $operator;
    private string $revision;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-household-images-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        $this->app = 'sha256:' . str_repeat('a', 64);
        $this->operator = 'sha256:' . str_repeat('b', 64);
        $this->revision = str_repeat('c', 40);
        AtomicFile::writeJson($this->root . '/dataset.json', ['version' => 1, 'files' => [['path' => 'fixture/music.flac']]]);
    }

    protected function tearDown(): void
    {
        Collector::remove($this->root);
    }

    private function images(?DevelopmentCommandExecutor $runner = null): Images
    {
        $runner ??= new DevDataRunner(static fn(): string => '');
        return new Images(new Client($runner, new Target('/docker', 'unix:///baander.app/docker.sock', 'image-daemon', null, null), getenv()), $this->root . '/artifacts', $this->layout);
    }

    public function testPrivateOverlayKeepsProductionInputsAndOnlyOverridesMetadataTransport(): void
    {
        $prepared = $this->images()->prepare($this->app, $this->operator, $this->revision, $this->root . '/dataset.json');
        self::assertSame(0700, fileperms($prepared['context']) & 0777);
        $dockerfile = file_get_contents($prepared['context'] . '/Dockerfile');
        self::assertStringContainsString('FROM ${APP_BASE} AS app', $dockerfile);
        self::assertStringContainsString('FROM app AS worker', $dockerfile);
        self::assertStringContainsString('FROM app AS operator', $dockerfile);
        self::assertStringContainsString('HEALTHCHECK NONE', $dockerfile);
        self::assertStringContainsString('STOPSIGNAL SIGTERM', $dockerfile);
        self::assertStringContainsString('COPY --from=household-operator-helper /usr/local/bin/docker /usr/local/bin/docker', $dockerfile);
        self::assertStringNotContainsString('composer install', $dockerfile);
        self::assertStringContainsString('composer dump-autoload --no-dev --optimize --no-interaction --no-scripts', $dockerfile);
        self::assertStringNotContainsString('BAANDER_DEPLOYMENT_PROFILE', $dockerfile);
        $services = Yaml::parseFile($prepared['context'] . '/files/config/services_prod.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];
        self::assertSame(['baander.provider_http_transport', 'App\\Shared\\Infrastructure\\Http\\PublicDestinationPolicy'], array_keys($services));
        self::assertSame($this->layout->path('var/household/dataset.json'), $prepared['metadataManifestPath']);
        self::assertSame($this->layout->path('storage/household-audit/native.jsonl'), $prepared['nativeAuditPath']);
        foreach (['ffmpeg', 'ffprobe'] as $executable) {
            self::assertSame(NativeAudit::wrapper($executable, $this->layout), file_get_contents($prepared['context'] . '/wrappers/' . $executable));
            self::assertSame(hash('sha256', NativeAudit::wrapper($executable, $this->layout)), $prepared['wrapperHashes'][$executable]);
        }
        self::assertFileExists($prepared['context'] . '/files/packages/baander-tooling/src/Fixtures/Household/HostSampler.php');
        self::assertSame(hash_file('sha256', $this->root . '/dataset.json'), $prepared['manifest']['files']['files/var/household/dataset.json']);
    }

    public function testCopiedImageFilesAreReadableWithoutElevatedFilesystemCapabilities(): void
    {
        $prepared = $this->images()->prepare($this->app, $this->operator, $this->revision, $this->root . '/dataset.json');
        self::assertSame(0700, fileperms($prepared['context']) & 0777);
        $copied = $prepared['context'] . '/files';
        self::assertSame(0755, fileperms($copied) & 0777);
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($copied, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            self::assertSame($entry->isDir() ? 0755 : 0644, $entry->getPerms() & 0777, $entry->getPathname());
        }
        self::assertSame(0700, fileperms($prepared['context'] . '/wrappers') & 0777);
        self::assertSame(0600, fileperms($prepared['context'] . '/autoload.php') & 0777);
    }

    public function testOverlayClassmapAddsOnlyTheMetadataTransportAndPreservesApplicationConfiguration(): void
    {
        $prepared = $this->images()->prepare($this->app, $this->operator, $this->revision, $this->root . '/dataset.json');
        $path = $this->root . '/composer.json';
        $document = ['name' => 'baander/application', 'autoload' => ['psr-4' => ['App\\' => 'src/'], 'classmap' => ['existing.php']], 'require' => ['php' => '>=8.5']];
        AtomicFile::writeJson($path, $document);
        $runtime = new Runtime(dirname(__DIR__, 3), 30);
        try {
            $runtime->run([PHP_BINARY, $prepared['context'] . '/autoload.php', $path]);
            $runtime->run([PHP_BINARY, $prepared['context'] . '/autoload.php', $path]);
        } finally {
            $runtime->close();
        }
        $document['autoload']['classmap'][] = 'packages/baander-tooling/src/Fixtures/Household/MetadataTransport.php';
        self::assertEquals($document, AtomicFile::readJson($path));
    }

    public function testManifestDigestIsStableAcrossPrivateContextsAndChangesWithDataset(): void
    {
        $images = $this->images();
        $one = $images->prepare($this->app, $this->operator, $this->revision, $this->root . '/dataset.json');
        $two = $images->prepare($this->app, $this->operator, $this->revision, $this->root . '/dataset.json');
        self::assertNotSame($one['context'], $two['context']);
        self::assertSame($one['manifestDigest'], $two['manifestDigest']);
        AtomicFile::writeJson($this->root . '/dataset.json', ['version' => 1, 'files' => [['path' => 'fixture/changed.flac']]]);
        $three = $images->prepare($this->app, $this->operator, $this->revision, $this->root . '/dataset.json');
        self::assertNotSame($one['manifestDigest'], $three['manifestDigest']);
    }

    public function testMutableBaseReferenceIsRejectedBeforeCreatingContext(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->images()->prepare('baander:latest', $this->operator, $this->revision, $this->root . '/dataset.json');
    }

    public function testMismatchedBaseRetainsARecoverableReceiptWithoutBuilding(): void
    {
        $configuration = ['Labels' => [
            'org.baander.application-layout' => json_encode($this->layout->toArray(), JSON_THROW_ON_ERROR),
            'org.opencontainers.image.revision' => str_repeat('d', 40),
        ], 'WorkingDir' => $this->layout->root, 'Env' => ['APP_HOME=' . $this->layout->root]];
        $runner = new DevDataRunner(fn(array $arguments): string => in_array('info', $arguments, true)
            ? '{"ID":"image-daemon"}' : json_encode([['Id' => $this->app, 'Config' => $configuration]], JSON_THROW_ON_ERROR));
        try {
            $this->images($runner)->build($this->app, $this->operator, $this->revision, $this->root . '/dataset.json');
            self::fail('Mismatched source revision accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('source revision', $error->getMessage());
        }
        $receipt = AtomicFile::readJson($this->root . '/artifacts/images.json');
        self::assertSame('preparing', $receipt['phase']);
        self::assertDirectoryExists($receipt['context']);
        self::assertFileDoesNotExist($receipt['ownershipReceipt']);
        self::assertCount(2, $runner->calls);
    }

    public function testThreeTargetsUseOwnedAliasesAndRetainImmutableBuildReceipts(): void
    {
        $labels = ['org.baander.application-layout' => json_encode($this->layout->toArray(), JSON_THROW_ON_ERROR), 'org.opencontainers.image.revision' => $this->revision];
        $configuration = ['Labels' => $labels, 'WorkingDir' => $this->layout->root, 'Env' => ['APP_HOME=' . $this->layout->root]];
        $inventory = [
            'fixture-app:source' => ['Id' => $this->app, 'Config' => $configuration, 'RepoTags' => ['fixture-app:source'], 'RepoDigests' => []],
            'fixture-operator:source' => ['Id' => $this->operator, 'Config' => $configuration, 'RepoTags' => ['fixture-operator:source'], 'RepoDigests' => []],
        ];
        $builds = [];
        $ownership = $this->root . '/artifacts/image-ownership.json';
        $runner = new DevDataRunner(static function (array $arguments) use (&$inventory, &$builds, $configuration, $ownership): string|ProcessResult {
            $args = array_slice($arguments, 3);
            if ($args[0] === 'info') {
                return '{"ID":"image-daemon"}';
            }
            if (array_slice($args, 0, 2) === ['image', 'inspect']) {
                $reference = $args[2];
                $image = $inventory[$reference] ?? array_find($inventory, static fn(array $image): bool => $image['Id'] === $reference);
                return $image === null ? new ProcessResult(1, null, false, false, '', 'Error response from daemon: No such image: ' . $reference, 0) : json_encode([$image], JSON_THROW_ON_ERROR);
            }
            if (array_slice($args, 0, 2) === ['image', 'ls']) {
                $lines = [];
                foreach ($inventory as $reference => $image) {
                    [$repository, $tag] = explode(':', $reference, 2);
                    $lines[] = json_encode(['Repository' => $repository, 'Tag' => $tag, 'ID' => $image['Id']], JSON_THROW_ON_ERROR);
                }
                return implode("\n", $lines);
            }
            if (array_slice($args, 0, 2) === ['image', 'tag']) {
                self::assertSame($args[2], AtomicFile::readJson($ownership)['aliases'][$args[3]]);
                $inventory[$args[3]] = array_find($inventory, static fn(array $image): bool => $image['Id'] === $args[2]);
                return '';
            }
            if ($args[0] === 'build') {
                $target = $args[array_search('--target', $args, true) + 1];
                $tag = $args[array_search('--tag', $args, true) + 1];
                self::assertArrayHasKey($tag, AtomicFile::readJson($ownership)['images']);
                self::assertNull(AtomicFile::readJson($ownership)['images'][$tag]);
                $built = $configuration;
                foreach ($args as $index => $argument) {
                    if ($argument === '--label') {
                        [$key, $value] = explode('=', $args[$index + 1], 2);
                        $built['Labels'][$key] = $value;
                    }
                }
                $id = 'sha256:' . hash('sha256', $target);
                $inventory[$tag] = ['Id' => $id, 'Config' => $built, 'RepoTags' => [$tag], 'RepoDigests' => []];
                $builds[] = $args;
                return '';
            }
            throw new \RuntimeException('Unexpected fake Docker command: ' . implode(' ', $args));
        });
        $result = $this->images($runner)->build($this->app, $this->operator, $this->revision, $this->root . '/dataset.json');
        self::assertCount(3, $builds);
        foreach (['app', 'worker', 'operator'] as $index => $role) {
            self::assertSame('sha256:' . hash('sha256', $role), $result[$role]);
            self::assertContains('--network=none', $builds[$index]);
            self::assertContains('--pull=false', $builds[$index]);
            self::assertTrue(array_any($builds[$index], static fn(string $argument): bool => str_starts_with($argument, 'APP_BASE=baander-build-input-')));
        }
        self::assertFileExists($result['ownershipReceipt']);
        self::assertSame('ready', AtomicFile::readJson($result['receipt'])['phase']);
        self::assertSame($this->app, AtomicFile::readJson($result['receipt'])['manifest']['appBase']);
        self::assertDirectoryDoesNotExist($result['context']);
    }
}
