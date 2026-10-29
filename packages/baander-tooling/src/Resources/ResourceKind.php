<?php

declare(strict_types=1);

namespace Baander\Tooling\Resources;

enum ResourceKind: string
{
    case Container = 'container';
    case Volume = 'volume';
    case Network = 'network';
}
