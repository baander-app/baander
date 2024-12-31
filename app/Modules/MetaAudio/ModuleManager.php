<?php

namespace App\Modules\MetaAudio;

use App\Modules\MetaAudio\Modules\ModuleInterface;

trait ModuleManager
{
    /**
     * @var ModuleInterface[] $modules The modules used to read/write tags.
     */
    private array $modules = [];


    /**
     * Add a module to the stack.
     *
     * @param ModuleInterface $module
     * @return $this
     */
    public function addModule(ModuleInterface $module)
    {
        $this->modules[] = $module;

        return $this;
    }


    /**
     * Add the default set of modules the library ships with.
     *
     * @return $this
     */
    public function addDefaultModules()
    {
        //$this->addModule(new Modules\Ape);
        $this->addModule(new Modules\Id3v2);
        //$this->addModule(new Modules\Id3v1);

        return $this;
    }


    /**
     * Remove all previously defined modules.
     *
     * @return $this
     */
    public function clearModules()
    {
        $this->modules = [];

        return $this;
    }


    /**
     * Get all active modules.
     *
     * @return ModuleInterface[]
     */
    protected function getModules()
    {
        return $this->modules;
    }
}
