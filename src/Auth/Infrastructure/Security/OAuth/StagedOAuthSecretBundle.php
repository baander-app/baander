<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Port\OAuthSecretBundleInterface;
use Defuse\Crypto\Key;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/** Separate filesystem preparation from database invalidation and operator cutover. */
final readonly class StagedOAuthSecretBundle implements OAuthSecretBundleInterface
{
    private const array FILES = ['private.key', 'public.key', 'encryption.key', 'oauth.env'];
    private const int MAX_BYTES = 8192;

    public function prepare(string $directory, int $keySize): void
    {
        try {
            $this->path($directory);
            if (!in_array($keySize, [2048, 4096], true)) {
                throw new RuntimeException();
            }
            $parent = dirname($directory);
            clearstatcache(true, $parent);
            $parentStat = @lstat($parent);
            if ($parentStat === false || @realpath($parent) !== $parent
                || ($parentStat['mode'] & 0170000) !== 0040000
                || ($parentStat['mode'] & 0022) !== 0 || $parentStat['uid'] !== posix_geteuid()
                || !@mkdir($directory, 0700) || !@chmod($directory, 0700)
            ) {
                throw new RuntimeException();
            }
            $this->directory($directory);
            $rsa = @openssl_pkey_new(['private_key_bits' => $keySize, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($rsa === false || !@openssl_pkey_export($rsa, $private)) {
                throw new RuntimeException();
            }
            $details = @openssl_pkey_get_details($rsa);
            if ($details === false) {
                throw new RuntimeException();
            }
            $encryption = Key::createNewRandomKey()->saveToAsciiSafeString();
            $files = [
                'private.key' => $private, 'public.key' => $details['key'],
                'encryption.key' => $encryption . "\n", 'oauth.env' => $this->environment($directory, $encryption),
            ];
            $this->keys($files['private.key'], $files['public.key'], $files['encryption.key']);
            $hashes = [];
            foreach ($files as $name => $contents) {
                $this->write($directory, $name, $contents);
                $hashes[$name] = hash('sha256', $contents);
            }
            // A crash before this exclusive final write leaves an inadmissible bundle.
            $this->write($directory, 'manifest.json', json_encode(['version' => 1, 'sha256' => $hashes], JSON_THROW_ON_ERROR) . "\n");
            $this->validate($directory);
        } catch (Throwable) {
            // Parser/native errors and stack traces may contain private input.
            throw new RuntimeException('OAuth secret bundle could not be prepared.');
        }
    }

    public function validate(string $directory): void
    {
        try {
            $this->directory($directory);
            $manifest = json_decode($this->read($directory, 'manifest.json'), false, 4, JSON_THROW_ON_ERROR);
            if (!$manifest instanceof stdClass || count(get_object_vars($manifest)) !== 2
                || array_diff(array_keys(get_object_vars($manifest)), ['version', 'sha256']) !== []
                || $manifest->version !== 1 || !$manifest->sha256 instanceof stdClass
            ) {
                throw new RuntimeException();
            }
            $hashes = get_object_vars($manifest->sha256);
            if (count($hashes) !== count(self::FILES) || array_diff(array_keys($hashes), self::FILES) !== []) {
                throw new RuntimeException();
            }
            $files = [];
            foreach (self::FILES as $name) {
                if (!isset($hashes[$name]) || !is_string($hashes[$name])
                    || preg_match('/\A[a-f0-9]{64}\z/D', $hashes[$name]) !== 1
                ) {
                    throw new RuntimeException();
                }
                $contents = $this->read($directory, $name);
                if (!hash_equals($hashes[$name], hash('sha256', $contents))) {
                    throw new RuntimeException();
                }
                $files[$name] = $contents;
            }
            $encryption = $this->keys($files['private.key'], $files['public.key'], $files['encryption.key']);
            if ($files['oauth.env'] !== $this->environment($directory, $encryption)) {
                throw new RuntimeException();
            }
            $this->directory($directory);
            $entries = @scandir($directory);
            $expected = ['.', '..', 'manifest.json', ...self::FILES];
            sort($expected);
            if ($entries !== $expected) {
                throw new RuntimeException();
            }
        } catch (Throwable) {
            throw new RuntimeException('OAuth secret bundle is invalid.');
        }
    }

    private function path(string $directory): void
    {
        if (!function_exists('posix_geteuid') || strlen($directory) > 1024
            || preg_match('~\A/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\z~D', $directory) !== 1
            || preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~', $directory) !== 0
        ) {
            throw new RuntimeException();
        }
    }

    private function directory(string $directory): void
    {
        $this->path($directory);
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        $parent = dirname($directory);
        clearstatcache(true, $parent);
        $parentStat = @lstat($parent);
        if ($stat === false || @realpath($directory) !== $directory || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 07777) !== 0700 || $stat['uid'] !== posix_geteuid()
            || $parentStat === false || @realpath($parent) !== $parent
            || ($parentStat['mode'] & 0170000) !== 0040000 || ($parentStat['mode'] & 0022) !== 0
            || $parentStat['uid'] !== posix_geteuid()
        ) {
            throw new RuntimeException();
        }
    }

    private function write(string $directory, string $name, #[SensitiveParameter] string $contents): void
    {
        $this->directory($directory);
        if (strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException();
        }
        $file = @fopen($directory . '/' . $name, 'x+b');
        if ($file === false) {
            throw new RuntimeException();
        }
        try {
            if (!@chmod($directory . '/' . $name, 0600)
                || @fwrite($file, $contents) !== strlen($contents) || !@fflush($file) || !@fsync($file)
            ) {
                throw new RuntimeException();
            }
            $stat = fstat($file);
            if ($stat === false || !$this->privateFile($stat) || $stat['size'] !== strlen($contents)) {
                throw new RuntimeException();
            }
        } finally {
            fclose($file);
        }
        if ($this->read($directory, $name) !== $contents) {
            throw new RuntimeException();
        }
    }

    private function read(string $directory, string $name): string
    {
        $this->directory($directory);
        $path = $directory . '/' . $name;
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false || @realpath($path) !== $path || !$this->privateFile($before)) {
            throw new RuntimeException();
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException();
        }
        try {
            $opened = fstat($file);
            if ($opened === false || !$this->privateFile($opened) || !$this->sameFile($before, $opened)) {
                throw new RuntimeException();
            }
            $contents = @stream_get_contents($file, self::MAX_BYTES + 1);
            $after = fstat($file);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if ($contents === false || strlen($contents) > self::MAX_BYTES || $after === false || $current === false
                || !$this->privateFile($after) || !$this->privateFile($current)
                || !$this->sameFile($opened, $after) || !$this->sameFile($after, $current)
                || strlen($contents) !== $after['size'] || $opened['size'] !== $after['size']
                || @realpath($path) !== $path
            ) {
                throw new RuntimeException();
            }
            return $contents;
        } finally {
            fclose($file);
        }
    }

    /** @param array<int|string,int> $stat */
    private function privateFile(array $stat): bool
    {
        return ($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 07777) === 0600
            && $stat['uid'] === posix_geteuid() && $stat['size'] <= self::MAX_BYTES;
    }

    /**
     * @param array<int|string,int> $left
     * @param array<int|string,int> $right
     */
    private function sameFile(array $left, array $right): bool
    {
        return $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }

    private function keys(#[SensitiveParameter] string $private, string $public, #[SensitiveParameter] string $encryption): string
    {
        $privateKey = @openssl_pkey_get_private($private);
        $publicKey = @openssl_pkey_get_public($public);
        if ($privateKey === false || $publicKey === false) {
            throw new RuntimeException();
        }
        $details = @openssl_pkey_get_details($privateKey);
        $challenge = random_bytes(32);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || !in_array($details['bits'], [2048, 4096], true)
            || !@openssl_sign($challenge, $signature, $privateKey, OPENSSL_ALGO_SHA256)
            || @openssl_verify($challenge, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1
            || !str_ends_with($encryption, "\n")
        ) {
            throw new RuntimeException();
        }
        $value = substr($encryption, 0, -1);
        if (Key::loadFromAsciiSafeString($value)->saveToAsciiSafeString() !== $value) {
            throw new RuntimeException();
        }
        return $value;
    }

    private function environment(string $directory, #[SensitiveParameter] string $encryption): string
    {
        return 'OAUTH_PRIVATE_KEY_PATH=' . $directory . "/private.key\n"
            . 'OAUTH_PUBLIC_KEY_PATH=' . $directory . "/public.key\n"
            . 'OAUTH_ENCRYPTION_KEY=' . $encryption . "\n";
    }
}
