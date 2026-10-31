<?php

declare(strict_types=1);

namespace Baander\Tooling\Dependencies;

use Baander\Tooling\Docker\Client;

final readonly class DockerEvidence
{
    public function __construct(private Client $client, private float $timeout) {}

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
}
