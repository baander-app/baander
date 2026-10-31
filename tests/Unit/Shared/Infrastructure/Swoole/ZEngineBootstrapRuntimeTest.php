<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunInSeparateProcess};
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ContainerSourceCodeExtractor;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;

final class ZEngineBootstrapRuntimeTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testZtsGlobalsAndContainerReflectionWorkOnTheRequiredRuntime(): void
    {
        self::assertGreaterThanOrEqual(80512, PHP_VERSION_ID);
        self::assertTrue(PHP_ZTS, 'This proof requires the application ZTS runtime.');
        self::assertTrue(extension_loaded('ffi'), 'FFI is required; this proof must not skip.');
        self::assertSame('6.2.4', phpversion('swoole'));

        Core::init();
        self::assertNotNull(Core::$executor);
        self::assertNotNull(Core::$compiler);
        self::assertNotNull(Core::$modules);

        $reflection = new ReflectionClass(ZEngineBootstrapFixture::class);
        $method = $reflection->getMethod('service');
        $source = file_get_contents(__FILE__);
        self::assertIsString($source);
        $internals = new ContainerSourceCodeExtractor($source)->getContainerInternalsForMethod($method);
        self::assertSame('services', $internals['type']);
        self::assertSame('probe', $internals['key']);

        try {
            $reflection->setFinal(false);
            $method->setFinal(false);
            self::assertFalse(new \ReflectionClass(ZEngineBootstrapFixture::class)->isFinal());
            self::assertFalse(new \ReflectionMethod(ZEngineBootstrapFixture::class, 'service')->isFinal());
            $fixture = new ZEngineBootstrapFixture();
            $service = $fixture->service();
            self::assertInstanceOf(\stdClass::class, $service);
            self::assertSame($service, $fixture->storedService());
        } finally {
            $method->setFinal(true);
            $reflection->setFinal(true);
        }
    }
}

final class ZEngineBootstrapFixture
{
    /** @var array<string, object> */
    private array $services = [];

    public function storedService(): ?object
    {
        return $this->services['probe'] ?? null;
    }

    final public function service(): object
    {
        return $this->services['probe'] = new \stdClass();
    }
}
