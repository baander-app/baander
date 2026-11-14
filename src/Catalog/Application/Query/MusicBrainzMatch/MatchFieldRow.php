<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One field of a comparison: the value the item holds, the value MusicBrainz gives, and whether
 * the row is ticked by default. MusicBrainz IDs and empty fields are ticked; a field that holds a
 * different value is not. A locked field shows the MusicBrainz value but is never ticked, and the
 * admin cannot tick it. A field MusicBrainz gives no value for has no row.
 */
#[Exclude]
final readonly class MatchFieldRow
{
    /**
     * @param string|int|list<string>|null $current  the value the item holds, as the admin saw it; null when empty.
     *                                               An apply rechecks it before writing the row.
     * @param string|int|list<string>      $proposed the value MusicBrainz gives
     */
    public function __construct(
        public MatchField $field,
        public string|int|array|null $current,
        public string|int|array $proposed,
        public bool $ticked,
        public bool $locked,
    ) {
    }

    /**
     * @param string|int|list<string>|null $current
     * @param string|int|list<string>      $proposed
     */
    public static function compare(MatchField $field, string|int|array|null $current, string|int|array $proposed, bool $locked): self
    {
        $current = self::isEmpty($current) ? null : $current;
        $ticked = !$locked
            && self::differ($current, $proposed)
            && ($current === null || $field->isIdentifier());

        return new self($field, $current, $proposed, $ticked, $locked);
    }

    /** Whether applying the row would change the item. Genre names are compared ignoring case and order. */
    public function changes(): bool
    {
        return self::differ($this->current, $this->proposed);
    }

    /** Whether the admin may tick the row: the field is unlocked and the row would change it. */
    public function isSelectable(): bool
    {
        return !$this->locked && $this->changes();
    }

    /**
     * @param string|int|list<string>|null $current
     * @param string|int|list<string>      $proposed
     */
    private static function differ(string|int|array|null $current, string|int|array $proposed): bool
    {
        if (is_array($current) && is_array($proposed)) {
            return self::folded($current) !== self::folded($proposed);
        }

        return $current !== $proposed;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function folded(array $names): array
    {
        $folded = array_map(mb_strtolower(...), $names) |> array_unique(...);
        sort($folded);

        return $folded;
    }

    /** @param string|int|list<string>|null $value */
    private static function isEmpty(string|int|array|null $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }
}
