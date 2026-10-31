<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use ProxyManager\Configuration;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Generator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Twig\UnwrappingTwigDataCollector;
use SwooleBundle\SwooleBundle\Reflection\ClassModifier;
use Twig\Profiler\Profile;
use SwooleBundle\SwooleBundle\Bridge\Symfony\WebProfiler\ContentSecurityPolicyHandlerResetter;
use Symfony\Bundle\WebProfilerBundle\Csp\ContentSecurityPolicyHandler;
use Symfony\Bundle\WebProfilerBundle\Csp\NonceGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FrameworkIsolationProfilerTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCollectedProxyProfileSurvivesSymfonySerializationAllowlist(): void
    {
        ClassModifier::removeFinalFlagsFromClass(Profile::class);
        $profile = new Profile();
        $profile->addProfile(new Profile('baander.app.twig', Profile::TEMPLATE));
        $pool = $this->createStub(ServicePool::class);
        $pool->method('get')->willReturn($profile);
        $configuration = new Configuration();
        $configuration->setGeneratorStrategy(new EvaluatingGeneratorStrategy());
        $proxy = (new Generator($configuration))->createProxy($pool, Profile::class);
        self::assertInstanceOf(Profile::class, $proxy);
        $collector = new UnwrappingTwigDataCollector($proxy);
        $collector->lateCollect();
        $restored = unserialize(serialize($collector));
        self::assertInstanceOf(UnwrappingTwigDataCollector::class, $restored);
        self::assertSame(Profile::class, $restored->getProfile()::class);
        self::assertSame('baander.app.twig', $restored->getProfile()->getProfiles()[0]->getTemplate());
    }

    public function testReusedHandlerRestoresCspAfterToolbarDisabledIt(): void
    {
        $handler = new ContentSecurityPolicyHandler(new NonceGenerator());
        $handler->disableCsp();
        $disabled = new Response(headers: ['Content-Security-Policy' => "default-src 'self'"]);
        $handler->updateResponseHeaders(new Request(), $disabled);
        self::assertFalse($disabled->headers->has('Content-Security-Policy'));
        (new ContentSecurityPolicyHandlerResetter())->reset($handler);
        $reused = new Response(headers: ['Content-Security-Policy' => "default-src 'self'"]);
        $handler->updateResponseHeaders(new Request(), $reused);
        self::assertTrue($reused->headers->has('Content-Security-Policy'));
    }
}
