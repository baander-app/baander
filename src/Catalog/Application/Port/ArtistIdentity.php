<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\ValueObject\MusicbrainzId;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The artist one tag names: the tagged name, and the MusicBrainz artist ID when the file gives
 * exactly one valid ID for it. Empty and malformed IDs are ignored and an ID repeated in another
 * letter case counts once, so a file whose only ID is malformed resolves by its name.
 */
#[Exclude]
final readonly class ArtistIdentity
{
    /** The tagged name without NUL bytes or surrounding whitespace; never empty. */
    public string $name;

    /** The file's only valid ID, in canonical form; null when it gives none or several. */
    public ?MusicbrainzId $musicbrainzId;

    /**
     * @param list<string> $musicbrainzIds every MusicBrainz artist ID the file gives for this artist
     *
     * @throws InvalidArgumentException when the name is blank
     */
    public function __construct(string $name, array $musicbrainzIds = [])
    {
        $this->name = str_replace("\0", '', $name) |> trim(...);
        if ($this->name === '') {
            throw new InvalidArgumentException('An artist identity needs a name.');
        }
        $this->musicbrainzId = self::onlyValidId($musicbrainzIds);
    }

    /** Equal for two identities exactly when they resolve to the same artist. */
    public function key(): string
    {
        // The name holds no NUL byte, so the separator keeps the two parts apart.
        return $this->name . "\0" . ($this->musicbrainzId?->toString() ?? '');
    }

    /** @param list<string> $ids */
    private static function onlyValidId(array $ids): ?MusicbrainzId
    {
        $valid = [];
        foreach ($ids as $id) {
            $parsed = MusicbrainzId::tryFromString($id);
            if ($parsed !== null) {
                $valid[$parsed->toString()] = $parsed;
            }
        }

        return count($valid) === 1 ? array_first($valid) : null;
    }
}
