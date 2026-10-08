<?php

declare(strict_types=1);

namespace App\Tests\Support\AdminCliParity;

use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Attribute\CliParityExemption;
use App\Tests\Support\Console\CommandDocsLocator;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Lists the ways a route collection breaks admin/CLI parity.
 *
 * Every admin-guarded route, and every declared non-admin route the admin pages
 * call, needs `#[CliCounterpart]` or `#[CliParityExemption]` on its controller
 * method. A counterpart must name an existing command, and a project command must
 * have an operator docs page. Declared entries must name live routes, and a
 * marking must sit on a method that a route reaches.
 */
final readonly class AdminCliParityChecker
{
    /**
     * @param array<string, bool> $commands console command name => whether it needs an
     *                                      operator docs page (false for framework commands)
     */
    public function __construct(
        private AdminRouteClassifier $classifier,
        private CommandDocsLocator $docs,
        private array $commands,
    ) {
    }

    /**
     * @param list<string>       $declaredRoutes non-admin routes the admin pages call
     * @param list<class-string> $markedClasses  classes whose methods carry a marking
     *
     * @return list<string> one message per violation
     */
    public function violations(RouteCollection $routes, array $declaredRoutes, array $markedClasses): array
    {
        $violations = [];
        $routedMethods = [];

        foreach ($routes as $name => $route) {
            $method = AdminRouteClassifier::controllerMethod($route);
            if ($method !== null) {
                $routedMethods[$method->class . '::' . $method->name] = true;
            }

            try {
                $required = in_array($name, $declaredRoutes, true) || $this->classifier->isAdminGuarded($route);
            } catch (\UnexpectedValueException $e) {
                $violations[] = sprintf('Route "%s": %s', $name, $e->getMessage());
                continue;
            }
            if (!$required) {
                continue;
            }
            $violations = [...$violations, ...$this->routeViolations($name, $route, $method)];
        }

        foreach ($declaredRoutes as $name) {
            if ($routes->get($name) === null) {
                $violations[] = sprintf('Declared admin-page route "%s" does not exist; remove it from the declared list.', $name);
            }
        }

        foreach ($markedClasses as $class) {
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                $marking = $this->marking($method);
                if ($marking !== null && !isset($routedMethods[$method->class . '::' . $method->name])) {
                    $violations[] = sprintf(
                        '%s::%s() carries #[%s] but no route reaches it; remove the attribute.',
                        $method->class,
                        $method->name,
                        (new \ReflectionClass($marking))->getShortName(),
                    );
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function routeViolations(string $name, Route $route, ?\ReflectionMethod $method): array
    {
        $label = sprintf('Route "%s" (%s %s)', $name, implode('|', $route->getMethods()) ?: 'ANY', $route->getPath());
        $marking = $method === null ? null : $this->marking($method);

        if ($marking === null) {
            return [$method === null
                ? sprintf('%s needs a CLI parity marking, but its controller is not a class method that can carry one.', $label)
                : sprintf('%s has neither #[CliCounterpart] nor #[CliParityExemption] on %s::%s().', $label, $method->class, $method->name)];
        }

        $violations = [];

        if ($marking instanceof CliParityExemption) {
            if (trim($marking->reason) === '') {
                $violations[] = sprintf('%s has an exemption without a reason.', $label);
            }

            return $violations;
        }

        if (!array_key_exists($marking->command, $this->commands)) {
            $violations[] = sprintf('%s names console command "%s", which does not exist.', $label, $marking->command);
        } elseif ($this->commands[$marking->command] && $this->docs->pageFor($marking->command) === null) {
            $violations[] = sprintf('Console command "%s", the counterpart of %s, has no operator docs page.', $marking->command, $label);
        }

        return $violations;
    }

    private function marking(\ReflectionMethod $method): CliCounterpart|CliParityExemption|null
    {
        foreach ([CliCounterpart::class, CliParityExemption::class] as $class) {
            $attributes = $method->getAttributes($class);
            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        }

        return null;
    }
}
