<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Infrastructure\Security\OAuth\StagedOAuthSecretBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class StagedOAuthSecretBundleTest extends TestCase
{
    private string $directory;
    private StagedOAuthSecretBundle $bundles;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-oauth-bundle-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->bundles = new StagedOAuthSecretBundle();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('keySizes')]
    public function testPreparationPublishesACompletePrivateBundleWithoutChangingActiveFiles(int $size): void
    {
        $active = $this->directory . '/active.key';
        self::assertSame(17, file_put_contents($active, 'active-key-marker'));
        $bundle = $this->prepare($size);
        self::assertSame('active-key-marker', file_get_contents($active));
        self::assertSame(0700, fileperms($bundle) & 07777);
        self::assertSame(posix_geteuid(), fileowner($bundle));
        foreach (['private.key', 'public.key', 'oauth.env', 'manifest.json'] as $name) {
            self::assertSame(0600, fileperms($bundle . '/' . $name) & 07777);
            self::assertSame(posix_geteuid(), fileowner($bundle . '/' . $name));
        }
        $private = openssl_pkey_get_private($this->read($bundle . '/private.key'));
        self::assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);
        self::assertSame($size, $details['bits']);
        self::assertSame($details['key'], $this->read($bundle . '/public.key'));
        self::assertSame(
            'OAUTH_PRIVATE_KEY_PATH=' . $bundle . "/private.key\n"
            . 'OAUTH_PUBLIC_KEY_PATH=' . $bundle . "/public.key\n",
            $this->read($bundle . '/oauth.env'),
        );
        $this->bundles->validate($bundle);
    }

    /** @return iterable<string,array{int}> */
    public static function keySizes(): iterable
    {
        yield '2048 bits' => [2048];
        yield '4096 bits' => [4096];
    }

    #[DataProvider('invalidSizes')]
    public function testInvalidSizesCreateNoDirectory(int $size): void
    {
        $bundle = $this->directory . '/bundle';
        $this->assertRejected(fn () => $this->bundles->prepare($bundle, $size), 'OAuth secret bundle could not be prepared.');
        self::assertDirectoryDoesNotExist($bundle);
    }

    /** @return iterable<string,array{int}> */
    public static function invalidSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'too small' => [1024];
        yield 'unsupported' => [3072];
        yield 'too large' => [8192];
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathsCreateNoBundle(string $suffix): void
    {
        $path = $suffix === 'relative' ? 'relative' : $this->directory . '/' . $suffix;
        $this->assertRejected(fn () => $this->bundles->prepare($path, 2048), 'OAuth secret bundle could not be prepared.');
        self::assertDirectoryDoesNotExist($this->directory . '/bundle');
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'relative' => ['relative'];
        yield 'parent traversal' => ['../bundle'];
        yield 'dot component' => ['./bundle'];
        yield 'duplicate slash' => ['/bundle'];
        yield 'trailing slash' => ['bundle/'];
        yield 'space' => ['bundle with space'];
        yield 'dotenv injection' => ["bundle\nAPP_SECRET=private-secret"];
        yield 'NUL' => ["bundle\0"];
        yield 'oversized' => [str_repeat('b', 1025)];
        yield 'missing parent' => ['missing/bundle'];
    }

    public function testPreparationRefusesWritableOrSymlinkedParents(): void
    {
        self::assertTrue(chmod($this->directory, 0777));
        $this->assertRejected(fn () => $this->bundles->prepare($this->directory . '/bundle', 2048), 'OAuth secret bundle could not be prepared.');
        self::assertTrue(chmod($this->directory, 0700));
        self::assertTrue(symlink($this->directory, $this->directory . '/parent-link'));
        $this->assertRejected(fn () => $this->bundles->prepare($this->directory . '/parent-link/bundle', 2048), 'OAuth secret bundle could not be prepared.');
        self::assertDirectoryDoesNotExist($this->directory . '/bundle');
    }

    public function testExistingCompleteAndPartialBundlesAreNeverOverwritten(): void
    {
        $bundle = $this->prepare();
        $before = $this->read($bundle . '/manifest.json');
        $this->assertRejected(fn () => $this->bundles->prepare($bundle, 4096), 'OAuth secret bundle could not be prepared.');
        self::assertSame($before, $this->read($bundle . '/manifest.json'));
        $this->bundles->validate($bundle);
        $partial = $this->directory . '/partial';
        self::assertTrue(mkdir($partial, 0700));
        self::assertSame(14, file_put_contents($partial . '/private.key', 'partial-marker'));
        self::assertTrue(chmod($partial . '/private.key', 0600));
        $this->assertRejected(fn () => $this->bundles->prepare($partial, 2048), 'OAuth secret bundle could not be prepared.');
        $this->assertRejected(fn () => $this->bundles->validate($partial));
        self::assertSame('partial-marker', $this->read($partial . '/private.key'));
    }

    #[DataProvider('permissionChanges')]
    public function testValidationRejectsPermissionChanges(string $name, int $mode): void
    {
        $bundle = $this->prepare();
        $path = $name === '' ? $bundle : $bundle . '/' . $name;
        self::assertTrue(chmod($path, $mode));
        $this->assertRejected(fn () => $this->bundles->validate($bundle));
    }

    /** @return iterable<string,array{string,int}> */
    public static function permissionChanges(): iterable
    {
        yield 'readable directory' => ['', 0755];
        foreach (['private.key', 'public.key', 'oauth.env', 'manifest.json'] as $name) {
            yield $name . ' readable' => [$name, 0644];
            yield $name . ' executable' => [$name, 0700];
        }
    }

    public function testValidationRejectsChangedOwnerWhenOwnershipCanBeChanged(): void
    {
        $bundle = $this->prepare();
        if (posix_geteuid() === 0) {
            self::assertTrue(chown($bundle . '/private.key', 12345));
            $this->assertRejected(fn () => $this->bundles->validate($bundle));
        } else {
            // /tmp is deliberately owned by another user and cannot be a prepare parent.
            $this->assertRejected(fn () => $this->bundles->prepare('/tmp/baander-oauth-unowned-' . bin2hex(random_bytes(8)), 2048), 'OAuth secret bundle could not be prepared.');
        }
    }

    public function testHashMismatchSymlinksMissingAndExtraFilesAreRejected(): void
    {
        $bundle = $this->prepare();
        $original = $this->read($bundle . '/public.key');
        self::assertSame(7, file_put_contents($bundle . '/public.key', 'changed'));
        $this->assertRejected(fn () => $this->bundles->validate($bundle));
        self::assertTrue(unlink($bundle . '/public.key'));
        self::assertSame(strlen($original), file_put_contents($this->directory . '/external-public.key', $original));
        self::assertTrue(chmod($this->directory . '/external-public.key', 0600));
        self::assertTrue(symlink($this->directory . '/external-public.key', $bundle . '/public.key'));
        $this->assertRejected(fn () => $this->bundles->validate($bundle));
        self::assertTrue(unlink($bundle . '/public.key'));
        $this->assertRejected(fn () => $this->bundles->validate($bundle));
        self::assertSame(strlen($original), file_put_contents($bundle . '/public.key', $original));
        self::assertTrue(chmod($bundle . '/public.key', 0600));
        self::assertSame(5, file_put_contents($bundle . '/extra', 'extra'));
        $this->assertRejected(fn () => $this->bundles->validate($bundle));
    }

    public function testValidHashesCannotAdmitMismatchedKeysOrAlteredEnvironment(): void
    {
        $first = $this->prepare();
        $second = $this->directory . '/other';
        $this->bundles->prepare($second, 2048);
        $original = $this->read($first . '/public.key');
        $this->replaceAndRehash($first, 'public.key', $this->read($second . '/public.key'));
        $this->assertRejected(fn () => $this->bundles->validate($first));
        $this->replaceAndRehash($first, 'public.key', $original);
        $originalEnvironment = $this->read($first . '/oauth.env');
        foreach ([$originalEnvironment . "APP_SECRET=private-secret\n", str_replace($first, $second, $originalEnvironment)] as $environment) {
            $this->replaceAndRehash($first, 'oauth.env', $environment);
            $this->assertRejected(fn () => $this->bundles->validate($first));
        }
        $this->replaceAndRehash($first, 'oauth.env', $originalEnvironment);
        $this->replaceAndRehash($first, 'private.key', "private-secret\n");
        $this->assertRejected(fn () => $this->bundles->validate($first));
    }

    #[DataProvider('invalidManifests')]
    public function testMalformedOrNoncanonicalManifestsAreRejected(string $document): void
    {
        $bundle = $this->prepare();
        self::assertSame(strlen($document), file_put_contents($bundle . '/manifest.json', $document));
        $this->assertRejected(fn () => $this->bundles->validate($bundle));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidManifests(): iterable
    {
        yield 'malformed' => ['{'];
        yield 'array' => ['[]'];
        yield 'unsupported version' => ['{"version":2,"sha256":{}}'];
        yield 'unknown field' => ['{"version":1,"sha256":{},"secret":"private-secret"}'];
        yield 'noninteger version' => ['{"version":"1","sha256":{}}'];
        yield 'missing hashes' => ['{"version":1,"sha256":{}}'];
        yield 'oversized' => [str_repeat(' ', 8193)];
        yield 'excess depth' => ['{"version":1,"sha256":{"private.key":[[[[[]]]]]}}'];
    }

    private function prepare(int $size = 2048): string
    {
        $bundle = $this->directory . '/bundle';
        $this->bundles->prepare($bundle, $size);
        return $bundle;
    }

    private function read(string $path): string
    {
        $value = file_get_contents($path);
        self::assertIsString($value);
        return $value;
    }

    private function replaceAndRehash(string $bundle, string $name, string $contents): void
    {
        self::assertSame(strlen($contents), file_put_contents($bundle . '/' . $name, $contents));
        $manifest = json_decode($this->read($bundle . '/manifest.json'), true, 4, JSON_THROW_ON_ERROR);
        $manifest['sha256'][$name] = hash('sha256', $contents);
        $json = json_encode($manifest, JSON_THROW_ON_ERROR);
        self::assertSame(strlen($json), file_put_contents($bundle . '/manifest.json', $json));
    }

    /** @param callable():void $operation */
    private function assertRejected(callable $operation, string $message = 'OAuth secret bundle is invalid.'): void
    {
        try {
            $operation();
            self::fail('Invalid OAuth secret bundle was admitted.');
        } catch (RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('private-secret', $error->getMessage());
        }
    }
}
