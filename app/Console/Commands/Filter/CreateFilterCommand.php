<?php

namespace App\Console\Commands\Filter;

use Illuminate\Console\Command;
use Illuminate\Console\GeneratorCommand;

class CreateFilterCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:filter {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new filter';

    protected function getStub()
    {
        return app_path('../stubs/filter.stub');
    }

    protected function getDefaultNamespace($rootNamespace)
    {
        return parent::getDefaultNamespace($rootNamespace) . '\Filters';
    }
}
