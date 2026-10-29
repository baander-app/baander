<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Docker\{OperatorTransport, Target};

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
