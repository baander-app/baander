<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Dependencies\Inventory;
use Baander\Tooling\Dependencies\LocalEvidence;
use Baander\Tooling\Dependencies\Report;
use PHPUnit\Framework\TestCase;

final class PhpDependencyInventoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-dependency-inventory-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $projects = [];
        foreach (['composer.json', 'scripts/composer.json', 'relay/composer.json', 'packages/tsduck/composer.json', 'packages/tooling/composer.json', 'packages/rules/composer.json', 'packages/fork/composer.json'] as $index => $path) {
            $this->put($path, ['name' => 'baander/package-' . $index, 'require' => ['php' => '^8.5'], 'require-dev' => ['phpunit/phpunit' => '^12']]);
            $projects[$path] = ['kind' => $index < 4 ? 'locked' : 'package', 'owner' => 'fixture'];
            if ($index < 4) {
                $lockPath = str_replace('.json', '.lock', $path);
                $projects[$path]['lock'] = $lockPath;
                $this->put($lockPath, ['packages' => [], 'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '12.0.0']]]);
            }
        }
        $this->put('packages/baander-tooling/resources/php-dependencies.json', ['version' => 1, 'projects' => $projects]);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    /** @param array<string,mixed> $value */
    private function put(string $path, array $value): void
    {
        if (!is_dir(dirname($this->root . '/' . $path))) {
            mkdir(dirname($this->root . '/' . $path), 0700, true);
        }
        file_put_contents($this->root . '/' . $path, json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function testSevenProjectsIncludeManifestOnlyDevelopmentAndLockedDevelopmentWithoutVendor(): void
    {
        $inventory = Inventory::load($this->root);
        self::assertCount(7, $inventory->projects);
        self::assertSame([], $inventory->gaps);
        self::assertSame('require-dev', $inventory->projects['packages/fork/composer.json']['requirements'][1]['section']);
        self::assertSame('phpunit/phpunit', $inventory->projects['composer.json']['lock']['packages-dev'][0]['name']);
        $report = new Report(true);
        LocalEvidence::collect($this->root, $inventory, $report);
        self::assertSame(0, $report->exitCode());
        self::assertStringContainsString('Installed metadata unavailable', $report->render());
    }

    public function testNewManifestRequiresClassificationButVendorAndSymlinkTreesAreExcluded(): void
    {
        $this->put('new-project/composer.json', ['name' => 'baander/new']);
        $this->put('vendor/foreign/composer.json', ['name' => 'baander/vendor']);
        symlink($this->root . '/new-project', $this->root . '/linked');
        $inventory = Inventory::load($this->root);
        self::assertCount(1, $inventory->gaps);
        self::assertSame('new-project/composer.json', $inventory->gaps[0]['path']);
        self::assertStringContainsString('classification', $inventory->gaps[0]['reason']);
    }

    public function testFixtureNeedsReasonAndMissingLockPreservesManifest(): void
    {
        $this->put('tests/fixture/composer.json', ['name' => 'baander/fixture']);
        $policy = Inventory::json($this->root . '/packages/baander-tooling/resources/php-dependencies.json');
        $policy['projects']['tests/fixture/composer.json'] = ['kind' => 'fixture', 'owner' => 'tests', 'reason' => 'Parser input'];
        $this->put('packages/baander-tooling/resources/php-dependencies.json', $policy);
        unlink($this->root . '/scripts/composer.lock');
        $inventory = Inventory::load($this->root);
        self::assertCount(7, $inventory->projects);
        self::assertCount(1, $inventory->gaps);
        self::assertSame('scripts/composer.lock', $inventory->gaps[0]['path']);
        self::assertNull($inventory->projects['scripts/composer.json']['lock']);
    }

    public function testGeneratedManifestCopiesNeedExplicitTreeExclusion(): void
    {
        $this->put('var/context/composer.json', ['name' => 'baander/generated']);
        self::assertCount(1, Inventory::load($this->root)->gaps);
        $policy = Inventory::json($this->root . '/packages/baander-tooling/resources/php-dependencies.json');
        $policy['excludedTrees'] = ['var' => ['kind' => 'generated', 'reason' => 'Saved test contexts']];
        $this->put('packages/baander-tooling/resources/php-dependencies.json', $policy);
        self::assertSame([], Inventory::load($this->root)->gaps);
    }

    public function testDuplicateRuntimeDeclarationsAreIncomplete(): void
    {
        file_put_contents($this->root . '/Dockerfile', "ARG PHP_VERSION=8.5.12\nARG PHP_VERSION=8.5.13\n");
        $this->expectExceptionMessage('exactly one');
        LocalEvidence::declaration($this->root . '/Dockerfile', ['kind' => 'assignment', 'key' => 'PHP_VERSION']);
    }

    public function testPatchHashAndStaleEntriesRemainAttributableFindings(): void
    {
        $manifest = Inventory::json($this->root . '/composer.json');
        $manifest['extra']['patches'] = ['public/package' => ['Fix' => 'patches/fix.patch']];
        $this->put('composer.json', $manifest);
        $this->put('composer.lock', ['packages' => [['name' => 'public/package', 'version' => '1.0.0']], 'packages-dev' => []]);
        $this->put('patches.lock.json', ['_hash' => str_repeat('a', 64), 'patches' => ['public/package' => [['package' => 'public/package', 'description' => 'Fix', 'url' => 'patches/fix.patch', 'sha256' => str_repeat('0', 64), 'depth' => 1], ['url' => 'patches/stale.patch']]]]);
        mkdir($this->root . '/patches');
        file_put_contents($this->root . '/patches/fix.patch', 'changed');
        $report = new Report(true);
        LocalEvidence::collect($this->root, Inventory::load($this->root), $report);
        self::assertSame(1, $report->exitCode());
        self::assertStringContainsString('Stale patch lock entry', $report->render());
        self::assertStringContainsString('hash, depth or proof', $report->render());
    }
    public function testExpandedPatchDefinitionHashMustAgreeWithFileAndLock(): void
    {
        foreach ([hash('sha256', 'patch bytes') => 0, strtoupper(hash('sha256', 'patch bytes')) => 0, str_repeat('0', 64) => 1, 'malformed' => 2] as $declaredHash => $expectedStatus) {
            $manifest = Inventory::json($this->root . '/composer.json');
            $manifest['extra']['patches'] = ['public/package' => [['description' => 'Fix', 'url' => 'patches/fix.patch', 'sha256' => $declaredHash, 'depth' => 1]]];
            $this->put('composer.json', $manifest);
            $this->put('composer.lock', ['packages' => [['name' => 'public/package', 'version' => '1.0.0']], 'packages-dev' => []]);
            $this->put('patches.lock.json', ['_hash' => str_repeat('a', 64), 'patches' => ['public/package' => [['package' => 'public/package', 'description' => 'Fix', 'url' => 'patches/fix.patch', 'sha256' => hash('sha256', 'patch bytes'), 'depth' => 1]]]]);
            if (!is_dir($this->root . '/patches')) {
                mkdir($this->root . '/patches');
            }
            file_put_contents($this->root . '/patches/fix.patch', 'patch bytes');
            $this->put('tests/proof.php', []);
            $policy = Inventory::json($this->root . '/packages/baander-tooling/resources/php-dependencies.json');
            $policy['patches'] = ['patches/fix.patch' => ['package' => 'public/package', 'proofs' => ['tests/proof.php']]];
            $this->put('packages/baander-tooling/resources/php-dependencies.json', $policy);
            $report = new Report(true);
            LocalEvidence::collect($this->root, Inventory::load($this->root), $report);
            self::assertSame($expectedStatus, $report->exitCode(), $report->render());
        }
    }

    /** @param list<array<string,mixed>> $files */
    private function forkFixture(array $files): void
    {
        $manifest = Inventory::json($this->root . '/composer.json');
        $manifest['require']['public/fork'] = '^1.0';
        $this->put('composer.json', $manifest);
        $this->put('tests/proof.php', []);
        $this->put('packages/fork/upstream-review.json', ['reference' => 'reviewed-reference', 'tag' => '1.0.0', 'files' => $files]);
        $policy = Inventory::json($this->root . '/packages/baander-tooling/resources/php-dependencies.json');
        $policy['forks'] = ['public/fork' => ['path' => 'packages/fork', 'reviewedReference' => 'reviewed-reference', 'reviewedVersion' => '1.0.0', 'comparison' => 'packages/fork/upstream-review.json', 'reason' => 'Owned local integration', 'proofs' => ['tests/proof.php']]];
        $this->put('packages/baander-tooling/resources/php-dependencies.json', $policy);
        if (!is_dir($this->root . '/packages/fork/src')) {
            mkdir($this->root . '/packages/fork/src');
        }
        file_put_contents($this->root . '/packages/fork/src/Foo.php', 'reviewed bytes');
    }

    /** @phpstan-impure */
    private function localReport(): Report
    {
        $report = new Report(true);
        LocalEvidence::collect($this->root, Inventory::load($this->root), $report);
        return $report;
    }

    public function testForkInventoryDetectsChangedDeletedAndUnaccountedSource(): void
    {
        $this->forkFixture([['path' => 'src/Foo.php', 'current_source_presence' => 'present', 'current_local_sha256' => hash('sha256', 'reviewed bytes')], ['path' => 'src/UpstreamOnly.php', 'current_source_presence' => 'absent', 'current_local_sha256' => null]]);
        self::assertSame(0, $this->localReport()->exitCode());
        file_put_contents($this->root . '/packages/fork/src/Foo.php', 'changed bytes');
        self::assertSame(1, $this->localReport()->exitCode());
        unlink($this->root . '/packages/fork/src/Foo.php');
        self::assertSame(1, $this->localReport()->exitCode());
        file_put_contents($this->root . '/packages/fork/src/Foo.php', 'reviewed bytes');
        file_put_contents($this->root . '/packages/fork/src/New.php', 'new bytes');
        $report = $this->localReport();
        self::assertSame(1, $report->exitCode());
        self::assertStringContainsString('src/New.php', $report->render());
        self::assertStringContainsString('no reviewed inventory entry', $report->render());
    }

    public function testForkInventoryRejectsMalformedPresenceHashesAndPaths(): void
    {
        $record = ['path' => 'src/Foo.php', 'current_source_presence' => 'present', 'current_local_sha256' => hash('sha256', 'reviewed bytes')];
        foreach ([[$record, $record], [['path' => 'src/../outside.php', 'current_source_presence' => 'absent', 'current_local_sha256' => null]], [['path' => 'src/Foo.php', 'current_source_presence' => 'present', 'current_local_sha256' => null]]] as $files) {
            $this->forkFixture($files);
            self::assertSame(2, $this->localReport()->exitCode());
        }
    }

    public function testDuplicateKnownRuntimeArchiveHashesAreIncomplete(): void
    {
        file_put_contents($this->root . '/Dockerfile', "ARG PHP_VERSION=8.5.12\nARG PHP_SHA256=" . str_repeat('a', 64) . "\nARG PHP_SHA256=" . str_repeat('b', 64) . "\n");
        $policy = Inventory::json($this->root . '/packages/baander-tooling/resources/php-dependencies.json');
        $policy['runtimes'] = ['php' => ['minimum' => '8.5.12', 'declarations' => [['path' => 'Dockerfile', 'kind' => 'assignment', 'key' => 'PHP_VERSION']]]];
        $this->put('packages/baander-tooling/resources/php-dependencies.json', $policy);
        $report = $this->localReport();
        self::assertSame(2, $report->exitCode());
        self::assertStringContainsString('hash declaration must have exactly one', $report->render());
    }

}
