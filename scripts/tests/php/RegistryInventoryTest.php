<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Registry\Inventory\ImageVerifier;
use Baander\Registry\Qualification\DockerOwnership;
use Baander\Registry\Inventory\Inventory;
use Baander\Registry\Inventory\InventoryError;
use PHPUnit\Framework\TestCase;

require dirname(__DIR__, 3) . '/relay/vendor/autoload.php';

final class RegistryInventoryTest extends TestCase
{
    private string $temporary;
    private string $root;
    private string $resolutions;
    private string $bundle;
    /** @var list<array<string, mixed>> */
    private array $components;
    /** @var list<array<string, mixed>> */
    private array $executables;
    /** @var array<string, string> */
    private array $binaries;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/registry-inventory-test-' . bin2hex(random_bytes(8));
        $this->root = $this->temporary . '/root';
        $this->resolutions = $this->temporary . '/resolutions';
        $this->bundle = $this->root . Inventory::BUNDLE_PATH;
        Inventory::write(
            $this->root . '/var/lib/dpkg/status',
            Inventory::read(dirname(__DIR__, 3) . '/relay/tests/inventory_fixtures/packages.txt'),
        );
        Inventory::write(
            $this->root . '/usr/share/doc/fixture-lib/copyright',
            "Format: https://www.debian.org/doc/packaging-manuals/copyright-format/1.0/\nFiles: *\nCopyright: Fixture authors\nLicense: MIT\n Full fixture license.\n",
        );
        Inventory::write($this->resolutions . '/go/NOTICE', "Fixture license notice\n");
        $notice = ['path' => 'go/NOTICE', 'sha256' => Inventory::digest("Fixture license notice\n")];
        $this->components = [];
        foreach (['go-toolchain:go1.25.3', 'go:fixture/main@v1.0.0', 'go:fixture/dependency@v2.0.0'] as $id) {
            $this->components[] = [
                'id' => $id,
                'name' => $id,
                'version' => 'fixture',
                'license' => 'MIT',
                'notices' => [$notice],
                'source' => ['url' => 'https://baander.app/fixtures/source', 'sha256' => str_repeat('a', 64)],
            ];
        }
        $this->components[] = [
            'id' => 'native:sqlite',
            'name' => 'SQLite',
            'version' => 'fixture',
            'license' => 'LicenseRef-Public-Domain',
            'notices' => [$notice],
            'parent' => 'go:fixture/dependency@v2.0.0',
        ];
        $this->components[] = [
            'id' => 'native:api',
            'name' => 'native API dependency',
            'version' => 'fixture',
            'license' => 'MIT',
            'notices' => [$notice],
            'family' => 'api',
            'scope' => 'runtime',
        ];
        $this->components[] = [
            'id' => 'native:googletest',
            'name' => 'GoogleTest',
            'version' => 'fixture',
            'license' => 'BSD-3-Clause',
            'notices' => [$notice],
            'family' => 'api',
            'scope' => 'build-test',
        ];
        $this->components[2]['incorporated_components'] = ['native:sqlite'];
        $this->executables = $this->binaries = [];
        foreach (['rqlited', 'age', 'age-keygen', 'rclone'] as $name) {
            $data = self::goBinary($name, [['fixture/dependency', 'v2.0.0']]);
            Inventory::write($this->root . '/usr/local/bin/' . $name, $data);
            $this->binaries[$name] = $data;
            $this->executables[] = ['path' => '/usr/local/bin/' . $name, 'sha256' => Inventory::digest($data)];
        }
        $this->binaries['baander-registry'] = 'fixture native executable';
        Inventory::write($this->root . '/usr/local/bin/baander-registry', $this->binaries['baander-registry']);
        Inventory::write(
            $this->resolutions . '/native/source-files.json',
            Inventory::canonical(['boost/boost/fixture.hpp' => str_repeat('b', 64), 'json/include/fixture.hpp' => str_repeat('c', 64)]),
        );
        Inventory::write(
            $this->bundle . '/api-build.json',
            Inventory::canonical([
                'sha256' => Inventory::digest($this->binaries['baander-registry']),
                'linker_map' => 'fixture map',
                'link_commands' => '/opt/openssl/lib/libssl.a /opt/openssl/lib/libcrypto.a',
                'static_libraries' => [
                    ['path' => '/opt/openssl/lib/libssl.a', 'sha256' => str_repeat('d', 64)],
                    ['path' => '/opt/openssl/lib/libcrypto.a', 'sha256' => str_repeat('e', 64)],
                ],
                'inputs' => [
                    ['path' => '/build/_deps/boost_headers-src/boost/fixture.hpp', 'sha256' => str_repeat('b', 64)],
                    ['path' => '/build/_deps/json-src/include/fixture.hpp', 'sha256' => str_repeat('c', 64)],
                ],
            ]),
        );
        $this->save();
    }

    protected function tearDown(): void
    {
        Inventory::removeTree($this->temporary);
    }

    private function save(): void
    {
        Inventory::write($this->resolutions . '/go/components.json', Inventory::canonical($this->components));
        Inventory::write($this->resolutions . '/go/executables.json', Inventory::canonical($this->executables));
    }

    /** @return array<string, mixed> */
    private function generate(string $kind = 'backup'): array
    {
        foreach ($this->binaries as $name => $data) {
            $path = $this->root . '/usr/local/bin/' . $name;
            if (!in_array($name, Inventory::EXECUTABLES[$kind], true) && is_file($path)) {
                unlink($path);
            }
        }
        return Inventory::generate($this->root, $kind, $this->resolutions, $this->bundle);
    }

    /**
     * @param list<array{string, string}> $dependencies
     * @param list<array{string, string}> $settings
     */
    private static function goBinary(string $name, array $dependencies = [], array $settings = [], string $extra = ''): string
    {
        $string = static function (string $value): string {
            $size = strlen($value);
            $prefix = '';
            while ($size >= 128) {
                $prefix .= chr(($size & 127) | 128);
                $size >>= 7;
            }
            return $prefix . chr($size) . $value;
        };
        $raw = pack('H*', '3077af0c9274080241e1c107e6d618e6') . "path\tfixture/" . $name . "\nmod\tfixture/main\tv1.0.0\th1:main\n";
        foreach ($dependencies as [$path, $version]) {
            $raw .= "dep\t" . $path . "\t" . $version . "\th1:dependency\n";
        }
        foreach ($settings as [$key, $value]) {
            $raw .= "build\t" . $key . '=' . $value . "\n";
        }
        $raw .= $extra . pack('H*', 'f932433186182072008242104116d8f2');
        return "\xff Go buildinf:\x08\x02" . str_repeat("\0", 16) . $string('go1.25.3') . $string($raw);
    }

    private function rejects(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('Expected inventory rejection: ' . $message);
        } catch (InventoryError $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }

    public function testBinaryGoFramingIsNotInterpretedAsUtf8(): void
    {
        $info = Inventory::goBuildinfo(self::goBinary('rqlited'), 'rqlited');
        self::assertSame('fixture/rqlited', $info['package']);
        self::assertSame('go1.25.3', $info['go_version']);
        $this->rejects(
            static fn() => Inventory::goBuildinfo(self::goBinary('binary', [], [], "invalid\xff\n"), 'binary'),
            'invalid Go build information',
        );
    }
    public function testModernGoBuildinfoReplacementAndMissingBuildinfo(): void
    {
        $info = Inventory::goBuildinfo(self::goBinary('binary', [], [], "=>\treplaced\tv3\th1:replacement\n"), 'binary');
        self::assertSame('go1.25.3', $info['go_version']);
        self::assertSame(
            ['path' => 'replaced', 'version' => 'v3', 'sum' => 'h1:replacement'],
            $info['modules'][0]['replacement'],
        );
        $this->rejects(
            static fn() => Inventory::goBuildinfo('ELF', 'age-keygen'),
            'age-keygen: missing usable Go build information',
        );
    }

    public function testImageSymlinksAndRemovedDebianPackages(): void
    {
        Inventory::write($this->root . '/usr/share/doc/common/copyright', 'MIT license');
        symlink('/usr/share/doc/common', $this->root . '/usr/share/doc/pkg');
        self::assertSame(
            $this->root . '/usr/share/doc/common/copyright',
            Inventory::rootFile($this->root, '/usr/share/doc/pkg/copyright'),
        );
        symlink('/missing', $this->root . '/broken');
        $this->rejects(fn() => Inventory::rootFile($this->root, '/broken'), 'missing');
        $this->rejects(fn() => Inventory::rootFile($this->root, '../../etc/passwd'), 'escapes');
        symlink('/loop', $this->root . '/loop');
        $this->rejects(fn() => Inventory::rootFile($this->root, '/loop'), 'loop');
        $records = Inventory::debianStatus("Package: live\nStatus: install ok installed\nVersion: 1\nArchitecture: amd64\n\nPackage: removed\nStatus: deinstall ok config-files\nVersion: 1\n");
        self::assertSame(['live'], array_column($records, 'Package'));
    }

    public function testEveryImageKindIsDeterministicAndOmitsBuildOnlyComponents(): void
    {
        foreach (['api', 'rqlite', 'backup'] as $kind) {
            foreach ($this->binaries as $name => $data) {
                Inventory::write($this->root . '/usr/local/bin/' . $name, $data);
            }
            $first = $this->generate($kind);
            self::assertSame(Inventory::canonical($first), Inventory::canonical($this->generate($kind)));
            self::assertEquals($first, Inventory::verify($this->root, $this->bundle . '/inventory.json'));
            self::assertCount(count(Inventory::EXECUTABLES[$kind]), $first['executables']);
            self::assertNotContains('native:googletest', array_column($first['components'], 'id'));
        }
    }

    public function testMissingTransitiveAndIncorporatedEvidenceNamesIdentity(): void
    {
        $this->components = $this->components
            |> (static fn(array $components): array => array_filter(
                $components,
                static fn(array $component): bool => $component['id'] !== 'go:fixture/dependency@v2.0.0',
            ))
            |> array_values(...);
        $this->save();
        $this->rejects(fn() => $this->generate(), 'fixture/dependency@v2.0.0');
        $this->components = $this->components
            |> (static fn(array $components): array => array_filter(
                $components,
                static fn(array $component): bool => $component['id'] !== 'native:sqlite',
            ))
            |> array_values(...);
        $this->components[] = [
            'id' => 'go:fixture/dependency@v2.0.0',
            'incorporated_components' => ['native:sqlite'],
            'license' => 'MIT',
        ];
        $this->save();
        $this->rejects(fn() => $this->generate(), 'missing incorporated component evidence for go:fixture/dependency');
    }

    public function testUnresolvedLicenseAndMissingNoticesFail(): void
    {
        $this->components[0]['license'] = 'unknown';
        $this->save();
        $this->rejects(fn() => $this->generate(), 'unresolved license: go-toolchain');
        $this->components[0]['license'] = 'MIT';
        $this->save();
        unlink($this->resolutions . '/go/NOTICE');
        $this->rejects(fn() => $this->generate(), 'missing retained file');
    }

    public function testChangedExecutableAndMissingAgeKeygenFail(): void
    {
        $this->generate();
        Inventory::write($this->root . '/usr/local/bin/rclone', self::goBinary('rclone', [['unreviewed/module', 'v9']]));
        $this->rejects(
            fn() => Inventory::verify($this->root, $this->bundle . '/inventory.json'),
            'rclone: executable differs',
        );
        unlink($this->root . '/usr/local/bin/age-keygen');
        $this->rejects(fn() => $this->generate(), 'age-keygen');
    }

    public function testEmbeddedNativeComponentCannotBeRemoved(): void
    {
        $this->generate('rqlite');
        $path = $this->bundle . '/inventory.json';
        $value = Inventory::readJson($path);
        $value['components'] = $value['components']
            |> (static fn(array $components): array => array_filter(
                $components,
                static fn(array $component): bool => $component['id'] !== 'native:sqlite',
            ))
            |> array_values(...);
        Inventory::write($path, Inventory::canonical($value));
        $this->rejects(fn() => Inventory::verify($this->root, $path), 'differs');
    }

    public function testDebianVersionAndRetainedNoticeTamperingFail(): void
    {
        $this->generate();
        $status = $this->root . '/var/lib/dpkg/status';
        $original = Inventory::read($status);
        Inventory::write($status, str_replace('1.2.3-1', '1.2.4-1', $original));
        $this->rejects(fn() => Inventory::verify($this->root, $this->bundle . '/inventory.json'), 'differs');
        Inventory::write($status, $original);
        Inventory::write($this->bundle . '/debian-notices/usr/share/doc/fixture-lib/copyright', 'tampered');
        $this->rejects(fn() => Inventory::verify($this->root, $this->bundle . '/inventory.json'), 'notice changed');
    }

    public function testDirtySourceCorrespondenceFails(): void
    {
        $this->components[1]['unresolved'] = 'dirty source identity';
        $this->save();
        $this->rejects(fn() => $this->generate(), 'fixture/main@v1.0.0: dirty source');
    }

    public function testRetainedNoticesAndTrustedResolutionsCannotChange(): void
    {
        $this->generate();
        $path = $this->bundle . '/inventory.json';
        $value = Inventory::readJson($path);
        $value['debian_notices'] = [];
        Inventory::write($path, Inventory::canonical($value));
        $this->rejects(fn() => Inventory::verify($this->root, $path), 'differs');
        $this->generate();
        Inventory::write($this->bundle . '/resolutions/go/NOTICE', 'unreviewed notice');
        $this->rejects(fn() => Inventory::verify($this->root, $path, $this->resolutions), 'independently reviewed');
    }

    public function testApiHeadersMustMatchPinnedSources(): void
    {
        $path = $this->bundle . '/api-build.json';
        $evidence = Inventory::readJson($path);
        $evidence['inputs'][0]['sha256'] = str_repeat('f', 64);
        Inventory::write($path, Inventory::canonical($evidence));
        $this->rejects(fn() => $this->generate('api'), 'compiled source differs from pinned archive: boost');
    }

    public function testSourceBuildChecksRevisionRuntimeAndModules(): void
    {
        $settings = [['vcs.revision', str_repeat('a', 40)], ['vcs.modified', 'false'], ['CGO_ENABLED', '1']];
        $binary = self::goBinary('rqlited', [['fixture/dependency', 'v2.0.0']], $settings);
        Inventory::write($this->root . '/usr/local/bin/rqlited', $binary);
        $info = Inventory::goBuildinfo($binary, 'rqlited');
        $this->executables[0] = [
            'path' => '/usr/local/bin/rqlited',
            'source_built' => true,
            'expected_vcs_revision' => str_repeat('a', 40),
        ] + $info;
        $this->save();
        $this->generate('rqlite');
        $settings[2][1] = '0';
        Inventory::write(
            $this->root . '/usr/local/bin/rqlited',
            self::goBinary('rqlited', [['fixture/dependency', 'v2.0.0']], $settings),
        );
        $this->rejects(fn() => $this->generate('rqlite'), 'runtime build settings differ');
        $settings[2][1] = '1';
        Inventory::write(
            $this->root . '/usr/local/bin/rqlited',
            self::goBinary('rqlited', [['fixture/dependency', 'v9.0.0']], $settings),
        );
        $this->rejects(fn() => $this->generate('rqlite'), 'module graph differs');
        $settings[1][1] = 'true';
        Inventory::write(
            $this->root . '/usr/local/bin/rqlited',
            self::goBinary('rqlited', [['fixture/dependency', 'v2.0.0']], $settings),
        );
        $this->rejects(fn() => $this->generate('rqlite'), 'VCS/toolchain differs');
    }

    public function testUnknownPackagedExecutableFails(): void
    {
        Inventory::write($this->root . '/usr/local/bin/unknown-tool', 'unknown');
        $this->rejects(fn() => $this->generate(), 'unreviewed packaged executable: unknown-tool');
    }

    public function testUnresolvedDebianDeclarationsAndResolutionsFail(): void
    {
        $path = $this->root . '/usr/share/doc/fixture-lib/copyright';
        $original = Inventory::read($path);
        $this->generate();
        foreach (['Unknown', 'NOASSERTION'] as $license) {
            Inventory::write($path, str_replace('License: MIT', 'License: ' . $license, $original));
            $this->rejects(fn() => $this->generate(), 'fixture-lib: unresolved Debian');
            $this->rejects(
                fn() => Inventory::verify($this->root, $this->bundle . '/inventory.json'),
                'fixture-lib: unresolved Debian',
            );
        }
        Inventory::write($path, $original);
        foreach (['', 'Unknown', 'NOASSERTION'] as $license) {
            Inventory::write(
                $this->resolutions . '/debian/components.json',
                Inventory::canonical([['copyright_sha256' => Inventory::digest($original), 'license' => $license]]),
            );
            $this->rejects(fn() => $this->generate(), 'fixture-lib: unresolved Debian');
        }
    }

    public function testUnreviewedStaticArchivesFailGenerationAndVerification(): void
    {
        $path = $this->bundle . '/api-build.json';
        $original = Inventory::readJson($path);
        $this->generate('api');
        foreach (['/opt/unreviewed/libextra.a', 'libextra.a'] as $archive) {
            $evidence = $original;
            $evidence['static_libraries'][] = ['path' => $archive, 'sha256' => str_repeat('f', 64)];
            $evidence['link_commands'] .= ' ' . $archive;
            Inventory::write($path, Inventory::canonical($evidence));
            $this->rejects(fn() => $this->generate('api'), 'unreviewed API static archive: ' . $archive);
            $this->rejects(
                fn() => Inventory::verify($this->root, $this->bundle . '/inventory.json'),
                'unreviewed API static archive: ' . $archive,
            );
            Inventory::write($path, Inventory::canonical($original));
        }
    }

    public function testApiCaptureRetainsCompilerInputsAndStaticLinkEvidence(): void
    {
        $build = $this->temporary . '/build';
        Inventory::write($build . '/baander-registry', 'native bytes');
        Inventory::write($build . '/baander-registry.map', 'linker map bytes');
        Inventory::write($build . '/_deps/boost_headers-src/boost/fixture.hpp', 'boost bytes');
        Inventory::write($build . '/_deps/json-src/include/fixture.hpp', 'json bytes');
        Inventory::write($build . '/source.cpp', 'source bytes');
        Inventory::write($build . '/libssl.a', 'SSL archive');
        Inventory::write($build . '/libcrypto.a', 'crypto archive');
        Inventory::write(
            $build . '/compile_commands.json',
            Inventory::canonical([['file' => $build . '/source.cpp', 'command' => 'c++ source.cpp']]),
        );
        $fixture = '#!' . PHP_BINARY . "\n<?php\n" . <<<'FIXTURE'
if ($argv[4] === 'commands') {
    echo "c++ source.cpp libssl.a libcrypto.a\n";
} elseif ($argv[4] === 'deps') {
    echo "object.o:\n    _deps/boost_headers-src/boost/fixture.hpp\n    _deps/json-src/include/fixture.hpp\n";
} else {
    exit(3);
}
FIXTURE;
        Inventory::write($this->temporary . '/capture-bin/ninja', $fixture);
        chmod($this->temporary . '/capture-bin/ninja', 0755);
        $previous = getenv('PATH');
        putenv('PATH=' . $this->temporary . '/capture-bin:' . $previous);
        try {
            Inventory::captureApi($build, $build . '/evidence.json');
        } finally {
            putenv($previous === false ? 'PATH' : 'PATH=' . $previous);
        }
        $evidence = Inventory::readJson($build . '/evidence.json');
        self::assertSame(hash('sha256', 'native bytes'), $evidence['sha256']);
        self::assertSame('linker map bytes', $evidence['linker_map']);
        self::assertSame(['libcrypto.a', 'libssl.a'], array_column($evidence['static_libraries'], 'path'));
        self::assertSame(hash('sha256', 'crypto archive'), $evidence['static_libraries'][0]['sha256']);
        self::assertCount(3, $evidence['inputs']);
        self::assertSame('c++ source.cpp', $evidence['compile_commands'][0]['command']);
    }

    public function testMalformedGoLengthsAndUnmatchedReplacementsFail(): void
    {
        $this->rejects(
            static fn() => Inventory::goBuildinfo(
                "\xff Go buildinf:\x08\x02" . str_repeat("\0", 16) . "\xff\xff\xff\xff\xff\xff\xff\xff\xff\x7f",
                'binary',
            ),
            'invalid Go build information length',
        );
        $this->rejects(
            static fn() => Inventory::goBuildinfo(self::goBinary('binary', [], [], "=>\tfirst\tv1\n=>\tsecond\tv2\n"), 'binary'),
            'unmatched Go module replacement',
        );
    }

    public function testCanonicalGoldenBytesKeepUnicodeFloatsAndEmptyObjects(): void
    {
        $value = ['z' => 'é/😀', 'a' => [1.0, 1.0e-7, 1.0e20, 1.0e15, new \stdClass(), []]];
        $expected = "{\n  \"a\": [\n    1.0,\n    1e-07,\n    1e+20,\n    1000000000000000.0,\n    {},\n    []\n  ],\n  \"z\": \"\\u00e9/\\ud83d\\ude00\"\n}\n";
        self::assertSame($expected, Inventory::canonical($value));
        self::assertSame("[\n  1e+16,\n  1.234e+16,\n  -0.0\n]\n", Inventory::canonical([1e16, 1.234e16, -0.0]));
        $path = $this->temporary . '/golden.json';
        Inventory::write($path, $expected);
        self::assertSame($expected, Inventory::canonical(Inventory::readJson($path)));
        $precision = ini_set('serialize_precision', '6');
        try {
            self::assertSame("{\n  \"precise\": 1.2345678901234567\n}\n", Inventory::canonical(['precise' => 1.2345678901234567]));
            self::assertSame('6', ini_get('serialize_precision'));
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }

    public function testCommandWordsDoNotExpandShellSyntax(): void
    {
        self::assertSame(
            ['a b.a', 'c d.a', '$HOME.a', '$(touch /tmp/ignored).a', '', 'x\\q.a'],
            Inventory::words("'a b.a' c\\ d.a \"\$HOME.a\" '\$(touch /tmp/ignored).a' '' \"x\\q.a\""),
        );
        self::assertSame(["a\vb", "c\fd"], Inventory::words("a\vb c\fd"));
        $this->rejects(static fn() => Inventory::words("'open"), 'unfinished command quote');
        $this->rejects(static fn() => Inventory::words('escape\\'), 'unfinished command escape');
    }

    public function testImageVerificationOwnsFailedCreatesAndPreservesPrimaryFailure(): void
    {
        $this->generate();
        foreach (['passed', 'copy', 'create', 'cleanup', 'copy-cleanup', 'kind'] as $scenario) {
            $directory = $this->temporary . '/' . $scenario;
            mkdir($directory, 0700);
            $ownership = $directory . '/ownership.json';
            $resource = null;
            $calls = [];
            $id = str_repeat('b', 64);
            $target = [
                'version' => 1,
                'executable' => PHP_BINARY,
                'endpoint' => 'unix:///fixture-docker.sock',
                'daemonId' => 'inventory-daemon',
                'originalContext' => null,
                'snapshotDirectory' => null,
                'transportArguments' => [],
                'files' => [],
            ];
            $execute = function (array $arguments, ?string $input, float $seconds) use (&$resource, &$calls, $scenario, $ownership, $id): array {
                self::assertGreaterThan(0, $seconds);
                self::assertSame([PHP_BINARY, '--host', 'unix:///fixture-docker.sock'], array_slice($arguments, 0, 3));
                $arguments = array_slice($arguments, 3);
                $calls[] = $arguments;
                $result = ['code' => 0, 'stdout' => '', 'stderr' => ''];
                if ($arguments[0] === 'info') {
                    $result['stdout'] = json_encode(['ID' => 'inventory-daemon']);
                } elseif ($arguments[0] === 'image') {
                    $result['stdout'] = 'sha256:' . str_repeat('a', 64);
                } elseif ($arguments[0] === 'create') {
                    self::assertFileExists($ownership);
                    $saved = DockerOwnership::read($ownership);
                    self::assertNull($saved['resources'][0]['id']);
                    self::assertSame('sha256:' . str_repeat('a', 64), $arguments[array_key_last($arguments)]);
                    $resource = ['Id' => $id, 'Name' => '/' . $saved['resources'][0]['name'],
                        'Config' => ['Labels' => ['org.baander.tooling.run' => $saved['token']]]];
                    $result['stdout'] = $id;
                    if ($scenario === 'create') {
                        $result['code'] = 4;
                        $result['stderr'] = 'create failed after allocation';
                    }
                } elseif ($arguments[0] === 'container' && $arguments[1] === 'inspect') {
                    if ($resource === null) {
                        $result['code'] = 1;
                        $result['stderr'] = 'Error: No such container: ' . $arguments[array_key_last($arguments)];
                    } else {
                        $result['stdout'] = json_encode([$resource]);
                    }
                } elseif ($arguments[0] === 'container' && $arguments[1] === 'ls') {
                    self::assertNull($resource);
                } elseif ($arguments[0] === 'cp') {
                    if (str_starts_with($scenario, 'copy')) {
                        $result['code'] = 5;
                        $result['stderr'] = 'copy primary failed';
                    } else {
                        Inventory::copyTree($this->root, $arguments[2]);
                    }
                } elseif ($arguments[0] === 'rm') {
                    self::assertLessThanOrEqual(2, $seconds);
                    self::assertSame($id, $arguments[array_key_last($arguments)]);
                    if (str_contains($scenario, 'cleanup')) {
                        $result['code'] = 6;
                        $result['stderr'] = 'cleanup failed';
                    } else {
                        $resource = null;
                    }
                } else {
                    self::fail('Unexpected Docker command: ' . json_encode($arguments));
                }
                return $result;
            };
            $owner = new DockerOwnership($ownership, $target, $execute);
            $verifier = new ImageVerifier($owner, 2);
            $destination = $directory . '/evidence/' . ($scenario === 'kind' ? 'api' : 'backup') . '/' . str_repeat('a', 64) . '/receipt.json';
            ob_start();
            try {
                if ($scenario === 'passed') {
                    $verifier->qualify('mutable-tag', 'backup', $directory . '/evidence', $this->resolutions);
                    $receipt = Inventory::readJson($destination);
                    self::assertSame('passed', $receipt['status']);
                    self::assertSame('sha256:' . str_repeat('a', 64), $receipt['image_id']);
                    self::assertSame(Inventory::digest(Inventory::read($this->bundle . '/inventory.json')), $receipt['inventory_sha256']);
                } else {
                    $expected = match ($scenario) {
                        'create' => 'create failed after allocation',
                        'cleanup' => 'cleanup failed',
                        'kind' => 'expected api image',
                        default => 'copy primary failed',
                    };
                    try {
                        $verifier->qualify('tag', $scenario === 'kind' ? 'api' : 'backup', $directory . '/evidence', $this->resolutions);
                        self::fail('Expected qualification failure');
                    } catch (InventoryError $error) {
                        self::assertStringContainsString($expected, $error->getMessage());
                        if ($scenario === 'copy-cleanup') {
                            self::assertStringContainsString('cleanup failed', $error->getMessage());
                            self::assertSame('Docker command failed (5): copy primary failed', $error->getPrevious()->getMessage());
                        }
                    }
                    self::assertFileDoesNotExist($destination);
                }
            } finally {
                ob_end_clean();
            }
            self::assertContains('rm', array_column($calls, 0));
            if (str_contains($scenario, 'cleanup')) {
                self::assertFileExists($ownership);
                self::assertNotNull($resource);
            } else {
                self::assertNull($resource);
                self::assertFileDoesNotExist($ownership);
                self::assertSame(['container', 'ls', '--all', '--no-trunc', '--format', '{"ID":{{json .ID}},"Names":{{json .Names}}}'], end($calls));
            }
            unset($verifier, $owner);
        }
    }
}
