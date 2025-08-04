<?php

namespace App\Modules\FFmpeg\Support;

/**
 * Class Container
 * @package support
 * @method static mixed get($name)
 * @method static mixed make($name, array $parameters)
 * @method static bool has($name)
 */
class Container
{
    /**
     * Instance
     * @return array|mixed|void|null
     */
    public static function instance()
    {
        return \support\Container::instance('brooke1220.webman-ffmpeg');
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments)
    {
        return static::instance()->{$name}(... $arguments);
    }
}
