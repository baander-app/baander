<?php

namespace App\Services;

class SystemMetricsCollectorService
{
    public function memoryUsage()
    {
        return memory_get_usage();
    }

    public function systemLoadAverage()
    {
        return sys_getloadavg();
    }

    public function swooleVm()
    {
        return swoole_get_vm_status();
    }
}