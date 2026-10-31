<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\HttpKernel\Controller;

use LogicException;
use Override;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Controller\ContainerControllerResolver;

final class NonMutatingControllerResolver extends ContainerControllerResolver
{
    private ?ReflectionProperty $containerProperty = null;

    #[Override]
    protected function instantiateController(string $class): object
    {
        $controller = parent::instantiateController($class);

        if ($controller instanceof AbstractController && !$this->hasContainer($controller)) {
            throw new LogicException(
                sprintf('"%s" has no container set, did you forget to define it as a service subscriber?', $class),
            );
        }

        return $controller;
    }

    private function hasContainer(AbstractController $controller): bool
    {
        $this->containerProperty ??= new ReflectionProperty(AbstractController::class, 'container');

        return $this->containerProperty->isInitialized($controller);
    }
}
