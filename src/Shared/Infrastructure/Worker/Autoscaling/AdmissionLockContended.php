<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** The admission lock stayed held past its deadline before the operation read any state. */
#[Exclude]
final class AdmissionLockContended extends \RuntimeException
{
}
