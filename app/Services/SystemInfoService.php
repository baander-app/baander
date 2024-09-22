<?php

namespace App\Services;

use App\Packages\PhpInfoParser\Info;

class SystemInfoService
{
    public function getVmStatus()
    {
        return swoole_get_vm_status();
    }

    public function getPhpInfo(): array
    {
        return Info::getModules();
    }
}