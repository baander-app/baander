<?php

declare(strict_types=1);

namespace App\Command\DocGenerator;

use App\Command\DocGenerator\Model\BoundedContext;
use App\Command\DocGenerator\Model\ClassInfo;
use App\Command\DocGenerator\Model\HandlerInfo;
use App\Command\DocGenerator\Model\RouteInfo;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Attribute\Route;

final class CodebaseScanner
{
    private const EXCLUDED_PATHS = [
        'Shared/Infrastructure/Swoole/',
        '/Infrastructure/Doctrine/Entity/',
    ];

    private const SELF_EXCLUDE = [
        'Command/GenerateDocsCommand.php',
        'Command/DocGenerator/',
    ];

    private DocBlockFactory $docBlockFactory;

    public function __construct()
    {
        $this->docBlockFactory = DocBlockFactory::createInstance();
    }

    /**
     * @return list<BoundedContext>
     */
    public function scan(string $sourceDir): array
    {
        $sourceDir = realpath($sourceDir);
        $contexts = [];
        $contextDirs = $this->detectContextDirectories($sourceDir);

        foreach ($contextDirs as $contextName => $contextPath) {
            $contexts[] = $this->scanContext($contextName, $contextPath, $sourceDir);
        }

        usort($contexts, fn (BoundedContext $a, BoundedContext $b) => $a->name <=> $b->name);

        return $contexts;
    }

    /**
     * @return array<string, string> contextName => absolute path
     */
    private function detectContextDirectories(string $sourceDir): array
    {
        $contexts = [];
        $entries = scandir($sourceDir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $sourceDir . '/' . $entry;

            if (!is_dir($fullPath)) {
                continue;
            }

            if ($this->isSelfExcluded($entry, $fullPath)) {
                continue;
            }

            if ($this->containsPhpFiles($fullPath)) {
                $contexts[$entry] = $fullPath;
            }
        }

        return $contexts;
    }

