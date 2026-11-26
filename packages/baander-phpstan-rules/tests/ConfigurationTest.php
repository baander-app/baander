<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Both PHPStan configurations load the project rules only through an explicit include, because
 * the package's extra.phpstan entry needs phpstan/extension-installer, which is not installed.
 * The files are read as YAML, which covers the syntax they use; a construct only NEON
 * understands fails the parse or the check, never passes it.
 */
final class ConfigurationTest extends TestCase
{
    private const string INCLUDE = 'vendor/baander/phpstan-rules/extension.neon';

    private ?string $directory = null;

    /** @return iterable<string, array{string}> */
    public static function configurations(): iterable
    {
        yield 'application' => ['phpstan.dist.neon'];
        yield 'tooling' => ['phpstan-tooling.neon'];
    }

    #[DataProvider('configurations')]
    public function testConfigurationIncludesTheRulesExtension(string $configuration): void
    {
        self::assertSame([], self::includeProblems(self::root() . '/' . $configuration));
    }

    public function testExtensionRegistersEveryRuleClass(): void
    {
        self::assertNotSame([], self::ruleClasses());
        self::assertSame([], self::registrationProblems(self::extension()));
    }

    #[DataProvider('configurations')]
    public function testConfigurationWithoutTheIncludeIsReported(string $configuration): void
    {
        $directory = $this->directory();
        // The copies resolve their includes through the same vendor tree as the original, so the
        // removed line is the only difference between the two.
        symlink(self::root() . '/vendor', $directory . '/vendor');
        $source = (string) file_get_contents(self::root() . '/' . $configuration);
        $pattern = '~^\h*-\h*' . preg_quote(self::INCLUDE, '~') . '\h*\n~m';
        $stripped = preg_replace($pattern, '', $source, -1, $removed);
        self::assertSame(1, $removed);
        file_put_contents($directory . '/unchanged.neon', $source);
        file_put_contents($directory . '/' . $configuration, $stripped);

        self::assertSame([], self::includeProblems($directory . '/unchanged.neon'));
        self::assertSame(
            [$directory . '/' . $configuration . ' does not include ' . self::extension()],
            self::includeProblems($directory . '/' . $configuration),
        );
    }

    public function testRuleClassTheExtensionDoesNotRegisterIsReported(): void
    {
        $unregistered = self::ruleClasses()[0];
        $services = array_filter(
            Yaml::parseFile(self::extension())['services'],
            static fn(array $service): bool => $service['class'] !== $unregistered,
        );
        $copy = $this->directory() . '/extension.neon';
        file_put_contents($copy, Yaml::dump(['services' => array_values($services)], 4, 2));

        self::assertSame([$unregistered . ' is not registered by ' . $copy], self::registrationProblems($copy));
    }

    protected function tearDown(): void
    {
        if ($this->directory === null) {
            return;
        }
        foreach (glob($this->directory . '/*') ?: [] as $entry) {
            unlink($entry);
        }
        rmdir($this->directory);
        $this->directory = null;
    }

    /**
     * PHPStan resolves a relative include against the directory of the file that names it.
     *
     * @return list<string>
     */
    private static function includeProblems(string $configuration): array
    {
        $resolved = array_map(
            static fn(string $include): string|false => realpath(dirname($configuration) . '/' . $include),
            Yaml::parseFile($configuration)['includes'] ?? [],
        );

        return in_array(self::extension(), $resolved, true)
            ? []
            : [$configuration . ' does not include ' . self::extension()];
    }

    /** @return list<string> */
    private static function registrationProblems(string $extension): array
    {
        $registered = [];
        foreach (Yaml::parseFile($extension)['services'] ?? [] as $service) {
            if (in_array('phpstan.rules.rule', $service['tags'] ?? [], true)) {
                $registered[] = $service['class'];
            }
        }
        $unregistered = array_diff(self::ruleClasses(), $registered);

        return array_map(
            static fn(string $rule): string => $rule . ' is not registered by ' . $extension,
            array_values($unregistered),
        );
    }

    /** @return list<string> */
    private static function ruleClasses(): array
    {
        return array_map(
            static fn(string $file): string => 'Baander\\PHPStan\\Rules\\' . basename($file, '.php'),
            glob(dirname(__DIR__) . '/src/Rules/*Rule.php') ?: [],
        );
    }

    private static function extension(): string
    {
        return (string) realpath(dirname(__DIR__) . '/extension.neon');
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function directory(): string
    {
        if ($this->directory === null) {
            $this->directory = sys_get_temp_dir() . '/baander.app-phpstan-configuration-' . bin2hex(random_bytes(8));
            mkdir($this->directory, 0o700);
        }

        return $this->directory;
    }
}
