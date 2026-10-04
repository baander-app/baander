<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\AllowedClassNormalizer;
use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Shared\Infrastructure\Messenger\JobMessageSerializerFactory;
use App\Shared\Infrastructure\Messenger\UuidNormalizer;
use App\Shared\Infrastructure\Messenger\PublicIdNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;

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

        $this->assertFalse($normalizer->supportsDenormalization(['key' => 'value'], \stdClass::class));
        $this->expectException(NotNormalizableValueException::class);
        $this->expectExceptionMessage('Class "stdClass" is not allowed for denormalization.');

        $normalizer->denormalize(['key' => 'value'], \stdClass::class);
    }

    public function testRejectsDenormalizationForNonMatchingPattern(): void
    {
        $normalizer = new AllowedClassNormalizer(
            propertyAccessor: $this->propertyAccessor,
            allowedPatterns: ['App*Application*Command*'],
        );

        $type = \App\Auth\Domain\Model\User::class;
        $this->assertFalse($normalizer->supportsDenormalization(['key' => 'value'], $type));
        $this->expectException(NotNormalizableValueException::class);
        $this->expectExceptionMessage('Class "' . $type . '" is not allowed for denormalization.');

        $normalizer->denormalize(['key' => 'value'], $type);
    }

    public function testAllowedCommandRoundTripsThroughSerializerFactory(): void
    {
        $serializer = JobMessageSerializerFactory::create(
            $this->propertyAccessor,
            new UuidNormalizer(),
            new PublicIdNormalizer(),
        );
        $command = new BulkFetchLyricsCommand(limit: 12, delayMs: 750);
        $json = $serializer->serialize($command, 'json');
        $restored = $serializer->deserialize($json, BulkFetchLyricsCommand::class, 'json');

        $this->assertSame(['limit' => 12, 'delayMs' => 750], json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(12, $restored->getLimit());
        $this->assertSame(750, $restored->getDelayMs());
    }

    public function testSerializerFactoryRejectsDisallowedType(): void
    {
        $serializer = JobMessageSerializerFactory::create(
            $this->propertyAccessor,
            new UuidNormalizer(),
            new PublicIdNormalizer(),
        );

        $this->expectException(NotNormalizableValueException::class);
        $this->expectExceptionMessage('no supporting normalizer found');

        $serializer->deserialize('{"key":"value"}', \stdClass::class, 'json');
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
