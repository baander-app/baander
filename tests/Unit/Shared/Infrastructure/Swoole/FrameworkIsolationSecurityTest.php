<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\SwoolePoolStatsProvider;
use Closure;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ProxyManager\Configuration;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Response as SwooleResponse;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServicesPass;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\ContextualProxy;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Generator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Instantiator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\InFlightStreams;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\StreamedResponseProcessor;
use SwooleBundle\SwooleBundle\Component\Locking\Channel\ChannelMutexFactory;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\UsageTrackingTokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The token storage pair and the authorization checker as SecurityBundle defines them, run
 * through the bundle's compiler pass and used from overlapping coroutines that release their
 * pooled services through CoWrapper, as the bundle's entry points do.
 */
final class FrameworkIsolationSecurityTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTokenIsVisibleOnlyInTheCoroutineThatSetItAndGoneOnceThatCoroutineReleases(): void
    {
        $container = $this->container();
        $storage = $container->get('security.token_storage');
        $pools = $container->get(ServicePoolContainer::class);
        $coroutines = new CoWrapper($pools, new Swoole());
        $idle = $this->activeCounts($pools);
        $seen = [];

        Coroutine\run(function () use ($storage, $coroutines, &$seen): void {
            $tokenSet = new Channel(1);
            $secondEnded = new Channel(1);
            $coroutines->go(function () use ($storage, $tokenSet, $secondEnded, &$seen): void {
                $storage->setToken($this->token('first'));
                $tokenSet->push(true);
                $secondEnded->pop();
                $seen['first, after the second ended'] = $storage->getToken()?->getUserIdentifier();
            });
            $coroutines->go(function () use ($storage, $tokenSet, &$seen): void {
                $tokenSet->pop();
                $seen['second, while the first is suspended'] = $storage->getToken()?->getUserIdentifier();
                $storage->setToken($this->token('second'));
            });
            $secondEnded->push(true);
        });
        Coroutine\run(static function () use ($storage, $coroutines, &$seen): void {
            $coroutines->go(static function () use ($storage, &$seen): void {
                $seen['third, after both released'] = $storage->getToken()?->getUserIdentifier();
            });
        });

        self::assertSame([
            'second, while the first is suspended' => null,
            'first, after the second ended' => 'first',
            'third, after both released' => null,
        ], $seen);
        self::assertSame($idle, $this->activeCounts($pools));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReleasedInnerStorageIsHandedToTheNextCoroutineWithoutItsToken(): void
    {
        $container = $this->container();
        $inner = $container->get('security.untracked_token_storage');
        $pool = $this->innerStoragePool($container);
        $coroutines = new CoWrapper($container->get(ServicePoolContainer::class), new Swoole());
        $assigned = [];
        $handedOver = 'not read';

        // The inner storage is written directly, as ContextListener does, so only its own pool's
        // resetter can clear it: the wrapper is never assigned and its setToken() never runs.
        Coroutine\run(function () use ($inner, $pool, $coroutines, &$assigned): void {
            $coroutines->go(function () use ($inner, $pool, &$assigned): void {
                $inner->setToken($this->token('first'));
                $assigned[] = $pool->get();
            });
        });
        Coroutine\run(static function () use ($inner, $pool, $coroutines, &$assigned, &$handedOver): void {
            $coroutines->go(static function () use ($inner, $pool, &$assigned, &$handedOver): void {
                $handedOver = $inner->getToken();
                $assigned[] = $pool->get();
            });
        });

        self::assertCount(2, $assigned);
        self::assertSame($assigned[0], $assigned[1]);
        self::assertNull($handedOver);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReleasingAWrapperWhoseCoroutineNeverTookAnInnerStorageRaisesNothing(): void
    {
        $container = $this->container();
        $storage = $container->get('security.token_storage');
        $innerPool = $this->innerStoragePool($container);
        $pools = $container->get(ServicePoolContainer::class);
        $coroutines = new CoWrapper($pools, new Swoole());
        $idle = $this->activeCounts($pools);
        $innerAssignedBeforeRelease = null;

        // The wrapper's setToken() reset reaches the inner pool only while the coroutine is released.
        $log = $this->captureErrors(static function () use ($storage, $innerPool, $coroutines, &$innerAssignedBeforeRelease): void {
            Coroutine\run(static function () use ($storage, $innerPool, $coroutines, &$innerAssignedBeforeRelease): void {
                $coroutines->go(static function () use ($storage, $innerPool, &$innerAssignedBeforeRelease): void {
                    $storage->disableUsageTracking();
                    $innerAssignedBeforeRelease = $innerPool->getAssignedCount();
                });
            });
        });

        self::assertSame(0, $innerAssignedBeforeRelease);
        self::assertSame('', $log);
        self::assertSame($idle, $this->activeCounts($pools));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsGrantedStacksOfTwoCoroutinesDoNotMix(): void
    {
        $container = $this->container();
        $storage = $container->get('security.token_storage');
        $checker = $container->get('security.authorization_checker');
        $voter = $container->get(FrameworkIsolationSecurityVoter::class);
        $pools = $container->get(ServicePoolContainer::class);
        $coroutines = new CoWrapper($pools, new Swoole());
        $idle = $this->activeCounts($pools);
        $firstDecision = new AccessDecision();

        Coroutine\run(function () use ($storage, $checker, $voter, $coroutines, $firstDecision): void {
            $voter->held = new Channel(1);
            $voter->resume = new Channel(1);
            // The voter suspends this check while the user's token and the decision are on the checker's stacks.
            $coroutines->go(static function () use ($checker, $firstDecision): void {
                $checker->isGrantedForUser(new InMemoryUser('offline', null), 'HOLD', null, $firstDecision);
            });
            $coroutines->go(function () use ($storage, $checker, $voter): void {
                $voter->held->pop();
                $storage->setToken($this->token('second'));
                $checker->isGranted('PASS');
            });
            $voter->resume->push(true);
        });

        self::assertSame(
            ['HOLD' => 'offline', 'PASS' => 'second', 'votes in the first decision' => 1],
            [...$voter->votedFor, 'votes in the first decision' => count($firstDecision->votes)],
        );
        self::assertSame($idle, $this->activeCounts($pools));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStreamedResponseCompletesInItsOwnCoroutineWithoutTheRequestToken(): void
    {
        $container = $this->container();
        $storage = $container->get('security.token_storage');
        $pools = $container->get(ServicePoolContainer::class);
        $coroutines = new CoWrapper($pools, new Swoole());
        $idle = $this->activeCounts($pools);
        $sent = '';
        $transport = $this->createMock(SwooleResponse::class);
        $transport->method('write')->willReturnCallback(static function (string $payload) use (&$sent): bool {
            $sent .= $payload;

            return true;
        });
        $transport->expects(self::once())->method('end');
        $processor = new StreamedResponseProcessor($coroutines, new InFlightStreams(static fn(int $fd): bool => true));
        $seen = [];
        $response = new StreamedResponse(static function () use ($storage, &$seen): void {
            echo 'first-';
            $seen['stream'] = $storage->getToken()?->getUserIdentifier();
            echo 'last';
        });

        $log = $this->captureErrors(function () use ($storage, $coroutines, $processor, $response, $transport, &$seen): void {
            Coroutine\run(function () use ($storage, $coroutines, $processor, $response, $transport, &$seen): void {
                $coroutines->go(function () use ($storage, $processor, $response, $transport, &$seen): void {
                    $storage->setToken($this->token('member'));
                    $processor->process($response, $transport);
                    $seen['request, after the stream ended'] = $storage->getToken()?->getUserIdentifier();
                });
            });
        });

        self::assertSame('first-last', $sent);
        self::assertSame('', $log);
        self::assertSame(['stream' => null, 'request, after the stream ended' => 'member'], $seen);
        self::assertSame($idle, $this->activeCounts($pools));
    }

    /**
     * The security services as SecurityBundle's security.php defines them, after the bundle's pass.
     */
    private function container(): ContainerBuilder
    {
        self::assertTrue(extension_loaded('ffi'), 'FFI is required; this proof must not skip.');
        $container = new ContainerBuilder();
        $container->addResource(new FileResource(__FILE__));
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_ENABLED, true);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_MAX_SVC_INSTANCES, 4);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_STATEFUL_SERVICES, []);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_COMPILE_PROCESSORS, []);
        $container->setDefinition('kernel_proxy', (new Definition(FrameworkIsolationSecurityKernel::class))
            ->addTag(ContainerConstants::TAG_SAFE_STATEFUL_SERVICE));
        $container->register(ServicePoolContainer::class, ServicePoolContainer::class)->setPublic(true);
        $container->setDefinition('services_resetter', new Definition(null, [
            new ServiceLocatorArgument(['security.token_storage' => new Reference('security.token_storage')]),
            ['security.token_storage' => ['disableUsageTracking', 'setToken']],
        ]));
        $container->register('security.untracked_token_storage', TokenStorage::class)->setPublic(true);
        $container->register('security.token_storage', UsageTrackingTokenStorage::class)
            ->setArguments([new Reference('security.untracked_token_storage'), new Definition(Container::class)])
            ->addTag('kernel.reset', ['method' => 'disableUsageTracking'])
            ->addTag('kernel.reset', ['method' => 'setToken'])
            ->setPublic(true);
        $container->register(FrameworkIsolationSecurityVoter::class, FrameworkIsolationSecurityVoter::class)->setPublic(true);
        $container->register('security.access.decision_manager', AccessDecisionManager::class)
            ->setArguments([[new Reference(FrameworkIsolationSecurityVoter::class)]]);
        $container->register('security.authorization_checker', AuthorizationChecker::class)
            ->setArguments([new Reference('security.token_storage'), new Reference('security.access.decision_manager')])
            ->setPublic(true);

        new StatefulServicesPass()->process($container);

        $configuration = new Configuration();
        $configuration->setProxiesNamespace('FrameworkIsolationSecurity' . bin2hex(random_bytes(8)));
        $configuration->setGeneratorStrategy(new EvaluatingGeneratorStrategy());
        $container->set(Swoole::class, new Swoole());
        $container->set(Instantiator::class, new Instantiator(new Generator($configuration)));
        $container->set('swoole_bundle.service_pool.locking', new ChannelMutexFactory(acquireTimeout: .5));

        return $container;
    }

    /**
     * @return ServicePool<object> the pool behind security.untracked_token_storage
     */
    private function innerStoragePool(ContainerBuilder $container): ServicePool
    {
        $inner = $container->get('security.untracked_token_storage');
        self::assertInstanceOf(
            ContextualProxy::class,
            $inner,
            'The inner token storage is one object for the whole worker instead of one per coroutine.',
        );

        return $inner->getServicePool();
    }

    /**
     * @return list<int> the assigned count of every pool, as SwoolePoolStatsProvider reports it
     */
    private function activeCounts(ServicePoolContainer $pools): array
    {
        return array_column(new SwoolePoolStatsProvider($pools)->getStats(), 'active');
    }

    private function token(string $userIdentifier): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userIdentifier, null), 'api');
    }

    private function captureErrors(Closure $operation): string
    {
        $path = tempnam(sys_get_temp_dir(), 'security-isolation-');
        self::assertNotFalse($path);
        $previous = ini_set('error_log', $path);
        try {
            $operation();

            return (string) file_get_contents($path);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($path);
        }
    }
}

/**
 * Grants every attribute and records the user each one was decided for; a HOLD vote suspends until resumed.
 */
class FrameworkIsolationSecurityVoter implements VoterInterface
{
    /** @var array<string, string> user identifier by attribute */
    public array $votedFor = [];

    public Channel $held;

    public Channel $resume;

    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        $this->votedFor[$attributes[0]] = $token->getUserIdentifier();
        if ($attributes === ['HOLD']) {
            $this->held->push(true);
            $this->resume->pop();
        }

        return self::ACCESS_GRANTED;
    }
}

class FrameworkIsolationSecurityKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void {}
}
