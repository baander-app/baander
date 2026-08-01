<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\PublicId;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class PublicIdNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        return $object->toString();
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): PublicId
    {
        if (!is_string($data)) {
            throw new NotNormalizableValueException(sprintf(
                'The data is not a valid string, got "%s".',
                get_debug_type($data),
            ));
        }

        return PublicId::fromString($data);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof PublicId;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === PublicId::class;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            PublicId::class => true,
        ];
    }
}
