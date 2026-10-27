<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Inventory\ApplicationInventory as Inventory;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Inventory\InventoryError;
use Baander\Tooling\Inventory\Json;
use Baander\Tooling\Inventory\SafeTar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationInventoryTest extends TestCase
{
    private string $temporary;
    private string $root;
    private string $bundle;
    /** @var array<string, string> */
    private array $identity = [
        'image_id' => 'sha256:abc',
        'target' => 'production',
        'platform' => 'linux/amd64',
        'revision' => 'abc',
    ];

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/baander-inventory-' . bin2hex(random_bytes(8));
        $this->root = $this->temporary . '/root';
        $this->bundle = $this->temporary . '/bundle';
        Inventory::directory($this->root);
        $this->put(
            '/var/lib/dpkg/status',
            "Package: libc\nStatus: install ok installed\nVersion: 1\nArchitecture: amd64\n\n",
        );
        $this->put('/var/lib/dpkg/info/libc.list', "/usr/lib/libc.so\n");
        $this->put('/usr/lib/libc.so', "\x7fELFfixture");
        $this->put('/usr/share/doc/libc/copyright', "License: fixture\nSee /usr/share/common-licenses/MIT\n");
        $this->put('/usr/share/common-licenses/MIT', 'retained notice');
        $this->package([
            'name' => 'acme/runtime',
            'version' => '1',
            'license' => ['MIT'],
            'install-path' => '../acme/runtime',
        ]);
        $this->put('/var/www/html/vendor/acme/runtime/LICENSE', 'composer notice');
        $this->put('/var/www/html/composer.lock', '{"packages-dev":[{"name":"acme/dev"}]}');
    }

    protected function tearDown(): void
    {
        Collector::remove($this->temporary);
    }

    private function put(string $path, string $value): string
    {
        $local = $this->root . $path;
        Inventory::directory(dirname($local));
        file_put_contents($local, $value);
        return $local;
    }

    /** @param array<string, mixed> $package */
    private function package(array $package): void
    {
        $this->put('/var/www/html/vendor/composer/installed.json', Json::canonical(['packages' => [$package]]));
    }
    /** @return array<string, mixed> */
    private function generate(): array
    {
        return Inventory::generate($this->root, $this->identity, $this->bundle, 'abc');
    }
    /** @return array<string, mixed> */
    private function verify(): array
    {
        return Inventory::verify($this->root, $this->bundle . '/inventory.json', 'abc');
    }
    /** @param array<string, mixed> $inventory
     * @return array<string, mixed>
     */
    private function entry(array $inventory, string $path): array
    {
        return array_column($inventory['files'], null, 'path')[$path];
    }

    public function testInstalledComposerExcludesLockfileDevAndIsDeterministic(): void
    {
        $inventory = $this->generate();
        self::assertSame(['acme/runtime'], array_column($inventory['composer'], 'name'));
        self::assertSame('installed-composer', $this->entry($inventory, '/var/www/html/vendor/acme/runtime/LICENSE')['group']);
        self::assertSame('not-qualified', $inventory['redistribution_review']);
        self::assertSame(
            [[
                'id' => 'deb:libc:amd64',
                'name' => 'libc',
                'version' => '1',
                'architecture' => 'amd64',
                'source' => 'libc',
                'declared_license' => ['fixture'],
            ]],
            $inventory['debian'],
        );
        $bytes = file_get_contents($this->bundle . '/inventory.json');
        $this->generate();
        self::assertSame($bytes, file_get_contents($this->bundle . '/inventory.json'));
        self::assertSame(Json::canonical($inventory), Json::canonical($this->verify()));
    }

    public function testCanonicalBytesPreserveUnicodeObjectsFloatsAndHash(): void
    {
        $value = [
            'z' => 1.0,
            'unicode' => 'æ/😀',
            'tiny' => 1e-7,
            'large' => 1e20,
            'object' => new \stdClass(),
            'list' => [],
            'decimal' => 1e-4,
            'negative' => -0.0,
            'threshold' => 1e16,
            'numeric' => (object) ['0' => 'value'],
        ];
        $expected = '{"decimal":0.0001,"large":1e+20,"list":[],"negative":-0.0,"numeric":{"0":"value"},"object":{},"threshold":1e+16,"tiny":1e-07,"unicode":"\u00e6/\ud83d\ude00","z":1.0}' . "\n";
        self::assertSame($expected, Json::canonical($value));
        self::assertSame(hash('sha256', $expected), hash('sha256', Json::canonical($value)));
        file_put_contents($this->temporary . '/canonical.json', $expected);
        self::assertSame($expected, Json::canonical(Json::read($this->temporary . '/canonical.json')));
        $precision = ini_set('serialize_precision', '6');
        try {
            self::assertSame('{"precise":1.2345678901234567}' . "\n", Json::canonical(['precise' => 1.2345678901234567]));
            self::assertSame('6', ini_get('serialize_precision'));
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }

    public function testComposerMetapackageIsMetadataOnly(): void
    {
        $this->package([
            'name' => 'upscale/ext-swoole',
            'type' => 'metapackage',
            'version' => 'v6.2.0',
            'install-path' => null,
            'license' => ['Apache-2.0'],
        ]);
        $inventory = $this->generate();
        self::assertNull($inventory['composer'][0]['install_path']);
        self::assertSame(
            [],
            array_filter(
                $inventory['files'],
                static fn(array $f): bool => in_array('composer:upscale/ext-swoole', $f['owners'], true),
            ),
        );
        self::assertSame(
            [],
            array_filter(
                $inventory['review_gaps'],
                static fn(array $g): bool => ($g['component'] ?? '') === 'composer:upscale/ext-swoole',
            ),
        );
        $this->verify();
    }

    public function testComposerRegularPackageRequiresInstallationPath(): void
    {
        foreach ([null, ''] as $path) {
            $this->package(['name' => 'acme/runtime', 'type' => 'library', 'install-path' => $path]);
            try {
                $this->generate();
                self::fail('Missing installation path accepted');
            } catch (InventoryError $error) {
                self::assertStringContainsString('installation path: acme/runtime', $error->getMessage());
            }
        }
        $this->package(['name' => 'acme/runtime']);
        $this->expectExceptionMessage('installation path: acme/runtime');
        $this->generate();
    }

    public function testComposerPathRepositoryOwnsPhysicalSourceAndNotice(): void
    {
        $this->package([
            'name' => 'swoole-bundle/swoole-bundle',
            'type' => 'library',
            'version' => '1',
            'install-path' => '../swoole-bundle/swoole-bundle',
            'license' => ['MIT'],
        ]);
        $this->put('/var/www/html/packages/swoole-bundle/LICENSE', 'local package notice');
        $this->put('/var/www/html/packages/swoole-bundle/src/Bundle.php', '<?php // source');
        Inventory::directory($this->root . '/var/www/html/vendor/swoole-bundle');
        symlink('../../packages/swoole-bundle/', $this->root . '/var/www/html/vendor/swoole-bundle/swoole-bundle');
        $inventory = $this->generate();
        foreach ([
            '/var/www/html/packages/swoole-bundle/src/Bundle.php',
            '/var/www/html/vendor/swoole-bundle/swoole-bundle',
        ] as $path) {
            self::assertSame('installed-composer', $this->entry($inventory, $path)['group']);
            self::assertSame(['composer:swoole-bundle/swoole-bundle'], $this->entry($inventory, $path)['owners']);
        }
        self::assertContains('/var/www/html/packages/swoole-bundle/LICENSE', array_column($inventory['evidence'], 'path'));
        $this->verify();
    }

    public function testDebianAbsoluteCopyrightAlias(): void
    {
        unlink($this->root . '/usr/share/doc/libc/copyright');
        $this->put('/usr/share/doc/shared/copyright', 'See /usr/share/common-licenses/MIT');
        symlink('/usr/share/doc/shared/copyright', $this->root . '/usr/share/doc/libc/copyright');
        self::assertContains('/usr/share/common-licenses/MIT', array_column($this->generate()['evidence'], 'path'));
        $this->verify();
    }

    public function testCopiedManifestAndUnknownWasmAreNotInstalledJs(): void
    {
        $this->put('/var/www/html/ui/package.json', '{"dependencies":{"fixture":"1"}}');
        $this->put('/var/www/html/public/code.wasm', "\0asmcode");
        $inventory = $this->generate();
        self::assertSame('copied-repository', $this->entry($inventory, '/var/www/html/ui/package.json')['group']);
        self::assertContains('/var/www/html/public/code.wasm', array_column($inventory['review_gaps'], 'path'));
        self::assertSame('not-qualified', $inventory['redistribution_review']);
    }

    public function testRootEscapeRejected(): void
    {
        $this->expectException(InventoryError::class);
        Inventory::rootFile($this->root, '../../etc/passwd');
    }
    public function testSymlinkLoopRejected(): void
    {
        symlink('/loop', $this->root . '/loop');
        $this->expectExceptionMessage('symlink loop');
        $this->generate();
    }
    public function testArtifactTamperingRejected(): void
    {
        $this->generate();
        $this->put('/usr/lib/libc.so', 'changed');
        $this->expectExceptionMessage('libc.so');
        $this->verify();
    }
    public function testRetainedNoticeTamperingRejected(): void
    {
        $inventory = $this->generate();
        file_put_contents($this->bundle . '/' . $inventory['evidence'][0]['retained'], 'tamper');
        $this->expectExceptionMessage('evidence');
        $this->verify();
    }
    public function testNewArtifactRejected(): void
    {
        $this->generate();
        $this->put('/opt/new-tool', "\x7fELFnew");
        $this->expectExceptionMessage('/opt/new-tool');
        $this->verify();
    }
    public function testMissingArtifactRejected(): void
    {
        $this->generate();
        unlink($this->root . '/usr/lib/libc.so');
        $this->expectExceptionMessage('libc.so');
        $this->verify();
    }
    public function testRevisionMismatchRejected(): void
    {
        $this->expectExceptionMessage('revision');
        Inventory::generate($this->root, $this->identity, $this->bundle, 'other');
    }
    public function testInspectedIdentityMustMatchBundle(): void
    {
        $this->generate();
        $this->expectExceptionMessage('identity');
        Inventory::verify(
            $this->root,
            $this->bundle . '/inventory.json',
            'abc',
            array_replace($this->identity, ['image_id' => 'sha256:other']),
        );
    }
    public function testSummaryTamperingRejected(): void
    {
        $this->generate();
        file_put_contents($this->bundle . '/SUMMARY.txt', 'tamper');
        $this->expectExceptionMessage('summary changed');
        $this->verify();
    }

    public function testBundleOutputCannotFollowSymlinkIntoExportRoot(): void
    {
        symlink($this->root, $this->temporary . '/linked-root');
        $this->expectExceptionMessage('bundle output must be outside');
        Inventory::generate($this->root, $this->identity, $this->temporary . '/linked-root/bundle', 'abc');
    }

    public function testBundleOutputResolvesSymlinkBeforeParentTraversal(): void
    {
        mkdir($this->root . '/child');
        symlink($this->root . '/child', $this->temporary . '/linked-child');
        $this->expectExceptionMessage('bundle output must be outside');
        Inventory::generate($this->root, $this->identity, $this->temporary . '/linked-child/../bundle', 'abc');
    }

    /** @param array<string, mixed> $overrides */
    private function native(array $overrides = []): void
    {
        $this->put('/usr/local/bin/native', "\x7fELFnative");
        $this->put('/usr/share/baander-build-evidence/notices/native/LICENSE', 'native notice');
        $component = array_replace(
            [
                'id' => 'native',
                'name' => 'native',
                'version' => '1',
                'source' => ['url' => 'https://baander.app/native'],
                'artifacts' => [['path' => '/usr/local/bin/native', 'sha256' => hash('sha256', "\x7fELFnative")]],
                'notices' => ['/usr/share/baander-build-evidence/notices/native/LICENSE'],
            ],
            $overrides,
        );
        $this->put(
            '/usr/share/baander-build-evidence/components.json',
            Json::canonical(['schema' => 'baander-native-build-evidence/v1', 'components' => [$component]]),
        );
    }

    public function testNativeUnknownIdentityRemainsGap(): void
    {
        $this->native(['version' => 'unknown', 'source' => ['url' => 'unknown'], 'notices' => []]);
        self::assertContains(
            'native source identity or version incomplete',
            array_column($this->generate()['review_gaps'], 'reason'),
        );
    }
    public function testNativeEmbeddedReviewDoesNotGrantClearance(): void
    {
        $this->native(['embedded_dependencies' => 'reviewed']);
        $inventory = $this->generate();
        self::assertSame('native-build', $this->entry($inventory, '/usr/local/bin/native')['group']);
        self::assertSame('not-qualified', $inventory['redistribution_review']);
    }
    public function testNativeOutputHashBoundToProvenance(): void
    {
        $this->native(['artifacts' => [['path' => '/usr/local/bin/native', 'sha256' => 'incorrect']]]);
        $this->expectExceptionMessage('/usr/local/bin/native');
        $this->generate();
    }
    public function testDeduplicatedNoticesPreserveBothPaths(): void
    {
        $this->put('/var/www/html/LICENSE', 'composer notice');
        $notices = $this->generate()['evidence']
            |> (static fn(array $entries): array => array_filter(
                $entries,
                static fn(array $entry): bool => str_ends_with($entry['path'], '/LICENSE'),
            ))
            |> array_values(...);
        self::assertCount(2, $notices);
        self::assertCount(1, array_unique(array_column($notices, 'retained')));
    }
    public function testNativeMergedUsrAliasPreservesOwnershipAndOtherGaps(): void
    {
        symlink('/usr/bin', $this->root . '/bin');
        $this->put('/usr/bin/pie', "#!/usr/bin/env php\npie fixture");
        $this->put('/usr/bin/unknown', "\x7fELFunknown");
        $this->native([
            'id' => 'pie',
            'artifacts' => [['path' => '/bin/pie', 'sha256' => hash('sha256', "#!/usr/bin/env php\npie fixture")]],
        ]);
        $inventory = $this->generate();
        self::assertSame('native-build', $this->entry($inventory, '/usr/bin/pie')['group']);
        self::assertSame(['pie'], $this->entry($inventory, '/usr/bin/pie')['owners']);
        self::assertNotContains('/usr/bin/pie', array_column($inventory['review_gaps'], 'path'));
        self::assertContains('/usr/bin/unknown', array_column($inventory['review_gaps'], 'path'));
        self::assertContains(
            'embedded native dependency coverage requires review',
            array_column($inventory['review_gaps'], 'reason'),
        );
        $this->verify();
    }

    /** @param list<array{name: string, type?: string, data?: string, link?: string, mode?: int}> $entries */
    private function tar(array $entries): string
    {
        $bytes = '';
        foreach ($entries as $entry) {
            $data = $entry['data'] ?? '';
            $header = str_pad($entry['name'], 100, "\0") . sprintf('%07o', $entry['mode'] ?? 0644) . "\0" . str_repeat("0000000\0", 2)
                . sprintf('%011o', strlen($data)) . "\0" . "00000000000\0" . str_repeat(' ', 8) . ($entry['type'] ?? '0') . str_pad($entry['link'] ?? '', 100, "\0");
            $header = str_pad($header, 512, "\0");
            $checksum = array_sum(unpack('C*', $header) ?: []);
            $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
            $bytes .= $header . str_pad($data, (int) (ceil(strlen($data) / 512) * 512), "\0");
        }
        $archive = $this->temporary . '/export.tar';
        file_put_contents($archive, $bytes . str_repeat("\0", 1024));
        return $archive;
    }

    public function testArchiveAbsoluteSymlinkResolvesInsideImageAndHardlinksRetainBytes(): void
    {
        $archive = $this->tar([
            ['name' => 'alias', 'type' => '2', 'link' => '/usr/share/notice'],
            ['name' => 'hard', 'type' => '1', 'link' => 'usr/share/notice'],
            ['name' => 'usr/share/notice', 'data' => 'image notice'],
        ]);
        $root = $this->temporary . '/exported';
        SafeTar::extract($archive, $root);
        self::assertSame('image notice', file_get_contents(Inventory::rootFile($root, '/alias')));
        self::assertSame('image notice', file_get_contents($root . '/hard'));
    }

    public function testSourceArchivePolicyRejectsAbsoluteSymlinksBeforeWriting(): void
    {
        $archive = $this->tar([['name' => 'file', 'data' => 'keep out'], ['name' => 'link', 'type' => '2', 'link' => '/usr']]);
        try {
            SafeTar::extract($archive, $this->temporary . '/exported', allowAbsoluteLinks: false);
            self::fail('Absolute source link accepted');
        } catch (InventoryError $error) {
            self::assertStringContainsString('absolute archive link is forbidden', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->temporary . '/exported');
    }

    /** @return iterable<string, array{list<array{name: string, type?: string, data?: string, link?: string}>}> */
    public static function unsafeArchives(): iterable
    {
        yield 'traversal' => [[['name' => 'okay', 'data' => 'must not write'], ['name' => '../escaped']]];
        yield 'absolute' => [[['name' => '/escaped']]];
        yield 'escaping symlink' => [[['name' => 'link', 'type' => '2', 'link' => '../escaped']]];
        yield 'escaping hardlink' => [[['name' => 'link', 'type' => '1', 'link' => '../escaped']]];
        yield 'symlink parent' => [[['name' => 'link', 'type' => '2', 'link' => '/usr'], ['name' => 'link/file', 'data' => 'bad']]];
        yield 'symlink parent replacement' => [[['name' => 'link/file', 'data' => 'bad'], ['name' => 'link', 'type' => '2', 'link' => '/usr']]];
        yield 'duplicate replacement' => [[['name' => 'file', 'data' => 'good'], ['name' => 'file', 'type' => '2', 'link' => '/usr']]];
        yield 'unknown type' => [[['name' => 'file', 'type' => 'S']]];
        yield 'missing hardlink' => [[['name' => 'hard', 'type' => '1', 'link' => 'absent']]];
        yield 'cyclic hardlink' => [[['name' => 'a', 'type' => '1', 'link' => 'b'], ['name' => 'b', 'type' => '1', 'link' => 'a']]];
    }

    /** @param list<array{name: string, type?: string, data?: string, link?: string}> $entries */
    #[DataProvider('unsafeArchives')]
    public function testUnsafeArchivesFailBeforeMaterializingAnyEntry(array $entries): void
    {
        $root = $this->temporary . '/exported';
        try {
            SafeTar::extract($this->tar($entries), $root);
            self::fail('Unsafe archive accepted');
        } catch (InventoryError) {
            self::assertDirectoryDoesNotExist($root);
        }
        self::assertFileDoesNotExist($this->temporary . '/escaped');
    }

    public function testExistingDestinationSymlinksCannotBeTraversed(): void
    {
        Inventory::directory($this->temporary . '/outside');
        symlink($this->temporary . '/outside', $this->temporary . '/linked');
        $this->expectExceptionMessage('destination is a symlink');
        SafeTar::extract($this->tar([['name' => 'file', 'data' => 'bad']]), $this->temporary . '/linked/exported');
    }

    public function testMalformedArchiveFailsBeforeWritingEarlierMembers(): void
    {
        $archive = $this->tar([['name' => 'good', 'data' => 'keep out'], ['name' => 'broken', 'data' => 'bad']]);
        $stream = fopen($archive, 'r+b');
        self::assertIsResource($stream);
        fseek($stream, 1024);
        fwrite($stream, 'X');
        fclose($stream);
        try {
            SafeTar::extract($archive, $this->temporary . '/exported');
            self::fail('Bad checksum accepted');
        } catch (InventoryError $error) {
            self::assertStringContainsString('checksum', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->temporary . '/exported');
    }

    public function testExtendedArchiveNamesCannotHideTraversal(): void
    {
        $archive = $this->tar([
            ['name' => '././@LongLink', 'type' => 'L', 'data' => "../escaped\0"],
            ['name' => 'innocent', 'data' => 'bad'],
        ]);
        $this->expectExceptionMessage('unsafe archive path');
        SafeTar::extract($archive, $this->temporary . '/exported');
    }

    public function testGnuAndPaxLongNamesAreValidatedAndExtracted(): void
    {
        $name = str_repeat('long/', 30) . 'file';
        $record = 'path=' . $name . "\n";
        $length = strlen($record) + 3;
        while (strlen((string) $length) + 1 + strlen($record) !== $length) {
            $length = strlen((string) $length) + 1 + strlen($record);
        }
        $archive = $this->tar([
            ['name' => 'PaxHeaders/file', 'type' => 'x', 'data' => $length . ' ' . $record],
            ['name' => 'short', 'data' => 'pax'],
            ['name' => '././@LongLink', 'type' => 'L', 'data' => "other-long\0"],
            ['name' => 'placeholder', 'data' => 'gnu'],
        ]);
        SafeTar::extract($archive, $this->temporary . '/exported');
        self::assertSame('pax', file_get_contents($this->temporary . '/exported/' . $name));
        self::assertSame('gnu', file_get_contents($this->temporary . '/exported/other-long'));
    }

    public function testArchivePreservesExecutableModesWithoutPrivilegeBits(): void
    {
        $archive = $this->tar([
            ['name' => 'bin', 'type' => '5', 'mode' => 0555],
            ['name' => 'bin/tool', 'data' => 'executable', 'mode' => 04755],
        ]);
        SafeTar::extract($archive, $this->temporary . '/exported');
        self::assertSame(0755, fileperms($this->temporary . '/exported/bin/tool') & 07777);
        self::assertSame(0555, fileperms($this->temporary . '/exported/bin') & 0777);
        chmod($this->temporary . '/exported/bin', 0755);
    }

    public function testCollectionPinsImageAndRemovesInspectionContainer(): void
    {
        $result = $this->collectWithFakeDocker();
        self::assertSame(0, $result[0], $result[2]);
        $inventory = Json::read($this->bundle . '/inventory.json');
        self::assertEquals($this->identity, $inventory['identity']);
        self::assertFileExists($this->bundle . '/checksums.json');
        self::assertFileExists($this->bundle . '/collection.log');
        $calls = $this->dockerCalls();
        self::assertSame(['create', '--name', $calls[1][2], '--entrypoint', '/bin/true', 'sha256:abc'], $calls[1]);
        self::assertSame(['rm', '-v', '--', $calls[1][2]], $calls[3]);
    }

    public function testCollectionFailureRetainsDiagnosticsAndRemovesContainer(): void
    {
        file_put_contents($this->temporary . '/fail-export', '');
        $result = $this->collectWithFakeDocker();
        self::assertSame(9, $result[0]);
        self::assertStringContainsString('Controlled export failure', (string) file_get_contents($this->bundle . '/collection.log'));
        self::assertSame(['rm', '-v', '--', $this->dockerCalls()[1][2]], $this->dockerCalls()[3]);
    }

    /** @return iterable<string, array{int}> */
    public static function signals(): iterable
    {
        yield 'termination' => [15];
        yield 'interrupt' => [2];
    }

    #[DataProvider('signals')]
    public function testInterruptedCollectionReturnsSignalAndCleansOwnedResources(int $signal): void
    {
        file_put_contents($this->temporary . '/pause-export', '');
        $result = $this->collectWithFakeDocker('abc', $signal);
        self::assertSame(128 + $signal, $result[0], $result[2]);
        $calls = $this->dockerCalls();
        self::assertSame(['rm', '-v', '--', $calls[1][2]], $calls[3]);
        self::assertDirectoryDoesNotExist(dirname($calls[2][2]));
    }

    public function testCollectionRejectsWrongRevisionBeforeCreatingContainer(): void
    {
        $result = $this->collectWithFakeDocker('wrong');
        self::assertSame(1, $result[0]);
        self::assertStringContainsString('revision', $result[2]);
        self::assertSame([], array_filter($this->dockerCalls(), static fn(array $call): bool => $call[0] === 'create'));
    }

    public function testShellCleansReceiptWhenCollectorIsKilledBetweenProcesses(): void
    {
        file_put_contents($this->temporary . '/block-create-log', '');
        $result = $this->collectWithFakeDocker('abc', 15, true);
        self::assertSame(143, $result[0], $result[2]);
        $calls = $this->dockerCalls();
        self::assertSame(['rm', '-v', '--', $calls[1][2]], $calls[2]);
        $work = sys_get_temp_dir() . '/baander-application-inventory.' . substr($calls[1][2], strlen('baander-application-inventory-'));
        self::assertDirectoryDoesNotExist($work);
    }

    public function testCliRejectsMissingSwooleBeforeCreatingOutput(): void
    {
        $output = $this->temporary . '/must-not-exist';
        $child = proc_open(
            [
                PHP_BINARY,
                '-n',
                dirname(__DIR__, 2) . '/packaged_inventory.php',
                'collect',
                '--image',
                'baander.app/app:fixture',
                '--target',
                'production',
                '--revision',
                'abc',
                '--output',
                $output,
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($child);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($child));
        self::assertStringContainsString('swoole is required', $stderr);
        self::assertDirectoryDoesNotExist($output);
    }

    public function testCliCanReadInventoryBeyondConfiguredPhpMemoryCap(): void
    {
        $inventory = $this->temporary . '/large-inventory.json';
        $stream = fopen($inventory, 'wb');
        self::assertIsResource($stream);
        fwrite($stream, '{"schema":"unsupported","identity":{"target":"production"},"padding":"');
        for ($index = 0; $index < 16; ++$index) {
            fwrite($stream, str_repeat('x', 1024 * 1024));
        }
        fwrite($stream, '"}');
        fclose($stream);
        $inspect = $this->temporary . '/inspect.json';
        file_put_contents(
            $inspect,
            '[{"Id":"sha256:abc","Os":"linux","Architecture":"amd64","Config":{"Labels":{"org.opencontainers.image.revision":"abc"}}}]',
        );
        $child = proc_open(
            [
                PHP_BINARY,
                '-d',
                'memory_limit=16M',
                dirname(__DIR__, 2) . '/packaged_inventory.php',
                'verify',
                '--root',
                $this->root,
                '--inventory',
                $inventory,
                '--image-inspect',
                $inspect,
                '--revision',
                'abc',
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($child);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($child));
        self::assertStringContainsString('unsupported inventory schema', $stderr);
    }

    /** @return list<list<string>> */
    private function dockerCalls(): array
    {
        return (file($this->temporary . '/calls.jsonl', FILE_IGNORE_NEW_LINES) ?: [])
            |> (static fn(array $lines): array => array_map(
                static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                $lines,
            ));
    }

    /** @return array{int, string, string} */
    private function collectWithFakeDocker(string $revision = 'abc', int $signal = 0, bool $betweenProcesses = false): array
    {
        $entries = [];
        foreach (Inventory::files($this->root) as $path) {
            $entries[] = ['name' => ltrim($path, '/'), 'data' => (string) file_get_contents($this->root . $path)];
        }
        $this->tar($entries);
        file_put_contents(
            $this->temporary . '/inspect.json',
            Json::canonical([[
                'Id' => 'sha256:abc',
                'Os' => 'linux',
                'Architecture' => 'amd64',
                'Config' => ['Labels' => ['org.opencontainers.image.revision' => $revision]],
            ]]),
        );
        mkdir($this->temporary . '/fake-bin');
        symlink(__DIR__ . '/Fixtures/inventory-docker.php', $this->temporary . '/fake-bin/docker');
        $environment = getenv();
        $environment['PATH'] = $this->temporary . '/fake-bin:' . ($environment['PATH'] ?? '');
        $environment['INVENTORY_DOCKER_STATE'] = $this->temporary;
        $child = proc_open(
            [
                'bash',
                dirname(__DIR__, 2) . '/collect-packaged-inventory.sh',
                '--image',
                'baander.app/app:fixture',
                '--target',
                'production',
                '--revision',
                'abc',
                '--output',
                $this->bundle,
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: $environment,
        );
        self::assertIsResource($child);
        if ($betweenProcesses) {
            $deadline = microtime(true) + 5;
            while (!is_file($this->temporary . '/create-block-ready') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($this->temporary . '/collector-pid');
            $pid = (int) file_get_contents($this->temporary . '/collector-pid');
            while (trim((string) file_get_contents('/proc/' . $pid . '/wchan')) !== 'wait_for_partner' && microtime(true) < $deadline) {
                usleep(10000);
            }
            $blocked = trim((string) file_get_contents('/proc/' . $pid . '/wchan'));
            unlink($this->bundle . '/collection.log');
            file_put_contents($this->bundle . '/collection.log', '');
            \Swoole\Process::kill($pid, $signal);
            self::assertSame(
                'wait_for_partner',
                $blocked,
                'Collector must be outside ProcessRunner, blocked in synchronous log I/O',
            );
        } elseif ($signal !== 0) {
            $deadline = microtime(true) + 5;
            while (!is_file($this->temporary . '/export-ready') && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (!is_file($this->temporary . '/export-ready')) {
                proc_terminate($child, 9);
                self::fail('Export child did not become ready');
            }
            \Swoole\Process::kill(proc_get_status($child)['pid'], $signal);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($child), $stdout, $stderr];
    }
}
