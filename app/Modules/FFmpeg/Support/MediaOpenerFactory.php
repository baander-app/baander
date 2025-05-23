<?php

namespace App\Modules\FFmpeg\Support;

use Illuminate\Support\Traits\ForwardsCalls;
use App\Modules\FFmpeg\Drivers\PHPFFMpeg;
use App\Modules\FFmpeg\Http\DynamicHLSPlaylist;
use App\Modules\FFmpeg\MediaOpener;

class MediaOpenerFactory
{
    use ForwardsCalls;

    private $defaultDisk;
    private $driver;
    private $driverResolver;

    public function __construct(string $defaultDisk, PHPFFMpeg $driver = null, callable $driverResolver = null)
    {
        $this->defaultDisk = $defaultDisk;
        $this->driver = $driver;
        $this->driverResolver = $driverResolver;
    }

    private function driver(): PHPFFMpeg
    {
        if ($this->driver) {
            return $this->driver;
        }

        $resolver = $this->driverResolver;

        return $this->driver = $resolver();
    }

    public function new(): MediaOpener
    {
        return new MediaOpener($this->defaultDisk, $this->driver());
    }

    public function dynamicHLSPlaylist(): DynamicHLSPlaylist
    {
        return new DynamicHLSPlaylist($this->defaultDisk);
    }

    /**
     * Handle dynamic method calls into the MediaOpener.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->forwardCallTo($this->new(), $method, $parameters);
    }
}
