<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Validator;

use App\Shared\Interface\Validator\JsonEncodedSize;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class JsonEncodedSizeValidatorTest extends TestCase
{
    public function testTheCompactUnescapedEncodingIsMeasured(): void
    {
        $validator = Validation::createValidator();
        // {"path":"/ø"} is 14 bytes with slashes and UTF-8 unescaped; escaped it would be 20.
        $value = ['path' => '/ø'];

        self::assertCount(0, $validator->validate($value, new JsonEncodedSize(max: 14)));
        self::assertCount(1, $validator->validate($value, new JsonEncodedSize(max: 13)));
        self::assertCount(0, $validator->validate(null, new JsonEncodedSize(max: 1)));
    }

    public function testAValueThatCannotBeEncodedIsRefused(): void
    {
        $violations = Validation::createValidator()->validate(['gain' => INF], new JsonEncodedSize(max: 16384));

        self::assertCount(1, $violations);
        self::assertSame('This value cannot be encoded as JSON.', $violations[0]->getMessage());
    }
}
