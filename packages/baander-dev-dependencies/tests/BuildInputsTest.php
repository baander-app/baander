<?php

declare(strict_types=1);

namespace Baander\DevDependencies\Tests;

use Baander\Configuration\ToolingConfiguration;
use Baander\Containers\Docker\Client;
use Baander\Containers\Docker\Target;
use Baander\DevDependencies\Php\BuildInputs;
use Baander\DevDependencies\Php\Command;
use Baander\DevDependencies\Php\DockerEvidence;
use Baander\DevDependencies\Php\ImageDrift;
use Baander\DevDependencies\Php\Inventory;
use Baander\DevDependencies\Php\LocalEvidence;
use Baander\DevDependencies\Php\Report;
use Baander\Process\Execution\CommandExecutor;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\Process\Execution\ProcessResult;
use Baander\Process\Execution\ProcessSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuildInputsTest extends TestCase
{
    private const string DIGEST = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string BASE = "FROM debian:trixie-slim@" . self::DIGEST . "\n";

    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->root);
        }
    }

    public function testEveryRepositoryBuildInputIsPinned(): void
    {
        $root = dirname(__DIR__, 3);
        $inventory = Inventory::load($root);
        foreach (['Dockerfile', 'docker/ffmpeg/Dockerfile', 'docker/nginx/Dockerfile.test', 'docker/postgres/Dockerfile', 'docker/runner/Dockerfile', 'docker/runner/tooling-test.Dockerfile', 'relay/docker/rqlite.Dockerfile'] as $path) {
            self::assertContains($path, $inventory->dockerfiles);
        }
        $scan = BuildInputs::scan($root, $inventory->dockerfiles, $inventory->policy['buildInputs'] ?? []);
        self::assertSame([], $scan['violations'], json_encode($scan['violations'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $references = array_column($scan['images'], 'reference');
        self::assertContains(ToolingConfiguration::resolve()->images->role('developmentRedis')->reference, $references, 'The image catalog is scanned.');
        self::assertNotEmpty(array_filter($references, static fn(string $reference): bool => str_starts_with($reference, 'moby/buildkit:')), 'Workflow images are listed for the drift report.');
    }

    #[DataProvider('unpinnedProvider')]
    public function testAnUnpinnedInputIsAViolationAtItsInstruction(string $dockerfile, string $rule, int $line): void
    {
        $violations = BuildInputs::dockerfile('Dockerfile', $dockerfile)['violations'];
        self::assertSame([[$rule, $line]], array_map(static fn(array $violation): array => [$violation['rule'], $violation['line']], $violations));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function unpinnedProvider(): iterable
    {
        yield 'base image by tag' => ["FROM debian:trixie-slim\nRUN true\n", 'image-digest', 1];
        yield 'image copied by tag' => [self::BASE . "COPY --from=ghcr.io/php/pie:bin /pie /bin/pie\n", 'image-digest', 2];
        yield 'external image through an argument default' => ["ARG BASE=debian:trixie-slim\nFROM \${BASE}\n", 'image-digest', 2];
        yield 'setup script piped into bash' => [self::BASE . "RUN set -xe && \\\n    curl -sL https://deb.nodesource.com/setup_24.x | bash - && \\\n    apt-get install -y nodejs\n", 'piped-installer', 2];
        yield 'installer piped into sh' => [self::BASE . "RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \\\n      | sh -s -- -y\n", 'piped-installer', 2];
        yield 'installer run from a command substitution' => [self::BASE . "RUN sh -c \"\$(wget -qO- https://get.baander.app)\"\n", 'piped-installer', 2];
        yield 'download without a checksum step' => [self::BASE . "RUN curl -fsSL https://baander.app/tool.tar.gz -o /tmp/tool.tar.gz \\\n    && tar -xzf /tmp/tool.tar.gz -C /opt\n", 'download-checksum', 2];
        yield 'second download left unchecked' => [self::BASE . "ARG TOOL_SHA256=" . str_repeat('b', 64) . "\nRUN curl -fsSL https://baander.app/a.tgz -o /tmp/a.tgz \\\n    && echo \"\${TOOL_SHA256}  /tmp/a.tgz\" | sha256sum -c - \\\n    && wget -q https://baander.app/b.tgz\n", 'download-checksum', 3];
        yield 'checksum computed instead of pinned' => [self::BASE . "RUN wget -q -O /tmp/a.deb https://baander.app/a.deb && sha256sum /tmp/a.deb > /tmp/sums && sha256sum -c /tmp/sums\n", 'download-checksum', 2];
        yield 'remote file added without a checksum' => [self::BASE . "ADD https://baander.app/tool.tar.gz /tmp/\n", 'download-checksum', 2];
        yield 'clone by tag' => [self::BASE . "RUN git clone --depth 1 --branch v1.59 https://github.com/markus-perl/ffmpeg-build-script.git \\\n    && cd ffmpeg-build-script\n", 'git-commit', 2];
        yield 'fetch by branch' => [self::BASE . "RUN git init -q /opt/emsdk && git -C /opt/emsdk fetch -q https://github.com/emscripten-core/emsdk.git main\n", 'git-commit', 2];
        yield 'PECL package by name' => [self::BASE . "RUN pecl bundle -d /tmp/pecl imagick && docker-php-ext-install /tmp/pecl/imagick\n", 'package-fetch', 2];
        yield 'PIE package by name' => [self::BASE . "RUN pie install swoole/swoole:6.2.4 --enable-sockets\n", 'package-fetch', 2];
    }

    public function testPinnedShapesPassAndListTheirImages(): void
    {
        $hash = str_repeat('c', 64);
        $commit = str_repeat('d', 40);
        $dockerfile = <<<DOCKERFILE
            # syntax note: comments and blank lines are skipped
            ARG FFMPEG_IMAGE=scratch
            ARG TOOLING_IMAGE=baander-tooling:local
            ARG TOOL_SHA256={$hash}
            FROM \${FFMPEG_IMAGE} AS ffmpeg
            FROM debian:trixie-slim@sha256:{$hash} AS base
            # Package names are not downloads.
            RUN apt-get update && apt-get install -y curl wget git \\
                # a comment inside the instruction
                && rm -rf /var/lib/apt/lists/*
            RUN curl -fsSL https://baander.app/tool.tar.gz -o /tmp/tool.tar.gz \\
                && echo "\${TOOL_SHA256}  /tmp/tool.tar.gz" | sha256sum -c - \\
                && php -r 'foreach (["curl", "zip"] as \$name) { if (!extension_loaded(\$name)) { exit(1); } }'
            RUN git clone --depth 1 --branch v1.0 https://github.com/baander-app/tool.git /src \\
                && test "\$(git -C /src rev-parse HEAD)" = {$commit}
            RUN for archive in 'a 1.0 {$hash}' 'b 2.0 {$hash}'; do \\
                    set -- \$archive; \\
                    curl -fsSL "https://pecl.php.net/get/\$1-\$2.tgz" -o "/tmp/\$1.tgz"; \\
                    echo "\$3  /tmp/\$1.tgz" | sha256sum -c -; \\
                done
            ADD --checksum=sha256:{$hash} https://baander.app/tool.tar.gz /tmp/
            COPY --from=ffmpeg /usr/local/bin/ffmpeg /usr/bin/ffmpeg
            COPY --from=composer:2.11.1@sha256:{$hash} /usr/bin/composer /usr/bin/composer
            FROM \${TOOLING_IMAGE}
            FROM base
            DOCKERFILE;
        $scan = BuildInputs::dockerfile('docker/tool/Dockerfile', $dockerfile);
        self::assertSame([], $scan['violations']);
        self::assertSame([
            ['path' => 'docker/tool/Dockerfile', 'line' => 6, 'reference' => 'debian:trixie-slim@sha256:' . $hash],
            ['path' => 'docker/tool/Dockerfile', 'line' => 23, 'reference' => 'composer:2.11.1@sha256:' . $hash],
        ], $scan['images']);
    }

    public function testCatalogImagesNeedDigestsExceptRolesTheToolingBuilds(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-build-inputs-' . (random_bytes(8) |> bin2hex(...));
        mkdir($this->root . '/catalog', 0700, true);
        file_put_contents($this->root . '/catalog/images.json', json_encode(['version' => 1, 'images' => [
            'application' => ['reference' => 'baander-app:latest', 'capabilities' => ['application']],
            'cache' => ['reference' => 'cache.baander.app/store:8', 'capabilities' => ['redis']],
            'browser' => ['reference' => 'browser.baander.app/engine:v1.64.1-noble@' . self::DIGEST, 'capabilities' => ['browser']],
        ]], JSON_THROW_ON_ERROR));
        $scan = BuildInputs::scan($this->root, [], ['catalog' => 'catalog/images.json', 'locallyBuiltRoles' => ['application' => 'Built from this checkout.']]);
        self::assertSame([['catalog/images.json', 'image-digest', 'cache']], array_map(static fn(array $violation): array => [$violation['path'], $violation['rule'], $violation['instruction']], $scan['violations']));
        self::assertSame(['browser.baander.app/engine:v1.64.1-noble@' . self::DIGEST], array_column($scan['images'], 'reference'));

        $this->expectExceptionMessage('catalog');
        BuildInputs::scan($this->root, [], ['catalog' => 'catalog/missing.json', 'locallyBuiltRoles' => []]);
    }

    public function testLocalEvidenceReportsAnUnpinnedInputAsAFindingAtItsLine(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-build-inputs-' . (random_bytes(8) |> bin2hex(...));
        mkdir($this->root . '/packages/baander-dev-dependencies/resources', 0700, true);
        mkdir($this->root . '/docker/tool', 0700, true);
        file_put_contents($this->root . '/composer.json', '{"name":"baander/fixture"}');
        file_put_contents($this->root . '/packages/baander-dev-dependencies/resources/php-dependencies.json', '{"version":1,"projects":{"composer.json":{"kind":"package","owner":"fixture"}}}');
        file_put_contents($this->root . '/docker/tool/Dockerfile', self::BASE . "RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash -\n");
        $inventory = Inventory::load($this->root);
        self::assertSame(['docker/tool/Dockerfile'], $inventory->dockerfiles);
        $report = new Report(true);
        LocalEvidence::collect($this->root, $inventory, $report);
        self::assertSame(1, $report->exitCode(), $report->render());
        $findings = array_filter($report->toArray()['records'], static fn(array $record): bool => $record['status'] === 'finding') |> array_values(...);
        self::assertSame(['docker/tool/Dockerfile', 'build-input', 'docker/tool/Dockerfile:2', 'piped-installer'], [$findings[0]['project'], $findings[0]['kind'], $findings[0]['source'], $findings[0]['details']['rule']]);
        self::assertContains('build-inputs', $report->toArray()['requested']);
    }

    public function testImageDriftFlagSelectsTheDriftReport(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-build-inputs-' . (random_bytes(8) |> bin2hex(...));
        mkdir($this->root . '/packages/baander-dev-dependencies/resources', 0700, true);
        file_put_contents($this->root . '/composer.json', '{"name":"baander/fixture"}');
        file_put_contents($this->root . '/packages/baander-dev-dependencies/resources/php-dependencies.json', '{"version":1,"projects":{"composer.json":{"kind":"package","owner":"fixture"}}}');
        $code = 'require $argv[1]; exit(' . Command::class . '::main(["--image-drift"], $argv[2], []));';
        $executor = new EngineExecutor(ExecutionContext::bounded(30, []));
        try {
            $result = $executor->run(new ProcessSpec([PHP_BINARY, '-r', $code, dirname(__DIR__, 3) . '/scripts/bootstrap.php', $this->root], $this->root), new CompletionPolicy(null));
        } finally {
            $executor->close();
        }
        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);
        self::assertStringStartsWith('PHP dependencies: image-drift; status 0', $result->stdout);
        self::assertStringContainsString('No build input is pinned by digest.', $result->stdout);
    }

    public function testDriftComparesEachTaggedPinWithItsRegistryDigest(): void
    {
        $moved = 'sha256:' . str_repeat('e', 64);
        $answers = [
            'debian:trixie-slim' => json_encode(['mediaType' => 'application/vnd.oci.image.index.v1+json', 'digest' => self::DIGEST], JSON_THROW_ON_ERROR),
            'node:24.22.2-trixie-slim' => json_encode(['mediaType' => 'application/vnd.oci.image.index.v1+json', 'digest' => $moved], JSON_THROW_ON_ERROR),
        ];
        $asked = [];
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $spec) use ($answers, &$asked): ProcessResult {
            self::assertSame(['buildx', 'imagetools', 'inspect'], array_slice($spec->argv, 3, 3));
            self::assertSame(['--format', '{{json .Manifest}}'], array_slice($spec->argv, 7));
            $asked[] = $spec->argv[6];
            return isset($answers[$spec->argv[6]])
                ? new ProcessResult(0, null, false, false, $answers[$spec->argv[6]], '', 0.01)
                : new ProcessResult(1, null, false, false, '', 'ERROR: 429 Too Many Requests', 0.01);
        });
        $registry = new DockerEvidence(new Client($executor, new Target(PHP_BINARY, 'unix:///fixture.sock', 'fixture', null, null), []), 2);
        $report = new Report(false, imageDrift: true);
        ImageDrift::assess([
            ['path' => 'Dockerfile', 'line' => 3, 'reference' => 'debian:trixie-slim@' . self::DIGEST],
            ['path' => 'docker/runner/Dockerfile', 'line' => 1, 'reference' => 'debian:trixie-slim@' . self::DIGEST],
            ['path' => 'Dockerfile', 'line' => 1, 'reference' => 'node:24.22.2-trixie-slim@' . self::DIGEST],
            ['path' => 'docker/web/Dockerfile', 'line' => 1, 'reference' => 'mirror.baander.app/web:alpine@' . self::DIGEST],
            ['path' => 'tooling-defaults.json', 'line' => 0, 'reference' => 'ghcr.io/baander-app/tool@' . self::DIGEST],
        ], $registry, $report);
        self::assertSame(['debian:trixie-slim', 'mirror.baander.app/web:alpine', 'node:24.22.2-trixie-slim'], $asked, 'Each tag is asked once; a digest-only pin has no tag to ask about.');
        $records = [];
        foreach ($report->toArray()['records'] as $record) {
            $records[$record['project']] = [$record['status'], $record['details']['current'] ?? null];
        }
        self::assertSame([
            'debian:trixie-slim@' . self::DIGEST => ['evidence', self::DIGEST],
            'ghcr.io/baander-app/tool@' . self::DIGEST => ['evidence', null],
            'mirror.baander.app/web:alpine@' . self::DIGEST => ['gap', null],
            'node:24.22.2-trixie-slim@' . self::DIGEST => ['finding', $moved],
        ], $records);
        self::assertSame(2, $report->exitCode(), 'An unreadable registry fails the report closed.');
        self::assertSame('image-drift', $report->toArray()['mode']);
    }
}
