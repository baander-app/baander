<?php

namespace App\Modules\Development\Console;

use Attribute;
use Illuminate\Console\Command;
use ReflectionMethod;

#[Attribute(Attribute::TARGET_METHOD)]
class RequiresLocalEnvironment
{
    public function check(Command $command): bool
    {
        if (config('app.env') !== 'local') {
            $command->error('This command is only available in local environment.');
            return false;
        }
        return true;
    }
}
