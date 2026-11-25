<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Interface\Request;

use App\Activity\Interface\Request\PlayActivityRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class PlayActivityRequestTest extends TestCase
{
    public function testPlatformAndPlayerAreBoundedTo64Characters(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertCount(0, $validator->validate(new PlayActivityRequest(platform: str_repeat('ø', 64), player: str_repeat('ø', 64))));

        $paths = [];
        foreach ($validator->validate(new PlayActivityRequest(platform: str_repeat('a', 65), player: str_repeat('a', 65))) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        self::assertSame(['platform', 'player'], $paths);
    }
}
