<?php

namespace App\Modules\Development\Console;

use Illuminate\Console\Command;
use ReflectionMethod;

abstract class DevelopmentCommand extends Command
{
    final public function handle(): int
    {
        // Automatically run prechecks based on attributes
        if (!$this->runAttributeBasedPrechecks()) {
            return Command::FAILURE;
        }

        // Call the child's handle method
        return $this->executeCommand();
    }

    private function runAttributeBasedPrechecks(): bool
    {
        $reflection = new ReflectionMethod($this, 'executeCommand');
        $attributes = $reflection->getAttributes();

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();

            // Check if the attribute has a check method
            if (method_exists($instance, 'check')) {
                if (!$instance->check($this)) {
                    return false;
                }
            }
        }

        return true;
    }

    abstract protected function executeCommand(): int;
}
