<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** Loads the actual configuration without booting runtime services. */
final class MessengerConfiguration
{
    public static function container(string $environment = 'prod'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FrameworkExtension());
        $loader = new PhpFileLoader($container, new FileLocator(dirname(__DIR__, 3) . '/config/packages'), $environment);
        $loader->load('messenger.php');
        return $container;
    }

    /** @return array<string, mixed> */
    public static function messenger(string $environment = 'prod'): array
    {
        return self::container($environment)->getExtensionConfig('framework')[0]['messenger'];
    }
}
