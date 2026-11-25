<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\NestedCallPipeOperator;

use ArrayObject;

interface Client
{
    public function send(string $payload): void;
}

final class Chains
{
    public ?Client $client = null;

    /** @param list<array<string, string>> $rows */
    public function functionChain(array $rows): array
    {
        return array_values(array_unique(array_column($rows, 'k')));
    }

    public function methodOnTop(string $path): void
    {
        $this->assertSame(
            1,
            json_decode(file_get_contents($path), true),
        );
    }

    public function staticCallOnTop(array $roles): array
    {
        return self::rolesFor(array_values(array_unique($roles)));
    }

    public function nullsafeMethodOnTop(string $s): void
    {
        $this->client?->send(strtoupper(trim(strrev($s))));
    }

    public function fiveLevels(string $s): string
    {
        return strtoupper(trim(strrev(strtolower(ltrim($s)))));
    }

    public function argumentLessInnermostCall(): string
    {
        return strtoupper(trim(php_uname()));
    }

    public function pipeArgumentKeepsTheDepth(string $s): string
    {
        return strtoupper(strtolower(trim($s |> ltrim(...))));
    }

    public function assertChain(string $path): void
    {
        assert(is_array(json_decode(file_get_contents($path), true)));
    }

    public function tenLevels(string $s): string
    {
        return strrev(strrev(strrev(strrev(strrev(strrev(strrev(strrev(strrev(strrev($s))))))))));
    }

    public function elevenLevels(string $s): string
    {
        return strrev(strrev(strrev(strrev(strrev(strrev(strrev(strrev(strrev(strrev(strrev($s)))))))))));
    }

    public function twoLevels(): string
    {
        return bin2hex(random_bytes(4));
    }

    public function pipes(string $s): array
    {
        return [
            random_bytes(4) |> bin2hex(...),
            $s
                |> trim(...)
                |> (static fn (string $v): string => str_pad($v, 8, '0'))
                |> strtoupper(...),
        ];
    }

    public function pipeArgumentIsNotACallLevel(string $s): string
    {
        return strtolower(trim($s |> ltrim(...)));
    }

    public function twoCallArgumentsInOneList(array $a, array $b): array
    {
        return array_merge(array_values(array_unique($a)), array_keys($b));
    }

    public function newOwnsTheTopArgumentList(array $values): ArrayObject
    {
        return new ArrayObject(array_values(array_unique(array_keys($values))));
    }

    private function assertSame(mixed $expected, mixed $actual): void
    {
    }

    private static function rolesFor(array $roles): array
    {
        return $roles;
    }
}
