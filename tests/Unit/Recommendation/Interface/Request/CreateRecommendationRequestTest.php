<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Interface\Request;

use App\Recommendation\Interface\Request\CreateRecommendationRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CreateRecommendationRequestTest extends TestCase
{
    public function testAnEmptyRequestNamesEveryRequiredField(): void
    {
        self::assertSame(
            ['score', 'source_id', 'source_type', 'target_id', 'target_type'],
            $this->violatedFields(new CreateRecommendationRequest()),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function valuesOutsideTheirBounds(): iterable
    {
        yield '256-character name' => [['name' => str_repeat('n', 256)], 'name'];
        yield 'infinite score' => [['score' => INF], 'score'];
        yield 'negative infinite score' => [['score' => -INF], 'score'];
        yield 'score that is not a number' => [['score' => NAN], 'score'];
        yield 'score above one' => [['score' => 1.000001], 'score'];
        yield 'negative score' => [['score' => -0.000001], 'score'];
        yield 'position above the stored integer' => [['position' => 2_147_483_648], 'position'];
        yield 'position below the stored integer' => [['position' => -2_147_483_649], 'position'];
        yield 'unknown source type' => [['source_type' => 'playlist'], 'source_type'];
        yield 'upper-case target type' => [['target_type' => 'SONG'], 'target_type'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('valuesOutsideTheirBounds')]
    public function testAValueOutsideItsBoundIsRefused(array $override, string $field): void
    {
        self::assertSame([$field], $this->violatedFields($this->request($override)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function valuesAtTheirBounds(): iterable
    {
        yield 'lowest score' => [['score' => 0.0]];
        yield 'highest score' => [['score' => 1.0]];
        yield '255 multibyte characters of name' => [['name' => str_repeat('ø', 255)]];
        yield 'lowest position' => [['position' => -2_147_483_648]];
        yield 'highest position' => [['position' => 2_147_483_647]];
        yield 'no position' => [['position' => null]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('valuesAtTheirBounds')]
    public function testAValueAtItsBoundIsAccepted(array $override): void
    {
        self::assertSame([], $this->violatedFields($this->request($override)));
    }

    /** @param array<string, mixed> $override */
    private function request(array $override): CreateRecommendationRequest
    {
        return new CreateRecommendationRequest(...[
            'source_type' => 'song',
            'source_id' => 'V1StGXR8_Z5jdHi6B-myT',
            'target_type' => 'album',
            'target_id' => 'Uakgb_J5m9g-0JDMbcJqL',
            'score' => 0.5,
            ...$override,
        ]);
    }

    /** @return list<string> */
    private function violatedFields(CreateRecommendationRequest $request): array
    {
        $fields = [];
        foreach ($this->validator()->validate($request) as $violation) {
            $fields[] = $violation->getPropertyPath();
        }
        $fields = array_values(array_unique($fields));
        sort($fields);

        return $fields;
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
