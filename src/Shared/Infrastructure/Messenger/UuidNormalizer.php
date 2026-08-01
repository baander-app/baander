<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class UuidNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        return $object->toString();
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Uuid
    {
        if (!is_string($data)) {
            throw new NotNormalizableValueException(sprintf(
                'The data is not a valid string, got "%s".',
                get_debug_type($data),
            ));
        }

        return Uuid::fromString($data);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Uuid;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === Uuid::class;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Uuid::class => true,
        ];
    }
}
