<?php

declare(strict_types=1);

namespace Baander\Images\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The operator guide installs the production images through the deployment preset. A development
 * build or the development stack's `app` service in an operator page would hand an operator the
 * stack that runs with APP_ENV=dev, a privileged container and published database ports.
 */
final class OperatorGuideBuildTest extends TestCase
{
    /** @var array<string,string> rule name => pattern */
    private const array RULES = [
        'development image build' => '/--target(?:\s+|=)dev(?![\w-])/',
        'development stack app service' => '/\bdc\s+exec\s+(?:-[A-Za-z]+\s+)*app\b/',
    ];

    public function testOperatorPagesNameNeitherADevelopmentBuildNorTheDevelopmentAppService(): void
    {
        $root = dirname(__DIR__, 3);
        $violations = [];
        foreach (self::operatorPages($root) as $path) {
            foreach (self::violations((string) file_get_contents($path)) as $violation) {
                $violations[] = substr($path, strlen($root) + 1) . ': ' . $violation;
            }
        }
        self::assertSame([], $violations);
    }

    /** @return iterable<string,array{string,list<string>}> */
    public static function pages(): iterable
    {
        yield 'development build' => ["php scripts/build-application.php --target dev --tag baander:local\n", ['development image build']];
        yield 'development build with equals' => ["build-application.php --target=dev\n", ['development image build']];
        yield 'console in the development app' => ["dc exec app php bin/console app:user:list\n", ['development stack app service']];
        yield 'console without a terminal in the development app' => ["dc exec -T app php bin/console app:health:check\n", ['development stack app service']];
        yield 'operator image build' => ["php scripts/build-application.php --target dev-operator --tag baander-operator:local\n", []];
        yield 'console in the preset web service' => ["dc exec web php bin/console app:user:list\ndc exec -T web php bin/console app:health:check\n", []];
    }

    /** @param list<string> $expected */
    #[DataProvider('pages')]
    public function testRulesRecognizeDevelopmentInstructions(string $page, array $expected): void
    {
        self::assertSame($expected, array_map(
            static fn(string $violation): string => explode(': ', $violation, 2)[0],
            self::violations($page),
        ));
    }

    /** @return list<string> */
    private static function operatorPages(string $root): array
    {
        $pages = [$root . '/deploy/preset/README.md'];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/docs-book/part-1-operator-guide', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $pages[] = $file->getPathname();
            }
        }
        sort($pages);
        return $pages;
    }

    /** @return list<string> each as "rule: matched text" */
    private static function violations(string $page): array
    {
        $violations = [];
        foreach (self::RULES as $rule => $pattern) {
            preg_match_all($pattern, $page, $matches);
            foreach ($matches[0] as $match) {
                $violations[] = $rule . ': ' . $match;
            }
        }
        return $violations;
    }
}
