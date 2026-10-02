<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Interface\Request;

use App\Notification\Interface\Request\UpdatePreferenceRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Validation;

final class UpdatePreferenceRequestTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function booleanPayloads(): iterable
    {
        yield 'enable' => ['true'];
        yield 'disable' => ['false'];
    }

    #[DataProvider('booleanPayloads')]
    public function testBothJsonBooleansAreAccepted(string $enabled): void
    {
        $request = $this->decodeRequest($enabled);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertCount(0, $validator->validate($request));
        self::assertSame($enabled === 'true', $request->preferences[0]['enabled']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidEnabledPayloads(): iterable
    {
        yield 'null' => ['null', NotNull::IS_NULL_ERROR];
        yield 'integer zero' => ['0', Type::INVALID_TYPE_ERROR];
        yield 'integer one' => ['1', Type::INVALID_TYPE_ERROR];
        yield 'float' => ['1.0', Type::INVALID_TYPE_ERROR];
        yield 'string true' => ['"true"', Type::INVALID_TYPE_ERROR];
        yield 'string false' => ['"false"', Type::INVALID_TYPE_ERROR];
        yield 'empty string' => ['""', Type::INVALID_TYPE_ERROR];
        yield 'array' => ['[]', Type::INVALID_TYPE_ERROR];
        yield 'object' => ['{}', Type::INVALID_TYPE_ERROR];
    }

    #[DataProvider('invalidEnabledPayloads')]
    public function testNullAndNonBooleanValuesAreRejected(string $enabled, string $expectedCode): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = $validator->validate($this->decodeRequest($enabled));
        $codes = [];
        foreach ($violations as $violation) {
            self::assertSame('preferences[0][enabled]', $violation->getPropertyPath());
            $codes[] = $violation->getCode();
        }

        self::assertContains($expectedCode, $codes);
    }

    public function testEnabledKeyRemainsRequired(): void
    {
        $decoded = (new JsonEncoder())->decode('{"preferences":[{"category":"media_changes","channel":"in_app"}]}', 'json');
        $request = new UpdatePreferenceRequest($decoded['preferences']);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = $validator->validate($request);

        self::assertCount(1, $violations);
        self::assertSame('preferences[0][enabled]', $violations[0]->getPropertyPath());
        self::assertSame(Collection::MISSING_FIELD_ERROR, $violations[0]->getCode());
    }

    public function testCategoryAndChannelChoicesRemainValidated(): void
    {
        $request = new UpdatePreferenceRequest([['category' => 'invalid', 'channel' => 'invalid', 'enabled' => false]]);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = $validator->validate($request);
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        sort($paths);

        self::assertSame(['preferences[0][category]', 'preferences[0][channel]'], $paths);
    }

    private function decodeRequest(string $enabled): UpdatePreferenceRequest
    {
        $decoded = (new JsonEncoder())->decode('{"preferences":[{"category":"media_changes","channel":"in_app","enabled":' . $enabled . '}]}', 'json');

        return new UpdatePreferenceRequest($decoded['preferences']);
    }
}
