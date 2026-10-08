<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\AdminCliParity\ParityFixtureController;
use App\Tests\Support\AdminCliParity\AdminCliParityChecker;
use App\Tests\Support\AdminCliParity\AdminRouteClassifier;
use App\Tests\Support\Console\CommandDocsLocator;
use App\Tests\Support\Console\ProjectCommands;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every admin-guarded route has a console command or a recorded exemption.
 *
 * Mark the controller method with `#[CliCounterpart('app:...')]` or
 * `#[CliParityExemption('reason')]` from `App\Shared\Interface\Attribute`.
 */
final class AdminCliParityTest extends KernelTestCase
{
    /** Non-admin routes the admin pages call; they need a marking like admin routes. */
    public const array ADMIN_PAGE_ROUTES = [
        'genre_index',
        'library_index',
        'library_show',
        'library_stats',
        'radio_countries',
        'radio_stations',
        'transcode_session_index',
    ];

    private const array FIXTURE_ACCESS_CONTROL = [
        ['path' => '^/api/admin', 'roles' => 'ROLE_ADMIN'],
        ['path' => '^/api/monitor', 'roles' => 'ROLE_ADMIN'],
        ['path' => '^/api/', 'roles' => 'IS_AUTHENTICATED_FULLY'],
    ];

    public function test_every_admin_route_has_a_counterpart_or_an_exemption(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router.default');
        self::assertInstanceOf(RouterInterface::class, $router);

        $projectCommands = ProjectCommands::inDirectory(self::$kernel->getProjectDir() . '/src');
        $commands = [];
        foreach (array_keys((new Application(self::$kernel))->all()) as $name) {
            $commands[$name] = isset($projectCommands[$name]);
        }

        $checker = new AdminCliParityChecker(
            AdminRouteClassifier::fromSecurityConfig(self::$kernel->getProjectDir() . '/config/packages/security.yaml'),
            CommandDocsLocator::forProject(),
            $commands,
        );
        $violations = $checker->violations(
            $router->getRouteCollection(),
            self::ADMIN_PAGE_ROUTES,
            $this->markedClasses(self::$kernel->getProjectDir() . '/src'),
        );

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function test_an_unmarked_admin_route_fails_with_its_name(): void
    {
        $violations = $this->fixtureViolations(['fixture_unmarked' => $this->route('/api/things', 'unmarked')]);

        self::assertCount(1, $violations);
        self::assertStringContainsString('"fixture_unmarked"', $violations[0]);
        self::assertStringContainsString('neither #[CliCounterpart] nor #[CliParityExemption]', $violations[0]);
    }

    public function test_a_counterpart_naming_a_missing_command_fails(): void
    {
        $violations = $this->fixtureViolations(['fixture_unknown' => $this->route('/api/things', 'unknownCommand')]);

        self::assertSame(['Route "fixture_unknown" (POST /api/things) names console command "app:fixture:missing", which does not exist.'], $violations);
    }

    public function test_an_exemption_on_a_method_no_route_reaches_fails(): void
    {
        $violations = $this->fixtureViolations([], markedClasses: [ParityFixtureController::class]);

        self::assertContains(
            ParityFixtureController::class . '::removedRoute() carries #[CliParityExemption] but no route reaches it; remove the attribute.',
            $violations,
        );
    }

    public function test_access_control_alone_makes_a_monitor_route_admin_guarded(): void
    {
        $classifier = new AdminRouteClassifier(self::FIXTURE_ACCESS_CONTROL);

        self::assertTrue($classifier->isAdminGuarded($this->route('/api/monitor/things', 'unguarded', 'GET')));
        self::assertFalse($classifier->isAdminGuarded($this->route('/api/things', 'unguarded', 'GET')));
        self::assertNotSame([], $this->fixtureViolations(['fixture_monitor' => $this->route('/api/monitor/things', 'unguarded', 'GET')]));
    }

    public function test_a_method_level_super_admin_grant_makes_a_route_admin_guarded(): void
    {
        $classifier = new AdminRouteClassifier(self::FIXTURE_ACCESS_CONTROL);

        self::assertTrue($classifier->isAdminGuarded($this->route('/api/things', 'superAdminOnly')));
        self::assertFalse($classifier->isAdminGuarded($this->route('/api/things', 'authenticated')));
    }

    public function test_a_grant_naming_an_unclassified_attribute_fails(): void
    {
        $violations = $this->fixtureViolations(['fixture_unclassified' => $this->route('/api/things', 'unclassified')]);

        self::assertCount(1, $violations);
        self::assertStringContainsString('"FIXTURE_UNKNOWN_ATTRIBUTE" is not classified', $violations[0]);
    }

    public function test_a_declared_admin_page_route_must_be_marked(): void
    {
        $routes = ['library_index' => $this->route('/api/libraries', 'unguarded', 'GET')];

        self::assertSame([], $this->fixtureViolations($routes));
        $violations = $this->fixtureViolations($routes, declared: ['library_index']);
        self::assertCount(1, $violations);
        self::assertStringContainsString('"library_index"', $violations[0]);
    }

    public function test_a_counterpart_without_a_docs_page_fails_naming_the_command(): void
    {
        $violations = $this->fixtureViolations([
            'fixture_undocumented' => $this->route('/api/things', 'undocumented'),
            'fixture_framework' => $this->route('/api/admin/failed', 'frameworkCommand'),
        ]);

        self::assertSame(['Console command "app:fixture:undocumented", the counterpart of Route "fixture_undocumented" (POST /api/things), has no operator docs page.'], $violations);
    }

    /**
     * @param array<string, Route> $routes
     * @param list<string>         $declared
     * @param list<class-string>   $markedClasses
     *
     * @return list<string>
     */
    private function fixtureViolations(array $routes, array $declared = [], array $markedClasses = []): array
    {
        $collection = new RouteCollection();
        foreach ($routes as $name => $route) {
            $collection->add($name, $route);
        }

        $checker = new AdminCliParityChecker(
            new AdminRouteClassifier(self::FIXTURE_ACCESS_CONTROL),
            new CommandDocsLocator(dirname(__DIR__) . '/Fixtures/AdminCliParity/commands'),
            ['app:fixture:documented' => true, 'app:fixture:undocumented' => true, 'messenger:failed:retry' => false],
        );

        return $checker->violations($collection, $declared, $markedClasses);
    }

    private function route(string $path, string $action, string $method = 'POST'): Route
    {
        return new Route($path, ['_controller' => ParityFixtureController::class . '::' . $action], methods: [$method]);
    }

    /**
     * Classes under src/ that use a parity attribute, so a marking left behind on
     * a method without a route is found.
     *
     * @return list<class-string>
     */
    private function markedClasses(string $sourceDirectory): array
    {
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Shared/Interface/Attribute/')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, 'CliCounterpart') && !str_contains($source, 'CliParityExemption')) {
                continue;
            }
            $class = 'App\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($sourceDirectory) + 1, -4));
            self::assertTrue(class_exists($class), $class . ' uses a parity attribute but cannot be autoloaded.');
            $classes[] = $class;
        }
        sort($classes);

        return $classes;
    }
}
