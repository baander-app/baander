<?php

declare(strict_types=1);

namespace Baander\DevQualification\Fixtures\Worker;

use Baander\Containers\Docker\OperatorTransport;
use Baander\Containers\Docker\Target;

final readonly class OperatorTopology
{
    public ?OperatorTransport $operatorTransport;

    /** @param array<string,string> $environment */
    public function __construct(public Target $target, bool $container, array $environment)
    {
        $socket = $environment['BAANDER_TEST_DOCKER_SOCKET_PATH'] ?? '';
        if ($container && $socket === '') {
            throw new \InvalidArgumentException('Container operator requires an explicit BAANDER_TEST_DOCKER_SOCKET_PATH mapping.');
        }
        if (!$container && !str_starts_with($target->endpoint, 'unix://')) {
            throw new \InvalidArgumentException('Host operator requires a Unix socket Docker target.');
        }
        $this->operatorTransport = $container ? new OperatorTransport($socket, '/run/baander-tooling/docker.sock') : null;
    }

    public function operatorTarget(): Target
    {
        return $this->operatorTransport?->target($this->target, '/usr/local/bin/docker') ?? $this->target;
    }
}
