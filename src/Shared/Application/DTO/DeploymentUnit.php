<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

enum DeploymentUnit: string
{
    case Control = 'control';
    case Execution = 'execution';
}
