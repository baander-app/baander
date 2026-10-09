<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

/**
 * Input rules and messages the artist credit use cases share.
 */
final class ArtistCreditInput
{
    /**
     * @throws InvalidInputException when the value is not a UUID
     */
    public static function targetId(CreditTarget $target, string $value): Uuid
    {
        try {
            return Uuid::fromString($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException(sprintf('Invalid %s ID format.', $target->value), [], $exception);
        }
    }

    /**
     * @return string the ArtistRole value
     *
     * @throws InvalidInputException when the value is no ArtistRole
     */
    public static function role(string $value): string
    {
        if (ArtistRole::tryFrom($value) === null) {
            throw new InvalidInputException(sprintf(
                'Invalid role "%s". Valid roles: %s.',
                $value,
                implode(', ', array_map(static fn (ArtistRole $role): string => $role->value, ArtistRole::cases())),
            ));
        }

        return $value;
    }

    /**
     * @throws NotFoundException when no artist has the public ID
     */
    public static function artist(ArtistPortInterface $artists, PublicId $publicId): Artist
    {
        return $artists->findByPublicId($publicId)
            ?? throw new NotFoundException(sprintf('Artist "%s" not found.', $publicId->toString()));
    }

    public static function noCredit(string $publicId, CreditTarget $target, string $targetId, ?string $role = null): NotFoundException
    {
        return new NotFoundException($role === null
            ? sprintf('Artist "%s" has no credit on %s "%s".', $publicId, $target->value, $targetId)
            : sprintf('Artist "%s" has no "%s" credit on %s "%s".', $publicId, $role, $target->value, $targetId));
    }
}
