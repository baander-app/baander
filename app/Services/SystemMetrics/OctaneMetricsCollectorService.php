<?php

namespace App\Services\SystemMetrics;

class OctaneMetricsCollectorService
{
    public function collect()
    {
        $vmStatus = swoole_get_vm_status();

        return [
            'swoole_vm_object_num'   => $vmStatus['object_num'],
            'swoole_vm_resource_num' => $vmStatus['resource_num'],
            'memory_usage_bytes'     => memory_get_usage(),
        ];
    }
}