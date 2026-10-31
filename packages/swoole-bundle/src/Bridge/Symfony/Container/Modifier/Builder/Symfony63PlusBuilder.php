<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Modifier\Builder;

use Assert\Assertion;
use ReflectionMethod as CoreReflectionMethod;
use RuntimeException;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\BlockingContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ContainerSourceCodeExtractor;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Reflection\ReflectionMethod;

/**
 * @phpstan-import-type ContainerMethodInternals from ContainerSourceCodeExtractor
 */
final class Symfony63PlusBuilder implements Builder
{
    public function overrideGeneratedContainer(ReflectionClass $reflContainer, string $cacheDir, bool $isDebug): void
    {
        $fs = new Filesystem();
        $containerFqcn = $reflContainer->getName();
        $overriddenFqcn = $containerFqcn . '_Overridden';
        $classParts = explode('\\', $containerFqcn);
        $containerClass = array_pop($classParts);
        $overriddenClass = $containerClass . '_Overridden';
        $containerFile = $this->getContainerFile($reflContainer);
        $containerDirectory = dirname($containerFile);
        $cacheDir = $reflContainer->getNamespaceName() === '' ? $containerDirectory : dirname($containerDirectory);
        $overriddenFile = $containerDirectory . DIRECTORY_SEPARATOR . $overriddenClass . '.php';

        if ($this->isAlreadyOverridden($containerFile, $overriddenClass)) {
            if (!is_file($overriddenFile)) {
                throw new RuntimeException(sprintf('Generated container parent "%s" is missing.', $overriddenFile));
            }
            $this->overrideCachedEntrypoint($fs, $cacheDir, $containerClass, $overriddenFqcn, $isDebug);

            return;
        }

        $containerSource = file_get_contents($containerFile);

        if ($containerSource === false) {
            throw new RuntimeException(sprintf('Could not read container file "%s".', $containerFile));
        }

        $codeExtractor = new ContainerSourceCodeExtractor($containerSource);
        $overriddenSource = str_replace('class ' . $containerClass, 'class ' . $overriddenClass, $containerSource);

        // dump opcache.blacklist_filename
        $blacklistFile = implode(
            DIRECTORY_SEPARATOR,
            [$cacheDir, ContainerConstants::PARAM_CACHE_FOLDER, 'opcache', 'blacklist.txt'],
        );
        $blacklistFiles = [$containerFile, $overriddenFile];
        $blacklistFileContent = implode(PHP_EOL, $blacklistFiles) . PHP_EOL;
        $fs->dumpFile($blacklistFile, $blacklistFileContent);

        // methods override
        $ignoredMethods = $this->getIgnoredGetters();
        $methods = $reflContainer->getMethods(CoreReflectionMethod::IS_PROTECTED);
        $methodsCodes = [];

        if (!$reflContainer->hasMethod('createProxy')) {
            $methodsCodes[] = $this->generateOverriddenCreateProxy();
        }

        $methodsCodes[] = $this->generateOverridenLoad($reflContainer);

        foreach ($methods as $method) {
            $methodName = $method->getName();

            if (isset($ignoredMethods[$methodName]) || !str_starts_with($methodName, 'get')) {
                continue;
            }

            $methodsCodes[] = $this->generateOverriddenGetter($method, $codeExtractor);
        }

        $namespace = $reflContainer->getNamespaceName();
        $modifierClassToUse = self::class;
        $methodsCode = implode(PHP_EOL . PHP_EOL, $methodsCodes);
        $newContainerSource = <<<EOF
            <?php

            namespace $namespace;

            use $modifierClassToUse;

            class $containerClass extends $overriddenClass
            {
            $methodsCode
            }
            EOF;

        $fs->dumpFile($overriddenFile, $overriddenSource);
        $fs->dumpFile($containerFile, $newContainerSource);
        $this->overrideCachedEntrypoint($fs, $cacheDir, $containerClass, $overriddenFqcn, $isDebug);
    }

