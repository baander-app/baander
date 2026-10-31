<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\ClassModificationProcessor;
use SwooleBundle\SwooleBundle\Reflection\ClassModifier;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use ZEngine\Core;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ClassModifierLifetimeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-class-modifier-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testInitializationPreservesAnAlreadyInitializedCore(): void
    {
        self::assertTrue(extension_loaded('ffi'), 'FFI is required; this proof must not skip.');
        Core::init();
        $executor = Core::$executor;

        ClassModifier::initialize();
        ClassModifier::initialize();

        self::assertSame($executor, Core::$executor);
    }

    public function testEachCompilationRecordsPreviouslyStrippedFinalClasses(): void
    {
        self::assertTrue(extension_loaded('ffi'), 'FFI is required; this proof must not skip.');
        $parameter = 'swoole_bundle.coroutines_support.final_classes';
        $first = new ContainerBuilder();
        $first->setParameter('kernel.cache_dir', $this->directory);
        (new ClassModificationProcessor($first))->processFinalClass(ClassModifierLifetimeFinalFixture::class);
        $second = new ContainerBuilder();
        $second->setParameter('kernel.cache_dir', $this->directory);
        (new ClassModificationProcessor($second))->processFinalClass(ClassModifierLifetimeFinalFixture::class);

        self::assertFalse((new \ReflectionClass(ClassModifierLifetimeFinalFixture::class))->isFinal());
        self::assertFalse((new \ReflectionMethod(ClassModifierLifetimeFinalFixture::class, 'value'))->isFinal());
        self::assertSame([ClassModifierLifetimeFinalFixture::class], $first->getParameter($parameter));
        self::assertSame($first->getParameter($parameter), $second->getParameter($parameter));

        $second->compile();
        $source = (new PhpDumper($second))->dump(['class' => 'FinalClassReplayContainer']);
        self::assertIsString($source);
        $containerFile = $this->directory . '/container.php';
        (new Filesystem())->dumpFile($containerFile, $source);
        (new Filesystem())->remove($this->directory . '/swoole_bundle/modification');
        $script = 'require $argv[1]; require $argv[2]; require $argv[3]; '
            . '$container = new FinalClassReplayContainer(); '
            . '\\' . ClassModifier::class . '::removeFinalFlagsFromClasses($container->getParameter($argv[4])); '
            . 'eval($argv[5]); echo (new ReplayFinalFixture())->value();';
        $subclass = 'class ReplayFinalFixture extends \\' . ClassModifierLifetimeFinalFixture::class
            . ' { public function value(): string { return "replayed"; } }';
        $process = new Process([
            PHP_BINARY, '-d', 'ffi.enable=true', '-r', $script,
            dirname(__DIR__, 5) . '/vendor/autoload.php', __FILE__, $containerFile, $parameter, $subclass,
        ]);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        self::assertSame('replayed', $process->getOutput());
    }
    public function testNativeParentsAndClassesWithoutFinalMembersAreExcluded(): void
    {
        self::assertTrue(extension_loaded('ffi'), 'FFI is required; this proof must not skip.');
        self::assertFalse(ClassModifier::removeFinalFlagsFromClass(ClassModifierLifetimeNativeFixture::class));
        self::assertTrue((new \ReflectionClass(ClassModifierLifetimeNativeFixture::class))->isFinal());
        self::assertFalse(ClassModifier::removeFinalFlagsFromClass(ClassModifierLifetimePlainFixture::class));
    }

}

final class ClassModifierLifetimeFinalFixture
{
    final public function value(): string
    {
        return 'ready';
    }
}

/** @extends \ArrayObject<array-key, mixed> */
final class ClassModifierLifetimeNativeFixture extends \ArrayObject {}

class ClassModifierLifetimePlainFixture
{
    public function value(): string
    {
        return 'plain';
    }
}
