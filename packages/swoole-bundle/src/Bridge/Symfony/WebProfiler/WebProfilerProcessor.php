<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\WebProfiler;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\CompileProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class WebProfilerProcessor implements CompileProcessor
{
    private const string CSP_HANDLER_ID = 'web_profiler.csp.handler';
    private const string CSP_HANDLER_RESETTER_ID = 'swoole_bundle.web_profiler.csp_handler_resetter';

    public function process(ContainerBuilder $container, Proxifier $proxifier): void
    {
        if (!$container->hasDefinition(self::CSP_HANDLER_ID)) {
            return;
        }

        if (!$container->hasDefinition(self::CSP_HANDLER_RESETTER_ID)) {
            $resetterDef = new Definition(ContentSecurityPolicyHandlerResetter::class);
            $resetterDef->setPublic(false);
            $container->setDefinition(self::CSP_HANDLER_RESETTER_ID, $resetterDef);
        }

        $container->findDefinition(self::CSP_HANDLER_ID)
            ->addTag(ContainerConstants::TAG_STATEFUL_SERVICE, [
                'resetter' => self::CSP_HANDLER_RESETTER_ID,
            ]);
    }
}