    public function overrideGeneratedContainerGetters(ReflectionClass $reflContainer, string $cacheDir): void
    {
        $fs = new Filesystem();
        $containerNamespace = $reflContainer->getNamespaceName();
        $containerDirectory = dirname($this->getContainerFile($reflContainer));
        $files = scandir($containerDirectory);

        if ($files === false) {
            throw new RuntimeException(sprintf('Could not read container directory "%s".', $containerDirectory));
        }

        $filteredFiles = array_filter(
            $files,
            static fn(string $fileName): bool => str_starts_with($fileName, 'get') && str_ends_with($fileName, '.php'),
        );

        foreach ($filteredFiles as $fileName) {
            if (!str_ends_with($fileName, '__Overridden.php')) {
                continue;
            }
            $originalFile = str_replace('__Overridden.php', '.php', $fileName);
            $originalPath = $containerDirectory . DIRECTORY_SEPARATOR . $originalFile;
            if (!is_file($originalPath)) {
                $parentPath = $containerDirectory . DIRECTORY_SEPARATOR . $fileName;
                $parentSource = file_get_contents($parentPath);
                if ($parentSource === false) {
                    throw new RuntimeException(sprintf('Could not read generated service parent "%s".', $parentPath));
                }
                $fs->dumpFile($originalPath, str_replace(substr($fileName, 0, -4), substr($originalFile, 0, -4), $parentSource));
                $filteredFiles[] = $originalFile;
            }
        }

        foreach ($filteredFiles as $fileName) {
            $class = str_replace('.php', '', $fileName);
            $this->generateOverriddenDoInExtension($fs, $containerDirectory, $fileName, $class, $containerNamespace);
        }
    }

    private function generateOverriddenCreateProxy(): string
    {
        return <<<'EOF'
                        protected function createProxy($class, \Closure $factory)
                        {
                            self::$mutex->acquire();

                            try {
                                $return = parent::createProxy($class, $factory);
                            } finally {
                                self::$mutex->release();
                            }

                            return $return;
                        }
            EOF;
    }

    private function generateOverridenLoad(ReflectionClass $reflContainer): string
    {
        $loadRefl = $reflContainer->getMethod('load');

        if ($loadRefl->getDeclaringClass()->getName() === Container::class) {
            return self::generateOverrideOriginalContainerLoad();
        }

        return self::generateOverridenGeneratedLoad();
    }

    private static function generateOverrideOriginalContainerLoad(): string
    {
        return <<<'EOF'
                protected function load(string $file)
                {
                    self::$mutex->acquire();

                    try {
                        $overriddenLoad = str_replace('.php', '__Overridden.php', $file);
                        require_once $overriddenLoad;

                        $return = parent::load($file);
                    } finally {
                        self::$mutex->release();
                    }

                    return $return;
                }
            EOF;
    }

    private static function generateOverridenGeneratedLoad(): string
    {
        return <<<'EOF'
                protected function load($file, $lazyLoad = true): mixed
                {
                    self::$mutex->acquire();

                    try {
                        $fileToLoad = $file;
                        $class = self::$buildContainerNs.'\\'.$file;
                        if ('.' === $file[-4]) {
                            $class = substr($class, 0, -4);
                        } else {
                            $fileToLoad .= '.php';
                        }

                        $overriddenLoad = str_replace('.php', '__Overridden.php', $fileToLoad);
                        require_once $this->containerDir . DIRECTORY_SEPARATOR . $overriddenLoad;

                        $return = parent::load($file, $lazyLoad);
                    } finally {
                        self::$mutex->release();
                    }

                    return $return;
                }
            EOF;
    }

    private function overrideCachedEntrypoint(
        Filesystem $fs,
        string $cacheDir,
        string $containerClass,
        string $overriddenFqcn,
        bool $isDebug,
    ): void {
        $cache = new ConfigCache($cacheDir . '/' . $containerClass . '.php', $isDebug);
        $cachePath = $cache->getPath();

        if (!file_exists($cachePath)) {
            throw new RuntimeException('Generated cached entry point file is missing.');
        }

        $content = file_get_contents($cachePath);

        if ($content === false) {
            throw new RuntimeException('Could not read generated cached file.');
        }

        $overriddenFile = str_replace('\\', DIRECTORY_SEPARATOR, $overriddenFqcn) . '.php';

        $header = <<<'EOF'
            <?php

            EOF;

        $newHeader = <<<EOF
            <?php

            require_once __DIR__.'/$overriddenFile';

            EOF;

        if (str_contains($content, "require_once __DIR__.'/$overriddenFile';")) {
            return;
        }
        $replacedContent = str_replace($header, $newHeader, $content);
        $fs->dumpFile($cachePath, $replacedContent);
    }

