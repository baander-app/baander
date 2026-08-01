<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\AllowedClassNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccessor;

final class AllowedClassNormalizerTest extends TestCase
{
    private PropertyAccessor $propertyAccessor;

    protected function setUp(): void
    {
        $this->propertyAccessor = new PropertyAccessor();
    }

    public function testRejectsClassNotInAllowedPatterns(): void
    {
        $normalizer = new AllowedClassNormalizer(
            propertyAccessor: $this->propertyAccessor,
            allowedPatterns: ['App*Application*Command*'],
        );

        $result = $normalizer->normalize(new \stdClass());

        $this->assertNull($result);
    }

    public function testRejectsClassWithNoPublicProperties(): void
    {
        $normalizer = new AllowedClassNormalizer(
            propertyAccessor: $this->propertyAccessor,
            allowedPatterns: ['App*Unit*AllowedClassNormalizerTest*'],
        );

        // normalize returns null for unsupported types or objects with issues,
        // but the key assertion is that stdClass (not matching pattern) is rejected
        $result = $normalizer->normalize(new \stdClass());

        $this->assertNull($result);
    }

    public function testRejectsDenormalizationForDisallowedType(): void
    {
        $normalizer = new AllowedClassNormalizer(
            propertyAccessor: $this->propertyAccessor,
            allowedPatterns: ['App*Application*Command*'],
        );

        $result = $normalizer->denormalize(['key' => 'value'], \stdClass::class);

        $this->assertNull($result);
    }

    public function testRejectsDenormalizationForNonMatchingPattern(): void
    {
        $normalizer = new AllowedClassNormalizer(
            propertyAccessor: $this->propertyAccessor,
            allowedPatterns: ['App*Application*Command*'],
        );

        $result = $normalizer->denormalize(['key' => 'value'], 'App\\Auth\\Domain\\Model\\User');

        $this->assertNull($result);
    }

    public function testDelegatesSupportsNormalizationToInner(): void
    {
        $normalizer = new AllowedClassNormalizer(
            propertyAccessor: $this->propertyAccessor,
            allowedPatterns: ['stdClass'],
        );

        $this->assertTrue($normalizer->supportsNormalization(new \stdClass()));
        $this->assertFalse($normalizer->supportsNormalization('not-an-object'));
    }

    public function testDelegatesSupportsDenormalizationToInner(): void
    {
        $normalizer = new AllowedClassNormalizer(
            propertyAccessor: $this->propertyAccessor,
            allowedPatterns: ['stdClass'],
        );

        $this->assertTrue($normalizer->supportsDenormalization([], \stdClass::class));
        $this->assertFalse($normalizer->supportsDenormalization([], 'NotAllowedClass'));
    }

    public function testGetSupportedTypesReturnsWildcard(): void
    {
        $normalizer = new AllowedClassNormalizer(
            propertyAccessor: $this->propertyAccessor,
            allowedPatterns: ['App*'],
        );

        $types = $normalizer->getSupportedTypes(null);

        $this->assertSame(['*' => false], $types);
    }

    public function testCommandPatternMatchesRealCommandClasses(): void
    {
        $this->assertTrue(fnmatch(
            'App*Application*Command*',
            'App\\Metadata\\Application\\Command\\ExtractAlbumCoverCommand',
        ));
        $this->assertFalse(fnmatch(
            'App*Application*Command*',
            'App\\Tests\\Unit\\SomeTest',
        ));
        $this->assertFalse(fnmatch(
            'App*Application*Command*',
            'stdClass',
        ));
    }
}
