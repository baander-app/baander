<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Swoole\HookFlags;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// The hook flags are Swoole constants, which a container built without the extension
// cannot read; such a container runs no server, so it takes none.
return static function (ContainerConfigurator $container): void {
    if (extension_loaded('swoole')) {
        $container->extension('swoole', ['http_server' => ['settings' => ['hook_flags' => HookFlags::logQuery()]]]);
    }
};
