<?php

namespace App\Modules\Microservices;

use ProxyManager\Factory\RemoteObjectFactory;

class ReSpoolProxy
{
    public function __construct(
        public readonly RemoteObjectFactory $remoteObjectFactory,
    )
    {
    }
}