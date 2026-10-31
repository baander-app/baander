<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Reflection;

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionMethod;

final class ClassModifier
{
    /** @var array<class-string, true> */
    private static array $strippedClasses = [];

    public static function initialize(): void
    {
        if (!isset(Core::$executor)) {
            Core::init();
        }
    }

    /**
     * @param class-string $className
     * @return bool Whether every process loading a proxy for this class must modify it.
     */
    public static function removeFinalFlagsFromClass(string $className): bool
    {
        self::initialize();
        $reflClass = new ReflectionClass($className);
        $className = $reflClass->getName();

        if (isset(self::$strippedClasses[$className])) {
            return true;
        }

        if (self::hasNativeParents($reflClass)) {
            // z-engine cannot safely modify classes with native parents.
            return false;
        }

        $finalMethods = array_filter(
            $reflClass->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $method): bool => $method->isFinal(),
        );

        if (!$reflClass->isFinal() && $finalMethods === []) {
            return false;
        }

        $reflClass->setFinal(false);
        foreach ($finalMethods as $method) {
            $method->setFinal(false);
        }
        self::$strippedClasses[$className] = true;

        return true;
    }

    /** @param iterable<class-string> $classNames */
    public static function removeFinalFlagsFromClasses(iterable $classNames): void
    {
        foreach ($classNames as $className) {
            self::removeFinalFlagsFromClass($className);
        }
    }

    private static function hasNativeParents(ReflectionClass $class): bool
    {
        do {
            if ($class->isInternal()) {
                return true;
            }

            $class = $class->getParentClass();
        } while ($class instanceof ReflectionClass);

        return false;
    }
}
