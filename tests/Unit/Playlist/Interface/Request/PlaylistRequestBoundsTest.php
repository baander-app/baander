<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Interface\Request;

use App\Playlist\Interface\Request\CreatePlaylistRequest;
use App\Playlist\Interface\Request\ReorderSongsRequest;
use App\Playlist\Interface\Request\UpdatePlaylistRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PlaylistRequestBoundsTest extends TestCase
{
    public function testNamesAndDescriptionsAtTheirBoundsAreAccepted(): void
    {
        self::assertSame([], $this->violations(new CreatePlaylistRequest(
            name: str_repeat('ø', 255),
            description: str_repeat('ø', 2000),
        )));
        self::assertSame([], $this->violations(new UpdatePlaylistRequest(
            name: str_repeat('ø', 255),
            description: str_repeat('ø', 2000),
        )));
    }

    public function testCreateRefusesANameOrDescriptionOverItsBound(): void
    {
        self::assertSame(['name', 'description'], $this->violations(new CreatePlaylistRequest(
            name: str_repeat('a', 256),
            description: str_repeat('a', 3_000_000),
        )));
    }

    public function testUpdateRefusesANameOrDescriptionOverItsBound(): void
    {
        self::assertSame(['name'], $this->violations(new UpdatePlaylistRequest(name: str_repeat('a', 256))));
        self::assertSame(['description'], $this->violations(new UpdatePlaylistRequest(description: str_repeat('a', 2001))));
    }

    public function testFiftySmartRulesOfTheWebClientsShapeAreAccepted(): void
    {
        $rules = [
            ...array_fill(0, 49, ['field' => 'genre', 'operator' => 'equals', 'value' => str_repeat('ø', 255)]),
            ['field' => 'year', 'operator' => 'is_empty'],
        ];

        self::assertSame([], $this->violations(new CreatePlaylistRequest(name: 'Smart', isSmart: true, smartRules: $rules)));
    }

    /** @return iterable<string, array{list<mixed>|array<string, mixed>, string}> */
    public static function invalidSmartRules(): iterable
    {
        $rule = ['field' => 'genre', 'operator' => 'equals', 'value' => 'Jazz'];
        yield '51 rules' => [array_fill(0, 51, $rule), 'smartRules'];
        yield 'unknown field' => [[['field' => 'path', 'operator' => 'equals', 'value' => '/']], 'smartRules[0][field]'];
        yield 'unknown operator' => [[['field' => 'genre', 'operator' => 'matches', 'value' => '.*']], 'smartRules[0][operator]'];
        yield 'value over its bound' => [[['field' => 'genre', 'operator' => 'equals', 'value' => str_repeat('a', 256)]], 'smartRules[0][value]'];
        yield 'value of another type' => [[['field' => 'year', 'operator' => 'equals', 'value' => ['nested' => true]]], 'smartRules[0][value]'];
        yield 'extra key' => [[[...$rule, 'note' => str_repeat('a', 10_000)]], 'smartRules[0][note]'];
        yield 'missing operator' => [[['field' => 'genre']], 'smartRules[0][operator]'];
        yield 'rule that is not an object' => [['genre'], 'smartRules[0]'];
        yield 'object instead of a list' => [['first' => $rule], 'smartRules'];
    }

    /** @param list<mixed>|array<string, mixed> $rules */
    #[DataProvider('invalidSmartRules')]
    public function testSmartRulesOutsideTheWebClientsShapeAreRefused(array $rules, string $path): void
    {
        self::assertContains($path, $this->violations(new CreatePlaylistRequest(name: 'Smart', isSmart: true, smartRules: $rules)));
    }

    public function testReorderListIsBoundedLikeAPlaylist(): void
    {
        $revision = str_repeat('a', 64);
        $id = 'aB3dE5fG7hJ9kL1mN3pQr';

        self::assertSame([], $this->violations(new ReorderSongsRequest(array_fill(0, 10_000, $id), $revision)));
        self::assertSame(['songIds'], $this->violations(new ReorderSongsRequest(array_fill(0, 10_001, $id), $revision)));
    }

    /** @return list<string> the property paths of the violations, in order */
    private function violations(object $request): array
    {
        $paths = [];
        foreach ($this->validator()->validate($request) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return array_values(array_unique($paths));
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
