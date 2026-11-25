<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Interface\Request;

use App\Radio\Interface\Request\StartRadioRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class StartRadioRequestTest extends TestCase
{
    public function testStreamUrlIsBoundedTo2048Characters(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $station = 'aB3dE5fG7hJ9kL1mN3pQr';
        $prefix = 'https://radio.baander.app/stream/';

        self::assertCount(0, $validator->validate(new StartRadioRequest($station, $prefix . str_repeat('a', 2048 - strlen($prefix)))));

        $violations = $validator->validate(new StartRadioRequest($station, $prefix . str_repeat('a', 2049 - strlen($prefix))));
        self::assertCount(1, $violations);
        self::assertSame('streamUrl', $violations[0]->getPropertyPath());
    }
}
