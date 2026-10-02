<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Rules;

use Baander\PHPStan\Rules\MapRequestPayloadObjectTypeRule;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<MapRequestPayloadObjectTypeRule> */
final class MapRequestPayloadObjectTypeRuleTest extends RuleTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/rules-test.neon'];
    }

    protected function getRule(): Rule
    {
        return new MapRequestPayloadObjectTypeRule();
    }

    public function testNodeTypeIsClassMethod(): void
    {
        self::assertSame(ClassMethod::class, $this->getRule()->getNodeType());
    }

    public function testRejectsGenericObjectsAndMissingTypesFromParsedSource(): void
    {
        $objectError = 'Parameter $payload with #[MapRequestPayload] is typed as "object". Use a concrete request DTO class instead.';
        $missingError = 'Parameter $payload with #[MapRequestPayload] must have an explicit type hint (not bare "object").';

        $this->analyse([__DIR__ . '/data/map-request-payload-invalid.php'], [
            [$objectError, 12],
            [$objectError, 16],
            [$objectError, 20],
            [$objectError, 24],
            [$missingError, 28],
            [$missingError, 32],
            [$missingError, 36],
            [$objectError, 40],
            [$objectError, 44],
        ]);
    }

    public function testAcceptsConcreteDtoAndExistingTypedArrayContract(): void
    {
        $this->analyse([__DIR__ . '/data/map-request-payload-valid.php'], []);
    }

    public function testIgnoresUnrelatedAttributesWithTheSameShortName(): void
    {
        $this->analyse([__DIR__ . '/data/map-request-payload-unrelated.php'], []);
    }
}
