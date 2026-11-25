<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Request;

use App\Media\Interface\Request\DeliveryRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class DeliveryRequestBoundsTest extends TestCase
{
    public function testEveryCapabilityNameIsAStringOfAtMost32Characters(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $name = str_repeat('a', 32);

        self::assertCount(0, $validator->validate(new DeliveryRequest(
            'video',
            'aB3dE5fG7hJ9kL1mN3pQr',
            [$name],
            videoCodecs: [$name],
            audioCodecs: [$name],
            transports: ['hls', 'dash'],
            pixelFormats: [$name],
        )));

        $paths = [];
        foreach ($validator->validate(new DeliveryRequest(
            'video',
            'aB3dE5fG7hJ9kL1mN3pQr',
            [$name . 'a'],
            videoCodecs: [$name . 'a'],
            audioCodecs: self::decoded('[["nested"]]'),
            transports: [str_repeat('file', 9)],
            pixelFormats: [$name . 'a'],
        )) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        self::assertSame(
            ['containers[0]', 'videoCodecs[0]', 'audioCodecs[0]', 'transports[0]', 'pixelFormats[0]'],
            array_values(array_unique($paths)),
        );
    }

    /** A request body value as the JSON decoder hands it over, before validation. */
    private static function decoded(string $json): mixed
    {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