    private function containsPhpFiles(string $dir): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                return true;
            }
        }

        return false;
    }

    private function scanContext(string $name, string $path, string $sourceDir): BoundedContext
    {
        $phpFiles = $this->findPhpFiles($path);
        $classes = [];
        $routes = [];
        $aggregateRoots = [];
        $valueObjects = [];
        $handlers = [];
        $layers = [];

        $repositoryInterfaces = $this->findRepositoryInterfaces($phpFiles, $sourceDir);

        foreach ($phpFiles as $filePath) {
            $relativePath = str_replace($sourceDir . '/', '', $filePath);

            if ($this->isExcludedPath($relativePath) || $this->isSelfExcludedFile($relativePath)) {
                continue;
            }

            $fqcn = $this->filePathToFqcn($relativePath);
            $classInfo = $this->reflectClass($fqcn, $relativePath);

            if ($classInfo === null) {
                continue;
            }

            $classes[] = $classInfo;
            $layer = $this->detectLayer($relativePath);

            if (!in_array($layer, $layers, true)) {
                $layers[] = $layer;
            }

            $classRoutes = $this->extractRoutes($fqcn, $relativePath);
            foreach ($classRoutes as $route) {
                $routes[] = $route;
            }

            if (in_array($classInfo->shortName, $repositoryInterfaces, true)) {
                $aggregateRoots[] = new ClassInfo(
                    fqcn: $classInfo->fqcn,
                    shortName: $classInfo->shortName,
                    namespace: $classInfo->namespace,
                    layer: $classInfo->layer,
                    description: $classInfo->description,
                    interfaces: $classInfo->interfaces,
                    properties: $classInfo->properties,
                    isAggregateRoot: true,
                );
            }

            if ($classInfo->isValueObject) {
                $valueObjects[] = $classInfo;
            }

            $handlerInfo = $this->extractHandler($fqcn, $classInfo->layer);
            if ($handlerInfo !== null) {
                $handlers[] = $handlerInfo;
            }
        }

        return new BoundedContext(
            name: $name,
            description: $this->extractContextDescription($name, $classes),
            classes: $classes,
            routes: $routes,
            aggregateRoots: $aggregateRoots,
            valueObjects: $valueObjects,
            handlers: $handlers,
            layers: $layers,
        );
    }

    private function reflectClass(string $fqcn, string $relativePath): ?ClassInfo
    {
        try {
            $reflection = new ReflectionClass($fqcn);
        } catch (\ReflectionException) {
            return null;
        }

        $description = $this->getDocblockDescription($reflection);
        $interfaces = array_map(
            fn (ReflectionClass $i) => $i->getShortName(),
            $reflection->getInterfaces(),
        );

        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->isPublic()) {
                $properties[] = $property->getName();
            }
        }

        $layer = $this->detectLayer($relativePath);
        $isEnum = $reflection->isEnum();
        $isValueObject = $this->isValueObject($reflection, $relativePath);

        return new ClassInfo(
            fqcn: $fqcn,
            shortName: $reflection->getShortName(),
            namespace: $reflection->getNamespaceName(),
            layer: $layer,
            description: $description,
            interfaces: $interfaces,
            properties: $properties,
            isValueObject: $isValueObject,
            isEnum: $isEnum,
        );
    }

    private function getDocblockDescription(ReflectionClass $reflection): string
    {
        $docComment = $reflection->getDocComment();

        if ($docComment === false) {
            return '';
        }

        try {
            $docBlock = $this->docBlockFactory->create($docComment);

            return $docBlock->getSummary() ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function getMethodDocblockSummary(ReflectionMethod $method): string
    {
        $docComment = $method->getDocComment();

        if ($docComment === false) {
            return '';
        }

        try {
            $docBlock = $this->docBlockFactory->create($docComment);

            return $docBlock->getSummary() ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<RouteInfo>
     */
    private function extractRoutes(string $fqcn, string $relativePath): array
    {
        try {
            $reflection = new ReflectionClass($fqcn);
        } catch (\ReflectionException) {
            return [];
        }

        $classAttributes = $reflection->getAttributes(Route::class);
        $classPrefix = '';
        $classNamePrefix = '';

        foreach ($classAttributes as $attr) {
            $instance = $attr->newInstance();
            $classPrefix = is_array($instance->path) ? implode('|', $instance->path) : ($instance->path ?? '');
            $classNamePrefix = $instance->name ?? '';
        }

        $routes = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodAttributes = $method->getAttributes(Route::class);

            foreach ($methodAttributes as $attr) {
                $instance = $attr->newInstance();
                $path = $classPrefix . (is_array($instance->path) ? implode('|', $instance->path) : ($instance->path ?? ''));
                $methods = implode(', ', !empty($instance->methods) ? $instance->methods : ['GET']);
                $name = $classNamePrefix . ($instance->name ?? $method->getName());

                $routes[] = new RouteInfo(
                    methods: $methods,
                    path: $path,
                    name: $name,
                    controllerFqcn: $fqcn,
                    methodName: $method->getName(),
                    description: $this->getMethodDocblockSummary($method),
                );
            }
        }

        return $routes;
    }

    private function extractHandler(string $fqcn, string $layer): ?HandlerInfo
    {
        try {
            $reflection = new ReflectionClass($fqcn);
        } catch (\ReflectionException) {
            return null;
        }

        if (!$reflection->hasMethod('__invoke')) {
            return null;
        }

        $invokeMethod = $reflection->getMethod('__invoke');
        $attributes = $invokeMethod->getAttributes(
            \Symfony\Component\Messenger\Attribute\AsMessageHandler::class,
        );

        if (empty($attributes)) {
            return null;
        }

        $parameters = $invokeMethod->getParameters();
        if (empty($parameters)) {
            return null;
        }

        $commandType = $parameters[0]->getType();
        if ($commandType === null) {
            return null;
        }

        $commandFqcn = $commandType instanceof \ReflectionNamedType ? $commandType->getName() : (string) $commandType;
        $commandShortName = basename(str_replace('\\', '/', $commandFqcn));

        return new HandlerInfo(
            handlerFqcn: $fqcn,
            handlerShortName: $reflection->getShortName(),
            commandFqcn: $commandFqcn,
            commandShortName: $commandShortName,
            layer: $layer,
        );
    }

    private function isValueObject(ReflectionClass $reflection, string $relativePath): bool
    {
        if ($reflection->isEnum()) {
            return true;
        }

        $isInValueObjectDir = preg_match('#/ValueObject/#', $relativePath) === 1
            || preg_match('#/Model/ValueObject/#', $relativePath) === 1;

        if (!$isInValueObjectDir) {
            return false;
        }

        if (!$reflection->isFinal()) {
            return false;
        }

        if (!$reflection->isReadOnly() && !$reflection->isEnum()) {
            return false;
        }

        return true;
    }

    private function detectLayer(string $relativePath): string
    {
        $segments = explode('/', $relativePath);

        if (count($segments) >= 2) {
            $potentialLayer = $segments[1];
            $standardLayers = ['Domain', 'Application', 'Infrastructure', 'Interface'];

            if (in_array($potentialLayer, $standardLayers, true)) {
                return $potentialLayer;
            }
        }

        return count($segments) >= 2 ? $segments[1] : 'root';
    }

    /**
     * @param list<string> $phpFiles
     * @return list<string> short names of repository interfaces
     */
    private function findRepositoryInterfaces(array $phpFiles, string $sourceDir): array
    {
        $interfaces = [];

        foreach ($phpFiles as $filePath) {
            $relativePath = str_replace($sourceDir . '/', '', $filePath);
            if (!str_contains($relativePath, '/Repository/') || !str_ends_with($filePath, 'Interface.php')) {
                continue;
            }

            $fqcn = $this->filePathToFqcn($relativePath);
            try {
                $reflection = new ReflectionClass($fqcn);
                if ($reflection->isInterface()) {
                    $interfaces[] = str_replace('RepositoryInterface', '', $reflection->getShortName());
                }
            } catch (\ReflectionException) {
                // Skip classes that cannot be reflected
            }
        }

        return $interfaces;
    }

    private function extractContextDescription(string $contextName, array $classes): string
    {
        foreach ($classes as $classInfo) {
            if ($classInfo->description !== '' && $classInfo->layer === 'Domain') {
                return $classInfo->description;
            }
        }

        return '';
    }

    /**
     * @return list<string> absolute paths to PHP files
     */
    private function findPhpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

    private function filePathToFqcn(string $relativePath): string
    {
        $classPath = substr($relativePath, 0, -4);
        $normalized = str_replace('/', '\\', $classPath);

        return 'App\\' . $normalized;
    }

    private function isExcludedPath(string $relativePath): bool
    {
        foreach (self::EXCLUDED_PATHS as $excludedPath) {
            if (str_contains($relativePath, $excludedPath)) {
                return true;
            }
        }

        return false;
    }

    private function isSelfExcluded(string $entry, string $fullPath): bool
    {
        return false;
    }

    private function isSelfExcludedFile(string $relativePath): bool
    {
        foreach (self::SELF_EXCLUDE as $excluded) {
            if (str_contains($relativePath, $excluded)) {
                return true;
            }
        }

        return false;
    }
}
