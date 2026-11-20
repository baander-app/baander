<?php

declare(strict_types=1);

namespace Baander\Deployment\Profiles;

use Baander\Core\Configuration\ConfigPath;

enum DeploymentField: string
{
    case Capability = 'capability';
    case WebWorkers = 'webWorkers';
    case DoctrinePoolSlots = 'doctrinePoolSlots';
    case OrdinaryConsumerLimit = 'ordinaryConsumerLimit';
    case SharedCopySlots = 'sharedCopySlots';
    case VideoEncoderSlots = 'videoEncoderSlots';
    case AudioEncoderSlots = 'audioEncoderSlots';
    case Hardware = 'hardware';

    public function path(?ConfigPath $parent = null): ConfigPath
    {
        return ($parent ?? ConfigPath::root())->key($this);
    }
}
