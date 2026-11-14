<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The artist credits of a song or album now and as a match would set them. A match replaces the
 * primary and featured credits only; credits of other roles, such as a producer's, stay. The row is
 * ticked by default when applying it would change something: other artists, roles or credited
 * names, or a stored artist taking on a MusicBrainz ID.
 */
#[Exclude]
final readonly class MatchCreditsRow
{
    /**
     * @param string|null          $songPublicId the song the credits belong to; null for the album's own credits
     * @param list<CatalogCredit>  $current      the primary and featured credits now, as the admin saw them; an apply rechecks them
     * @param list<ProposedCredit> $proposed     in MusicBrainz's credit order
     */
    public function __construct(
        public ?string $songPublicId,
        public array $current,
        public array $proposed,
        public bool $ticked,
    ) {
    }

    /**
     * @param list<CatalogCredit>  $current
     * @param list<ProposedCredit> $proposed
     */
    public static function compare(?string $songPublicId, array $current, array $proposed): self
    {
        return new self($songPublicId, $current, $proposed, self::differ($current, $proposed));
    }

    /** Whether applying the row would change the credits or give a stored artist its MusicBrainz ID. */
    public function changes(): bool
    {
        return self::differ($this->current, $this->proposed);
    }

    public function isAlbumCredits(): bool
    {
        return $this->songPublicId === null;
    }

    /**
     * @param list<CatalogCredit>  $current
     * @param list<ProposedCredit> $proposed
     */
    private static function differ(array $current, array $proposed): bool
    {
        if (array_any($proposed, static fn (ProposedCredit $credit): bool => $credit->artist->isNew() || $credit->artist->takesId)) {
            return true;
        }

        $now = array_map(
            static fn (CatalogCredit $credit): string => self::key($credit->artistId->toString(), $credit->role ?? '', $credit->creditedName),
            $current,
        );
        $then = array_map(
            static fn (ProposedCredit $credit): string => self::key($credit->artist->id?->toString() ?? '', $credit->role->value, $credit->creditedName),
            $proposed,
        );
        sort($now);
        sort($then);

        return $now !== $then;
    }

    private static function key(string $artistId, string $role, string $creditedName): string
    {
        return implode("\0", [$artistId, $role, $creditedName]);
    }
}
