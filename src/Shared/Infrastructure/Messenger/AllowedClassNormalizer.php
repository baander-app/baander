<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use ArrayObject;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class AllowedClassNormalizer implements NormalizerInterface, DenormalizerInterface, SerializerAwareInterface
{
    private readonly ObjectNormalizer $inner;

    /**
     * @param string[] $allowedPatterns
     */
    public function __construct(
        PropertyAccessorInterface $propertyAccessor,
        private readonly array $allowedPatterns,
    )
    {
        $this->inner = new ObjectNormalizer(propertyAccessor: $propertyAccessor);
    }

    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->inner->setSerializer($serializer);
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|ArrayObject|null
    {
        if (!$this->isClassAllowed($data::class)) {
            return null;
        }

        return $this->inner->normalize($data, $format, $context);
    }

    private function isClassAllowed(string $className): bool
    {
        foreach ($this->allowedPatterns as $pattern) {
            if (fnmatch($pattern, $className)) {
                return true;
            }
        }

        return false;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (!$this->isClassAllowed($type)) {
            return null;
        }

        return $this->inner->denormalize($data, $type, $format, $context);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return is_object($data) && $this->isClassAllowed($data::class);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $this->isClassAllowed($type);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            '*' => false,
        ];
    }
}