    private function generateOverriddenGetter(ReflectionMethod $method, ContainerSourceCodeExtractor $extractor): string
    {
        $methodName = $method->getName();
        $internals = $extractor->getContainerInternalsForMethod($method, $method->isStatic());

        if (isset($internals['type']) && $internals['type'] === 'factories') {
            $internals = [];
        }

        return $method->getNumberOfParameters() > 0
            ? $this->generateLazyGetter($methodName, $internals)
            : $this->generateCasualGetter($methodName, $internals);
    }

    /**
     * @param ContainerMethodInternals $internals
     */
    private function generateLazyGetter(string $methodName, array $internals): string
    {
        $sharedCheck = PHP_EOL;

        if (!empty($internals)) {
            Assertion::keyExists($internals, 'key');
            Assertion::keyExists($internals, 'type');

            $arrayKey = "['{$internals['key']}']" . (isset($internals['key2']) ? "['{$internals['key2']}']" : '');
            $sharedCheck = <<<EOF
                                        if (isset(\$container->{$internals['type']}{$arrayKey})) {
                                            if (\$lazyLoad === true) {
                                                return \$container->{$internals['type']}{$arrayKey};
                                            } elseif (\$lazyLoad === false && isset(\$container->lazyInitializedShared['$methodName'])) {
                                                return \$container->lazyInitializedShared['$methodName'];
                                            }
                                        }

                EOF;
        }

        return <<<EOF
                    protected static function $methodName(\$container, \$lazyLoad = true) {
                        if (is_string(\$lazyLoad)) {
                            \$lazyLoad = true;
                        }

            {$sharedCheck}
                        try {
                            self::\$mutex->acquire();
            {$sharedCheck}

                            \$return = parent::{$methodName}(\$container, \$lazyLoad);

                            if (\$lazyLoad !== true) \$container->lazyInitializedShared['$methodName'] = \$return;
                        } finally {
                            self::\$mutex->release();
                        }

                        return \$return;
                    }
            EOF;
    }

    /**
     * @param ContainerMethodInternals $internals
     */
    private function generateCasualGetter(string $methodName, array $internals): string
    {
        $sharedCheck = PHP_EOL;

        if (!empty($internals)) {
            Assertion::keyExists($internals, 'key');
            Assertion::keyExists($internals, 'type');

            $arrayKey = "['{$internals['key']}']" . (isset($internals['key2']) ? "['{$internals['key2']}']" : '');
            $sharedCheck = <<<EOF

                                        if (isset(\$container->{$internals['type']}{$arrayKey})) {
                                            return \$container->{$internals['type']}{$arrayKey};
                                        }

                EOF;
        }

        return <<<EOF
                    protected static function $methodName(\$container) {
            {$sharedCheck}
                        try {
                            self::\$mutex->acquire();
            {$sharedCheck}
                            \$return = parent::{$methodName}(\$container);
                        } finally {
                            self::\$mutex->release();
                        }

                        return \$return;
                    }
            EOF;
    }

