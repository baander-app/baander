<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\ClassModificationProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\EventDispatcher\EventDispatcherProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpClient\HttpClientProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Security\SecurityProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Twig\TwigProcessor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Security\TraceableAuthenticatorResetter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class FrameworkIsolationOwnershipTest extends TestCase
{
    public function testDebugFirewallAliasGetsAnIndependentInnerDispatcher(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('security.firewalls', ['api', 'public']);
        foreach (['event_dispatcher', 'security.event_dispatcher.api'] as $id) {
            $container->setAlias($id, 'debug.' . $id);
            $container->setDefinition('debug.' . $id, new Definition(EventDispatcher::class));
            $container->setDefinition('debug.' . $id . '.inner', new Definition(EventDispatcher::class));
        }
        (new EventDispatcherProcessor())->process($container, $this->proxifier($container));
        foreach (['event_dispatcher', 'security.event_dispatcher.api'] as $id) {
            self::assertFalse($container->getDefinition('debug.' . $id . '.inner')->isShared());
            self::assertTrue($container->getDefinition('debug.' . $id)->hasTag(ContainerConstants::TAG_STATEFUL_SERVICE));
        }
    }

    public function testFactoryTransportAndUntaggedTraceClientBecomePoolableWithoutAutoloadingOptionalDefinitions(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('http_client.uri_template.inner', (new Definition(HttpClientInterface::class))->setFactory([HttpClient::class, 'create']));
        $container->setDefinition('traced', new Definition(TraceableHttpClient::class));
        $customFactory = [HttpClient::class, 'createForBaseUri'];
        $container->setDefinition('custom', (new Definition(HttpClientInterface::class))->setFactory($customFactory));
        $container->setDefinition('optional', new Definition('Absent\\OptionalIntegration'));
        (new HttpClientProcessor())->process($container, $this->proxifier($container));
        self::assertSame(HttpClient::create()::class, $container->getDefinition('http_client.uri_template.inner')->getClass());
        self::assertTrue($container->getDefinition('traced')->hasTag(ContainerConstants::TAG_STATEFUL_SERVICE));
        self::assertSame(HttpClientInterface::class, $container->getDefinition('custom')->getClass());
        self::assertSame($customFactory, $container->getDefinition('custom')->getFactory());
    }

    public function testSecurityDecoratorOwnsItsManagerAndConcreteAuthenticatorIsTagged(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        $container->setDefinition('debug.security.access.decision_manager', new Definition());
        $container->setDefinition('debug.security.access.decision_manager.inner', new Definition());
        $container->setDefinition('authenticator', new Definition(TraceableAuthenticator::class));
        $container->setDefinition('authenticator.template', (new Definition(TraceableAuthenticator::class))->setAbstract(true));
        (new SecurityProcessor())->process($container, $this->proxifier($container));
        self::assertFalse($container->getDefinition('debug.security.access.decision_manager.inner')->isShared());
        self::assertTrue($container->getDefinition('authenticator')->hasTag(ContainerConstants::TAG_STATEFUL_SERVICE));
        $resetterId = $container->getDefinition('authenticator')->getTag(ContainerConstants::TAG_STATEFUL_SERVICE)[0]['resetter'];
        self::assertSame(TraceableAuthenticatorResetter::class, $container->getDefinition($resetterId)->getClass());
        self::assertFalse($container->getDefinition('authenticator.template')->hasTag(ContainerConstants::TAG_STATEFUL_SERVICE));
    }

    public function testReusedAuthenticatorDropsEarlierPassportAndFailureBeforeAnUnsupportedRequest(): void
    {
        $passport = new SelfValidatingPassport(new UserBadge('member@baander.app'));
        $authenticator = $this->createStub(AuthenticatorInterface::class);
        $authenticator->method('supports')->willReturn(true, false);
        $authenticator->method('authenticate')->willReturn($passport);
        $trace = new TraceableAuthenticator($authenticator);
        $request = new Request();
        self::assertTrue($trace->supports($request));
        $trace->authenticate($request);
        $exception = new AuthenticationException('Rejected credentials');
        $trace->onAuthenticationFailure($request, $exception);
        self::assertSame($passport, $trace->getInfo()['passport']);
        self::assertSame($exception, $trace->getInfo()['exception']);
        self::assertNotNull($trace->getInfo()['duration']);

        (new TraceableAuthenticatorResetter())->reset($trace);

        self::assertFalse($trace->supports(new Request()));
        $info = $trace->getInfo();
        self::assertNull($info['passport']);
        self::assertNull($info['duration']);
        self::assertNull($info['authenticated']);
        self::assertNull($info['exception']);
        self::assertSame([], $info['badges']);
    }

    public function testTwigExtensionsAreOwnedByEachEnvironmentWithoutRequiringACollector(): void
    {
        $container = new ContainerBuilder();
        foreach (['twig', 'twig.profile', 'twig.extension.profiler', 'twig.extension.webprofiler'] as $id) {
            $container->setDefinition($id, new Definition());
        }
        (new TwigProcessor())->process($container, $this->proxifier($container));
        self::assertFalse($container->getDefinition('twig.extension.profiler')->isShared());
        self::assertFalse($container->getDefinition('twig.extension.webprofiler')->isShared());
        self::assertTrue($container->getDefinition('twig.profile')->hasTag(ContainerConstants::TAG_STATEFUL_SERVICE));
        self::assertFalse($container->hasDefinition('data_collector.twig'));
    }

    private function proxifier(ContainerBuilder $container): Proxifier
    {
        return new Proxifier($container, new ClassModificationProcessor($container));
    }
}
