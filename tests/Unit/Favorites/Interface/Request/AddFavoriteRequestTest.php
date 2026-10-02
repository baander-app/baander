<?php

declare(strict_types=1);

namespace App\Tests\Unit\Favorites\Interface\Request;

use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Favorites\Interface\Request\AddFavoriteRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

final class AddFavoriteRequestTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function supportedTypes(): iterable
    {
        foreach (FavoriteType::cases() as $type) {
            yield $type->value => [$type->value];
        }
    }

    #[DataProvider('supportedTypes')]
    public function testAllSupportedFavoriteTypesAreAccepted(string $type): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertCount(0, $validator->validate(new AddFavoriteRequest($type, 'song-public-id')));
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedTypes(): iterable
    {
        yield 'unknown' => ['invalid-type'];
        yield 'unsupported playlist' => ['playlist'];
        yield 'case mismatch' => ['Song'];
        yield 'leading whitespace' => [' song'];
        yield 'trailing whitespace' => ['song '];
    }

    #[DataProvider('unsupportedTypes')]
    public function testUnsupportedTypeIsRejectedOnEntityType(string $type): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = $validator->validate(new AddFavoriteRequest($type, 'song-public-id'));

        self::assertCount(1, $violations);
        self::assertSame('entityType', $violations[0]->getPropertyPath());
        self::assertSame(Choice::NO_SUCH_CHOICE_ERROR, $violations[0]->getCode());
    }

    public function testBlankTypeRetainsRequiredFieldValidation(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = $validator->validate(new AddFavoriteRequest('', 'song-public-id'));
        $codes = [];
        foreach ($violations as $violation) {
            self::assertSame('entityType', $violation->getPropertyPath());
            $codes[] = $violation->getCode();
        }

        self::assertContains(NotBlank::IS_BLANK_ERROR, $codes);
    }

    public function testBlankPublicIdRetainsRequiredFieldValidation(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = $validator->validate(new AddFavoriteRequest('song', ''));

        self::assertCount(1, $violations);
        self::assertSame('entityPublicId', $violations[0]->getPropertyPath());
        self::assertSame(NotBlank::IS_BLANK_ERROR, $violations[0]->getCode());
    }
}
