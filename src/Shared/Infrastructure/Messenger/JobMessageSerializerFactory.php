<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerAwareInterface;

final class JobMessageSerializerFactory
{
    public static function create(
        PropertyAccessorInterface $propertyAccessor,
        UuidNormalizer $uuidNormalizer,
        PublicIdNormalizer $publicIdNormalizer,
    ): Serializer {
        $normalizer = new AllowedClassNormalizer($propertyAccessor, [
            'App*Application*Command*',
        ]);

        $serializer = new Serializer(
            normalizers: [
                $normalizer,
                $uuidNormalizer,
                $publicIdNormalizer,
                new BackedEnumNormalizer(),
                new ArrayDenormalizer(),
            ],
            encoders: [
                new JsonEncoder(),
            ],
        );

        // The Serializer constructor already injects itself via setSerializer()
        // on normalizers implementing SerializerAwareInterface. However, since
        // AllowedClassNormalizer creates ObjectNormalizer internally, we need
        // to explicitly propagate the serializer to the inner normalizer.
        if ($normalizer instanceof SerializerAwareInterface) {
            $normalizer->setSerializer($serializer);
        }

        return $serializer;
    }
}
