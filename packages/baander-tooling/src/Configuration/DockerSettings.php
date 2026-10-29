<?php

declare(strict_types=1);

namespace Baander\Tooling\Configuration;

final readonly class DockerSettings
{
    public function __construct(public string $executable, public ?string $host = null, public ?string $context = null)
    {
        foreach ([$executable, $host, $context] as $value) {
            if ($value !== null && (trim($value) === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1)) {
                throw new \InvalidArgumentException('Docker settings require nonempty strings without control characters.');
            }
        }
        if ($host !== null && $context !== null) {
            throw new \InvalidArgumentException('Docker host and context are mutually exclusive.');
        }
        if ($host !== null && preg_match('~^(?:unix|tcp|ssh|npipe)://[^\s]+$~D', $host) !== 1) {
            throw new \InvalidArgumentException('Unsupported Docker host.');
        }
        if ($host !== null && preg_match('~^[a-z]+://[^/]*@~i', $host) === 1 && !str_starts_with($host, 'ssh://')) {
            throw new \InvalidArgumentException('Docker host must not contain credentials.');
        }
        if ($host !== null && str_starts_with($host, 'ssh://') && preg_match('~^ssh://[^/@]*:[^/@]*@~', $host) === 1) {
            throw new \InvalidArgumentException('Docker host must not contain passwords.');
        }
    }

    /** @return array{executable:string,host:?string,context:?string} */
    public function toArray(): array
    {
        return ['executable' => $this->executable, 'host' => $this->host, 'context' => $this->context];
    }
}
