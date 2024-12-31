<?php

namespace App\Services;

use App\Modules\PhpInfoParser\Info;

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