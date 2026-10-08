<?php

declare(strict_types=1);

namespace App\Tests\Support\AdminCliParity;

use App\Auth\Infrastructure\Security\Voter\AdminVoter;
use App\Auth\Infrastructure\Security\Voter\UserManagementVoter;
use Symfony\Component\Routing\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

/**
 * Decides whether a route is admin-guarded.
 *
 * A route is admin-guarded when, for any of its HTTP methods, the first
 * matching `access_control` rule requires an admin role, or an `#[IsGranted]`
 * on the controller class or method that applies to that method names an admin
 * role or admin voter attribute. Inline checks in the action body are invisible
 * here; admin-only checks belong in `#[IsGranted]`.
 */
final readonly class AdminRouteClassifier
{
    /** Roles and voter attributes only an admin can be granted. */
    public const array ADMIN_ATTRIBUTES = [
        'ROLE_ADMIN',
        'ROLE_SUPER_ADMIN',
        AdminVoter::ADMIN_ACCESS,
        AdminVoter::SYSTEM_SETTINGS,
        UserManagementVoter::LIST_USERS,
        UserManagementVoter::CREATE_USER,
    ];

    /** Attributes a non-admin can be granted. Anything else must be classified before the test passes. */
    public const array NON_ADMIN_ATTRIBUTES = [
        'PUBLIC_ACCESS',
        'IS_AUTHENTICATED',
        'IS_AUTHENTICATED_FULLY',
        'ROLE_USER',
    ];

    /**
     * @param list<array{path: string, methods?: list<string>|string, roles?: list<string>|string}> $accessControl
     *        rules in configuration order, as under `security.access_control`
     */
    public function __construct(
        private array $accessControl,
    ) {
    }

    public static function fromSecurityConfig(string $securityYaml): self
    {
        /** @var array{security: array{access_control: list<array{path: string, methods?: list<string>|string, roles?: list<string>|string}>}} $config */
        $config = Yaml::parseFile($securityYaml);

        return new self($config['security']['access_control']);
    }

    /**
     * @throws \UnexpectedValueException when a guard names an attribute that is neither
     *                                   a known admin nor a known non-admin attribute
     */
    public function isAdminGuarded(Route $route): bool
    {
        $grants = $this->isGrantedAttributes($route);
        $methods = $route->getMethods() !== [] ? $route->getMethods() : ['*'];

        foreach ($methods as $method) {
            if ($this->accessControlRequiresAdmin($route->getPath(), $method)) {
                return true;
            }
            foreach ($grants as $grant) {
                if (($grant->methods === [] || $method === '*' || in_array($method, $grant->methods, true))
                    && is_string($grant->attribute)
                    && $this->isAdminAttribute($grant->attribute)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The controller method a route dispatches to, when it is a class method.
     */
    public static function controllerMethod(Route $route): ?\ReflectionMethod
    {
        $target = self::controllerTarget($route);

        return $target === null ? null : new \ReflectionMethod($target[0], $target[1]);
    }

    /** @return array{class-string, string}|null */
    private static function controllerTarget(Route $route): ?array
    {
        $controller = $route->getDefault('_controller');
        if (!is_string($controller)) {
            return null;
        }
        [$class, $method] = str_contains($controller, '::')
            ? explode('::', $controller, 2)
            : [$controller, '__invoke'];

        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        return [$class, $method];
    }

    private function accessControlRequiresAdmin(string $path, string $method): bool
    {
        foreach ($this->accessControl as $rule) {
            $ruleMethods = array_map('strtoupper', (array) ($rule['methods'] ?? []));
            if ($ruleMethods !== [] && $method !== '*' && !in_array($method, $ruleMethods, true)) {
                continue;
            }
            if (preg_match('{' . $rule['path'] . '}', $path) !== 1) {
                continue;
            }

            // Symfony applies only the first matching rule.
            foreach ((array) ($rule['roles'] ?? []) as $role) {
                if ($this->isAdminAttribute($role)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function isAdminAttribute(string $attribute): bool
    {
        if (in_array($attribute, self::ADMIN_ATTRIBUTES, true)) {
            return true;
        }
        if (in_array($attribute, self::NON_ADMIN_ATTRIBUTES, true)) {
            return false;
        }

        throw new \UnexpectedValueException(sprintf(
            'Security attribute "%s" is not classified. Add it to %s::ADMIN_ATTRIBUTES or NON_ADMIN_ATTRIBUTES.',
            $attribute,
            self::class,
        ));
    }

    /** @return list<IsGranted> */
    private function isGrantedAttributes(Route $route): array
    {
        $target = self::controllerTarget($route);
        if ($target === null) {
            return [];
        }

        $attributes = [
            ...(new \ReflectionClass($target[0]))->getAttributes(IsGranted::class),
            ...(new \ReflectionMethod($target[0], $target[1]))->getAttributes(IsGranted::class),
        ];

        return array_map(static fn (\ReflectionAttribute $attribute): IsGranted => $attribute->newInstance(), $attributes);
    }
}
