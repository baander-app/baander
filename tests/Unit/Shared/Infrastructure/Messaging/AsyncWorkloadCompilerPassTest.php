<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messaging;

use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Infrastructure\Messaging\AsyncWorkloadCompilerPass;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\DecoratorServicePass;
use Symfony\Component\DependencyInjection\Compiler\ResolveReferencesToAliasesPass;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\TraceableMessageBus;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Serialization\SigningSerializer;

final class AsyncWorkloadCompilerPassTest extends TestCase
{
    public function testValidEffectiveMetadataCompiles(): void
    {
        (new AsyncWorkloadCompilerPass())->process($this->container());
        $this->addToAssertionCount(1);
    }

    public function testExplicitOnlyWorkloadRequiresCoverageWithoutADefaultRoute(): void
    {
        $container = $this->container();
        $workloads = $container->getParameter('app.messenger.workloads');
        $workloads[CompilerExplicitWorkload::class] = ['transport' => 'async', 'resource_class' => 'io', 'default' => false];
        $container->setParameter('app.messenger.workloads', $workloads);
        $handlers = $container->getDefinition('messenger.bus.default.messenger.handlers_locator')->getArgument(0);
        $handlers[CompilerExplicitWorkload::class] = new IteratorArgument([new Reference('descriptor')]);
        $container->getDefinition('messenger.bus.default.messenger.handlers_locator')->setArgument(0, $handlers);
        (new AsyncWorkloadCompilerPass())->process($container);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidMetadata')]
    public function testRejectsInvalidEffectiveMetadata(string $mutation, string $reason, bool $explicitOnly = false): void
    {
        $container = $this->container();
        if ($explicitOnly) {
            $workloads = $container->getParameter('app.messenger.workloads');
            $workloads[CompilerWorkload::class]['default'] = false;
            $container->setParameter('app.messenger.workloads', $workloads);
            $container->getDefinition('messenger.senders_locator')->setArgument(0, []);
        }
        switch ($mutation) {
            case 'missing-route':
                $container->getDefinition('messenger.senders_locator')->setArgument(0, []);
                break;
            case 'extra-route':
                $container->getDefinition('messenger.senders_locator')->setArgument(0, ['*' => ['async']]);
                break;
            case 'fanout':
                $container->getDefinition('messenger.senders_locator')->setArgument(0, [CompilerWorkload::class => ['async', 'scheduler']]);
                break;
            case 'handler-missing':
                $container->getDefinition('messenger.bus.default.messenger.handlers_locator')->setArgument(0, []);
                break;
            case 'handler-transport':
                $container->getDefinition('descriptor')->setArgument(1, ['from_transport' => 'scheduler']);
                break;
            case 'handler-other-bus':
                $container->register('other.bus', MessageBus::class);
                $container->setAlias('messenger.default_bus', 'other.bus');
                break;
            case 'codec-missing':
                $container->removeDefinition('codec');
                break;
            case 'codec-duplicate':
                $container->setDefinition('duplicate.codec', clone $container->getDefinition('codec'));
                break;
            case 'codec-duplicate-class':
                $container->register('duplicate.codec', DuplicateClassCodec::class)->addTag('baander.message_payload_codec');
                break;
            case 'codec-duplicate-type':
                $container->register('duplicate.codec', DuplicateTypeCodec::class)->addTag('baander.message_payload_codec');
                break;
            case 'serializer':
                $container->getDefinition('messenger.transport.async')->setArgument(2, new Reference('other.serializer'));
                $container->register('other.serializer', \stdClass::class);
                break;
            case 'retry':
                $container->getDefinition('retry.async')->setArgument(0, 4);
                break;
            case 'retry-missing':
                $container->getDefinition('messenger.retry_strategy_locator')->setArgument(0, []);
                break;
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($reason);
        (new AsyncWorkloadCompilerPass())->process($container);
    }

    /** @return iterable<int, array{0: string, 1: string, 2?: bool}> */
    public static function invalidMetadata(): iterable
    {
        yield ['missing-route', 'routing'];
        yield ['extra-route', 'routing'];
        yield ['fanout', 'routing'];
        yield ['handler-missing', 'handler'];
        yield ['handler-transport', 'handler'];
        yield ['handler-other-bus', 'handler'];
        yield ['codec-missing', 'codec'];
        yield ['codec-duplicate', 'codec'];
        yield ['codec-duplicate-class', 'codec'];
        yield ['codec-duplicate-type', 'codec'];
        yield ['serializer', 'serializer'];
        yield ['retry', 'retry'];
        yield ['retry-missing', 'retry'];
        yield ['codec-missing', 'codec', true];
        yield ['handler-missing', 'handler', true];
        yield ['handler-transport', 'handler', true];
    }

    public function testDecoratedDefaultBusUsesItsOriginalCompiledHandlers(): void
    {
        $container = $this->container();
        $container->register('messenger.bus.default', MessageBus::class)->addTag('messenger.bus');
        $container->register('debug.traced.messenger.bus.default', TraceableMessageBus::class)
            ->setArguments([new Reference('debug.traced.messenger.bus.default.inner')])
            ->setDecoratedService('messenger.bus.default');
        (new DecoratorServicePass())->process($container);
        (new ResolveReferencesToAliasesPass())->process($container);
        (new AsyncWorkloadCompilerPass())->process($container);
        $this->addToAssertionCount(1);
    }

    public function testFrameworkSigningDecoratorPreservesTheJsonSerializer(): void
    {
        $container = $this->container();
        $container->register('signing.serializer', SigningSerializer::class)
            ->setArguments([new Reference('signing.serializer.inner'), 'test-signing-key', []])
            ->setDecoratedService(JsonTransportSerializer::class);
        (new DecoratorServicePass())->process($container);
        (new ResolveReferencesToAliasesPass())->process($container);
        (new AsyncWorkloadCompilerPass())->process($container);
        $this->addToAssertionCount(1);
    }

    public function testFrameworkSigningDecoratorCannotHideAWrongInnerSerializer(): void
    {
        $container = $this->container();
        $container->register('other.serializer', \stdClass::class);
        $container->register('signing.serializer', SigningSerializer::class)
            ->setArguments([new Reference('other.serializer'), 'test-signing-key', []]);
        $container->getDefinition('messenger.transport.async')->setArgument(2, new Reference('signing.serializer'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('serializer');
        (new AsyncWorkloadCompilerPass())->process($container);
    }

    public function testDecoratedSenderLocatorIsValidated(): void
    {
        $container = $this->container();
        $container->setDefinition('App\\Shared\\Infrastructure\\Messenger\\ValidatedSendersLocator.inner', $container->getDefinition('messenger.senders_locator'));
        $container->register('messenger.senders_locator', \stdClass::class);
        (new AsyncWorkloadCompilerPass())->process($container);
        $this->addToAssertionCount(1);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.messenger.workloads', [CompilerWorkload::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => true]]);
        $policy = ['max_retries' => 3, 'delay' => 1000, 'multiplier' => 2, 'max_delay' => 60000];
        $container->setParameter('app.messenger.transport_policies', ['async' => $policy, 'scheduler' => $policy]);
        $container->register('messenger.senders_locator')->setArgument(0, [CompilerWorkload::class => ['async']]);
        $container->register('messenger.bus.default', MessageBus::class);
        $container->setAlias('messenger.default_bus', 'messenger.bus.default');
        $container->register('messenger.bus.default.messenger.handlers_locator')->setArgument(0, [CompilerWorkload::class => new IteratorArgument([new Reference('descriptor')])]);
        $container->setDefinition('descriptor', new Definition(HandlerDescriptor::class, [new Reference('handler'), []]));
        $container->register('codec', CompilerCodec::class)->addTag('baander.message_payload_codec');
        $container->register(JsonTransportSerializer::class, JsonTransportSerializer::class);
        foreach (['async', 'scheduler'] as $transport) {
            $container->register('messenger.transport.' . $transport)->setArgument(2, new Reference(JsonTransportSerializer::class));
            $container->register('retry.' . $transport, MultiplierRetryStrategy::class)->setArguments(array_values($policy));
        }
        $container->register('messenger.retry_strategy_locator')->setArgument(0, [
            'async' => new ServiceClosureArgument(new Reference('retry.async')),
            'scheduler' => new ServiceClosureArgument(new Reference('retry.scheduler')),
        ]);

        return $container;
    }
}

final class CompilerWorkload {}
final class CompilerExplicitWorkload {}

class CompilerCodec implements MessagePayloadCodecInterface
{
    public static function types(): array
    {
        return ['compiler.workload' => CompilerWorkload::class, 'compiler.explicit' => CompilerExplicitWorkload::class];
    }

    public function fields(string $type): array { return []; }
    public function encode(object $message): array { return []; }
    public function decode(string $type, array $p): object { return new CompilerWorkload(); }
}

final class DuplicateClassCodec extends CompilerCodec
{
    public static function types(): array
    {
        return ['compiler.duplicate' => CompilerWorkload::class];
    }
}

final class DuplicateTypeCodec extends CompilerCodec
{
    public static function types(): array
    {
        return ['compiler.workload' => \stdClass::class];
    }
}
