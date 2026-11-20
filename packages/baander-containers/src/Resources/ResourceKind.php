<?php

declare(strict_types=1);

namespace Baander\Containers\Resources;

enum ResourceKind: string
{
    case Container = 'container';
    case Volume = 'volume';
    case Network = 'network';
}
