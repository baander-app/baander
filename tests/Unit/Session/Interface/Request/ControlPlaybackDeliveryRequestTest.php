<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Interface\Request;

use App\Session\Interface\Request\ControlPlaybackRequest;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ControlPlaybackDeliveryRequestTest extends TestCase
{
    public function testOptionalDeliveryIncarnationIsValidatedAndForwarded(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $requestId = new Uuid()->toString();
        $incarnation = new Uuid()->toString();
        $request = new ControlPlaybackRequest($requestId, 5, 'pause', incarnationId: $incarnation);
        self::assertCount(0, $validator->validate($request));
        self::assertSame($incarnation, $request->toArray()['incarnationId']);
        self::assertArrayNotHasKey('incarnationId', new ControlPlaybackRequest($requestId, 5, 'pause')->toArray());
        self::assertCount(1, $validator->validate(new ControlPlaybackRequest($requestId, 5, 'pause', incarnationId: 'invalid')));
    }
}
