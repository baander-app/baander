<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use App\Catalog\Application\Command\MusicBrainzMatch\MatchSelection;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenCredit;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenCredits;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Interface\Resource\MatchComparisonResource;
use App\Shared\Application\Exception\InvalidInputException;
use OpenApi\Attributes as OA;

/**
 * The body of POST /api/admin/metadata/match/{type}/{publicId}/apply, and what
 * app:metadata:match:apply builds from its --selection option or from the comparison's default
 * ticks: the candidate, every ticked row by the key the comparison gave it with the current value
 * it showed, and each listed artist merge or removal, ticked or kept.
 *
 * @phpstan-import-type ComparisonData from MatchComparisonResource
 */
#[OA\Schema(
    schema: 'ApplyMatchRequest',
    required: ['candidate'],
    properties: [
        new OA\Property(property: 'candidate', description: 'The MusicBrainz ID compared: a release for an album, a recording for a song, an artist for an artist', type: 'string'),
        new OA\Property(property: 'fields', description: 'The ticked field rows, each by its key with the current value the comparison showed', type: 'array', items: new OA\Items(
            required: ['key', 'current'],
            properties: [
                new OA\Property(property: 'key', description: 'The row\'s key: the field, or "<song public ID>:<field>" for a row of one of an album\'s songs', type: 'string'),
                new OA\Property(
                    property: 'current',
                    description: 'The current value the row showed; null when it showed none',
                    nullable: true,
                    oneOf: [
                        new OA\Schema(type: 'string'),
                        new OA\Schema(type: 'integer'),
                        new OA\Schema(type: 'boolean'),
                        new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
                    ],
                ),
            ],
            type: 'object',
        )),
        new OA\Property(property: 'credits', description: 'The ticked credits rows, each by its key with the credits the comparison showed as current', type: 'array', items: new OA\Items(
            required: ['key', 'current'],
            properties: [
                new OA\Property(property: 'key', description: 'The row\'s key: "credits", or "<song public ID>:credits" for one of an album\'s songs', type: 'string'),
                new OA\Property(property: 'current', type: 'array', items: new OA\Items(
                    required: ['artistPublicId', 'role', 'creditedName'],
                    properties: [
                        new OA\Property(property: 'artistPublicId', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
                        new OA\Property(property: 'role', type: 'string', nullable: true),
                        new OA\Property(property: 'creditedName', type: 'string'),
                    ],
                    type: 'object',
                )),
            ],
            type: 'object',
        )),
        new OA\Property(property: 'artistActions', description: 'The public IDs of the artists whose listed merge or removal stays ticked', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'keptArtists', description: 'The public IDs of the artists whose listed merge or removal was unticked', type: 'array', items: new OA\Items(type: 'string')),
    ],
)]
final readonly class ApplyMatchRequest
{
    private const string INVALID = 'The match selection is invalid.';

    /**
     * @param array<array-key, mixed> $fields        the ticked field rows: objects with key and current
     * @param array<array-key, mixed> $credits       the ticked credits rows: objects with key and current
     * @param array<array-key, mixed> $artistActions artist public IDs
     * @param array<array-key, mixed> $keptArtists   artist public IDs
     */
    public function __construct(
        public string $candidate = '',
        public array $fields = [],
        public array $credits = [],
        public array $artistActions = [],
        public array $keptArtists = [],
    ) {
    }

    /**
     * The request a selection given as a JSON object makes, with the members the API body has
     * besides the candidate; other members are ignored, as the API ignores them.
     *
     * @param array<string, mixed> $selection
     *
     * @throws InvalidInputException when a member is not a list
     */
    public static function fromSelection(string $candidate, array $selection): self
    {
        $lists = [];
        $errors = [];
        foreach (['fields', 'credits', 'artistActions', 'keptArtists'] as $member) {
            $lists[$member] = $selection[$member] ?? [];
            if (!is_array($lists[$member]) || !array_is_list($lists[$member])) {
                $errors[$member][] = 'This value should be a list.';
            }
        }
        if ($errors !== []) {
            throw new InvalidInputException(self::INVALID, $errors);
        }

        return new self($candidate, ...$lists);
    }

    /**
     * The request that applies what the comparison ticks by default: every ticked row with the
     * current value it shows, and every listed merge or removal as it is ticked.
     *
     * @param ComparisonData $comparison the comparison's data, as the comparison route returns it
     */
    public static function withDefaultTicks(array $comparison): self
    {
        $fieldRows = [$comparison['fields'], ...array_column($comparison['tracks'], 'fields')] |> (static fn (array $rows): array => array_merge(...$rows));
        $creditRows = [$comparison['credits'], ...array_column($comparison['tracks'], 'credits')] |> array_filter(...);
        $ticked = static fn (array $rows): array => array_filter($rows, static fn (array $row): bool => $row['ticked']) |> array_values(...);
        $actions = static fn (bool $ticked): array => array_filter($comparison['artistActions'], static fn (array $action): bool => $action['ticked'] === $ticked)
            |> (static fn (array $listed): array => array_column($listed, 'artistPublicId'));

        return new self(
            candidate: $comparison['candidateId'],
            fields: array_map(static fn (array $row): array => ['key' => $row['key'], 'current' => $row['current']], $ticked($fieldRows)),
            credits: array_map(
                static fn (array $row): array => [
                    'key' => $row['key'],
                    'current' => array_map(
                        static fn (array $credit): array => ['artistPublicId' => $credit['artistPublicId'], 'role' => $credit['role'], 'creditedName' => $credit['creditedName']],
                        $row['current'],
                    ),
                ],
                $ticked($creditRows),
            ),
            artistActions: $actions(true),
            keptArtists: $actions(false),
        );
    }

    /**
     * The selection the request names, each row taken apart by its key.
     *
     * @throws InvalidInputException with the messages per entry when a row, key, value or artist ID is malformed
     */
    public function selection(): MatchSelection
    {
        $errors = [];
        $fields = self::entries($this->fields, 'fields', self::seenField(...), $errors);
        $credits = self::entries($this->credits, 'credits', self::seenCredits(...), $errors);
        $artistActions = self::entries($this->artistActions, 'artistActions', self::artistPublicId(...), $errors);
        $keptArtists = self::entries($this->keptArtists, 'keptArtists', self::artistPublicId(...), $errors);
        if ($errors !== []) {
            throw new InvalidInputException(self::INVALID, $errors);
        }

        return new MatchSelection($fields, $credits, $artistActions, $keptArtists);
    }

    /**
     * Each entry read, or its errors added under its path, such as `fields[2].key`.
     *
     * @template T of object|string
     *
     * @param array<array-key, mixed>                                   $entries
     * @param callable(mixed, string, array<string, list<string>>): ?T $read    the entry read; null when it added errors
     * @param array<string, list<string>>                               $errors
     *
     * @return list<T>
     */
    private static function entries(array $entries, string $member, callable $read, array &$errors): array
    {
        $values = [];
        foreach ($entries as $index => $entry) {
            $value = $read($entry, sprintf('%s[%s]', $member, $index), $errors);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @param array<string, list<string>> $errors */
    private static function seenField(mixed $entry, string $path, array &$errors): ?SeenField
    {
        if (!is_array($entry) || !is_string($entry['key'] ?? null) || !array_key_exists('current', $entry)) {
            $errors[$path][] = 'A field row is an object with a key and its current value.';

            return null;
        }

        [$song, $name] = self::keyParts($entry['key']);
        $field = MatchField::tryFrom($name);
        if ($field === null || $song === '') {
            $errors[$path . '.key'][] = sprintf('"%s" names no field row.', $entry['key']);
        }
        $current = $entry['current'];
        if (!self::isFieldValue($current)) {
            $errors[$path . '.current'][] = 'A current value is a text, a number, true, a list of texts or null.';

            return null;
        }

        return $field === null || $song === '' ? null : new SeenField($field, $current, $song);
    }

    /** @param array<string, list<string>> $errors */
    private static function seenCredits(mixed $entry, string $path, array &$errors): ?SeenCredits
    {
        if (!is_array($entry) || !is_string($entry['key'] ?? null) || !is_array($entry['current'] ?? null) || !array_is_list($entry['current'])) {
            $errors[$path][] = 'A credits row is an object with a key and the list of credits it showed.';

            return null;
        }

        [$song, $name] = self::keyParts($entry['key']);
        $valid = $name === MatchComparisonResource::CREDITS && $song !== '';
        if (!$valid) {
            $errors[$path . '.key'][] = sprintf('"%s" names no credits row.', $entry['key']);
        }
        $credits = self::entries($entry['current'], $path . '.current', self::seenCredit(...), $errors);

        return $valid && count($credits) === count($entry['current']) ? new SeenCredits($song, $credits) : null;
    }

    /** @param array<string, list<string>> $errors */
    private static function seenCredit(mixed $entry, string $path, array &$errors): ?SeenCredit
    {
        $role = is_array($entry) ? $entry['role'] ?? null : null;
        if (!is_array($entry) || !is_string($entry['artistPublicId'] ?? null) || !is_string($entry['creditedName'] ?? null) || !($role === null || is_string($role))) {
            $errors[$path][] = 'A credit is an object with the artist\'s public ID, its role or null, and the credited name.';

            return null;
        }

        return new SeenCredit($entry['artistPublicId'], $role, $entry['creditedName']);
    }

    /** @param array<string, list<string>> $errors */
    private static function artistPublicId(mixed $entry, string $path, array &$errors): ?string
    {
        if (!is_string($entry) || $entry === '') {
            $errors[$path][] = 'An artist is named by its public ID.';

            return null;
        }

        return $entry;
    }

    /**
     * The song a key names, null for a row of the item itself, and the field or "credits".
     *
     * @return array{string|null, string}
     */
    private static function keyParts(string $key): array
    {
        if (!str_contains($key, ':')) {
            return [null, $key];
        }

        [$song, $name] = explode(':', $key, 2);

        return [$song, $name];
    }

    /** @phpstan-assert-if-true string|int|bool|list<string>|null $value */
    private static function isFieldValue(mixed $value): bool
    {
        return $value === null || is_string($value) || is_int($value) || $value === true
            || (is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => is_string($item)));
    }
}
