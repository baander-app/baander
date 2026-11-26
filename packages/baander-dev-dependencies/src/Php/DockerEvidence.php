<?php

declare(strict_types=1);

namespace Baander\DevDependencies\Php;

use Baander\Containers\Docker\Client;
use Baander\Process\Execution\CommandFailure;
use Baander\Process\Execution\ProcessResult;

final readonly class DockerEvidence
{
    /** Manifest reads of one tag while its registry answers 429, with a pause before each next read that doubles from the first. */
    private const int RATE_LIMITED_READS = 4;
    private const float FIRST_PAUSE_SECONDS = 5.0;

    /** @var \Closure(float): void */
    private \Closure $pause;

    /** @param (\Closure(float): void)|null $pause waits the given seconds between rate-limited reads */
    public function __construct(private Client $client, private float $timeout, ?\Closure $pause = null)
    {
        $this->pause = $pause ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /** @return array<string,array{php:string,composer:string}>
     */
    public function composerImage(string $reference): array
    {
        if (!preg_match('/^composer:(\d+\.\d+\.\d+)@sha256:[a-f0-9]{64}$/D', $reference, $declared)) {
            throw new \RuntimeException('Composer image must have an exact version and source digest.');
        }
        $result = $this->client->run(['buildx', 'imagetools', 'inspect', $reference, '--format', '{{json .Image}}'], timeout: $this->timeout);
        if ($result->stderr !== '') {
            throw new \RuntimeException('Composer registry metadata emitted a diagnostic.');
        }
        $images = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($images) || $images === []) {
            throw new \RuntimeException('Composer registry image metadata is empty.');
        }
        $versions = [];
        foreach ($images as $platform => $image) {
            if (!is_string($platform) || !preg_match('/^linux\/[a-z0-9_]+(?:\/[a-z0-9_]+)?$/D', $platform) || !is_array($image) || !is_array($image['config']['Env'] ?? null)) {
                throw new \RuntimeException('Unsupported Composer multi-platform image metadata.');
            }
            $php = [];
            $composer = [];
            foreach ($image['config']['Env'] as $variable) {
                if (!is_string($variable)) {
                    throw new \RuntimeException('Malformed image environment metadata.');
                }
                if (preg_match('/^PHP_VERSION=(\d+\.\d+\.\d+)$/D', $variable, $match)) {
                    $php[] = $match[1];
                }
                if (preg_match('/^COMPOSER_VERSION=(\d+\.\d+\.\d+)$/D', $variable, $match)) {
                    $composer[] = $match[1];
                }
            }
            if (count($php) !== 1 || count($composer) !== 1 || $composer[0] !== $declared[1]) {
                throw new \RuntimeException('Composer image runtime version metadata is missing, duplicate or inconsistent.');
            }
            $versions[$platform] = ['php' => $php[0], 'composer' => $composer[0]];
        }
        ksort($versions);
        return $versions;
    }

    /** Whether an image name carries a tag: a colon after its last slash, so a registry port does not count. */
    public static function isTagged(string $name): bool
    {
        return str_contains(substr($name, (int) strrpos($name, '/')), ':');
    }

    /** The digest a tag resolves to in its registry now: the index digest of a multi-platform image. */
    public function digest(string $reference): string
    {
        if (preg_match('~^[^\s@]+:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$~D', $reference) !== 1 || !self::isTagged($reference)) {
            throw new \RuntimeException('A registry digest is read for a tagged reference without a digest.');
        }
        $result = $this->manifest($reference);
        if ($result->stderr !== '') {
            throw new \RuntimeException('Registry manifest metadata emitted a diagnostic: ' . trim($result->stderr));
        }
        $manifest = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        $digest = is_array($manifest) ? ($manifest['digest'] ?? null) : null;
        if (!is_string($digest) || preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \RuntimeException('Registry manifest metadata has no digest.');
        }
        return $digest;
    }

    /**
     * A 429 is the registry's pull-rate limit, which lifts with time, so that answer alone is read again; an
     * authentication refusal or an unknown manifest stays the same on every read and ends the read at once.
     */
    private function manifest(string $reference): ProcessResult
    {
        for ($read = 1, $pause = self::FIRST_PAUSE_SECONDS; ; ++$read, $pause *= 2) {
            try {
                return $this->client->run(['buildx', 'imagetools', 'inspect', $reference, '--format', '{{json .Manifest}}'], timeout: $this->timeout);
            } catch (CommandFailure $failure) {
                if (!self::rateLimited($failure)) {
                    throw $failure;
                }
                if ($read === self::RATE_LIMITED_READS) {
                    throw new \RuntimeException('The registry still answered 429 after ' . self::RATE_LIMITED_READS . ' reads: ' . $failure->getMessage(), previous: $failure);
                }
            }
            ($this->pause)($pause);
        }
    }

    private static function rateLimited(CommandFailure $failure): bool
    {
        return preg_match('/\b429 Too Many Requests\b|\btoomanyrequests\b/i', $failure->result->stderr ?? '') === 1;
    }
}