    private function generateOverriddenDoInExtension(
        Filesystem $fs,
        string $containerDir,
        string $fileToLoad,
        string $class,
        string $namespace,
    ): void {
        $fullPath = $containerDir . DIRECTORY_SEPARATOR . $fileToLoad;

        if (str_contains($fullPath, '__Overridden.php') || str_contains($class, '__Overridden')) {
            return;
        }

        $fullOverriddenPath = str_replace('.php', '__Overridden.php', $fullPath);

        $overriddenClass = $class . '__Overridden';
        if ($this->isAlreadyOverridden($fullPath, $overriddenClass)) {
            if (!is_file($fullOverriddenPath)) {
                throw new RuntimeException(sprintf('Generated service parent "%s" is missing.', $fullOverriddenPath));
            }

            return;
        }

        $overriddenFqcn = $namespace . '\\' . $overriddenClass;
        $origContent = file_get_contents($fullPath);

        if ($origContent === false) {
            throw new RuntimeException('Could not read original generated cached file.');
        }

        $codeExtractor = new ContainerSourceCodeExtractor($origContent);
        $overriddenContent = str_replace($class, $overriddenClass, $origContent);
        $overriddenContent = str_replace('self::do(', 'static::do(', $overriddenContent);
        $fs->dumpFile($fullOverriddenPath, $overriddenContent);
        require_once $fullOverriddenPath;
        $reflClass = new ReflectionClass($overriddenFqcn);
        /** @var ReflectionMethod $reflMethod */
        $reflMethod = $reflClass->getMethod('do');
        $codeExtractor = new ContainerSourceCodeExtractor($overriddenContent);
        $internals = $codeExtractor->getContainerInternalsForMethod($reflMethod, true);
        $sharedCheck = '';

        if (!empty($internals)) {
            Assertion::keyExists($internals, 'key');
            Assertion::keyExists($internals, 'type');

            $arrayKey = "['{$internals['key']}']" . (isset($internals['key2']) ? "['{$internals['key2']}']" : '');
            $sharedCheck = <<<EOF
                if (isset(\$container->{$internals['type']}{$arrayKey})) {
                    if (\$lazyLoad === true) {
                        return \$container->{$internals['type']}{$arrayKey};
                    } elseif (\$lazyLoad === false && isset(\$container->lazyInitializedShared['$overriddenClass'])) {
                        return \$container->lazyInitializedShared['$overriddenClass'];
                    }
                }

                EOF;
        }

        $newContent = <<<EOF
            <?php

            namespace $namespace;

            /**
             * @internal This class has been auto-generated by Swoole bundle.
             */
            class $class extends $overriddenClass
            {
                public static function do(\$container, \$lazyLoad = true)
                {
                    if (is_string(\$lazyLoad)) {
                        \$lazyLoad = true;
                    }

                    $sharedCheck

                    try {
                        \$container::\$mutex->acquire();

                        $sharedCheck

                        \$return = parent::do(\$container, \$lazyLoad);
                        if (\$lazyLoad !== true) \$container->lazyInitializedShared['$overriddenClass'] = \$return;
                    } finally {
                        \$container::\$mutex->release();
                    }

                    return \$return;
                }
            }
            EOF;
        $fs->dumpFile($fullPath, $newContent);

        require_once $fullOverriddenPath;
        require_once $fullPath;
    }

    private function getContainerFile(ReflectionClass $container): string
    {
        $file = $container->getFileName();
        if (!is_string($file) || !is_file($file)) {
            throw new RuntimeException('The reflected generated container source file is missing.');
        }

        $resolved = realpath($file);
        if ($resolved === false) {
            throw new RuntimeException(sprintf('Could not resolve generated container source "%s".', $file));
        }

        return $resolved;
    }

    private function isAlreadyOverridden(string $file, string $parentClass): bool
    {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException(sprintf('Could not read generated source "%s".', $file));
        }

        return preg_match('/\\bclass\\s+\\w+\\s+extends\\s+' . preg_quote($parentClass, '/') . '\\b/', $source) === 1;
    }

    /**
     * @return array<string>
     */
    private function getIgnoredGetters(): array
    {
        $reflBlockingContainer = new ReflectionClass(BlockingContainer::class);
        $methods = $reflBlockingContainer->getMethods(CoreReflectionMethod::IS_PROTECTED);
        $methodNames = array_map(static fn(ReflectionMethod $method): string => $method->getName(), $methods);
        $methodNames = array_merge($methodNames, get_class_methods(BlockingContainer::class));
        $getters = array_filter(
            $methodNames,
            static fn(string $methodName): bool => str_starts_with($methodName, 'get'),
        );
        $getters[] = 'getDefaultParameters';

        return array_flip($getters);
    }
}
