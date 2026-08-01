<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute]
final class CronExpression extends Constraint
{
    public string $message = 'Invalid cron expression: {{ error }}';
}
