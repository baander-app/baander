<?php

declare(strict_types=1);

namespace App\Metadata\Application\MessageHandler;

use App\Catalog\Application\Port\PersonIdentityPortInterface;
use App\Metadata\Application\Message\SyncPeopleMessage;
use App\Metadata\Application\Port\TmdbPersonLookupInterface;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

final readonly class SyncPeopleHandler
{
    private const int PAGE_SIZE = 5;

    public function __construct(
        private PersonIdentityPortInterface $people,
        private TmdbPersonLookupInterface $tmdb,
        private MessageBusInterface $bus,
        private JobCancellationCheckpointInterface $cancellation,
    ) {}

    #[AsMessageHandler(fromTransport: 'async', sign: true)]
    public function __invoke(SyncPeopleMessage $message): void
    {
        if (!$this->tmdb->isConfigured()) {
            return;
        }
        $ids = $this->people->pendingTmdbPeople(self::PAGE_SIZE, $message->after);
        foreach ($ids as $tmdbId) {
            $this->cancellation->check();
            try {
                $this->enrich($tmdbId);
            } catch (\Throwable $exception) {
                throw new RecoverableMessageHandlingException(
                    'Person enrichment will retry after the profile claims expire.',
                    previous: $exception,
                    retryDelay: 300100,
                    forceRetry: false,
                );
            }
        }
        if (count($ids) === self::PAGE_SIZE) {
            $this->bus->dispatch(new SyncPeopleMessage($ids[array_key_last($ids)]));
        }
    }

    private function enrich(string $tmdbId): void
    {
        $data = $this->tmdb->lookupPerson((int) $tmdbId);
        if ($data === null) {
            return;
        }
        $externalIds = [];
        $imdbId = $data['external_ids']['imdb_id'] ?? null;
        if (is_string($imdbId) && preg_match('/^nm[0-9]+$/D', $imdbId) === 1) {
            $externalIds['imdb_name'] = $imdbId;
        }
        $gender = match ($data['gender'] ?? null) {
            1 => 'female',
            2 => 'male',
            3 => 'non-binary',
            default => null,
        };
        $aliases = array_values(array_filter(
            is_array($data['also_known_as'] ?? null) ? $data['also_known_as'] : [],
            is_string(...),
        ));
        $path = self::text($data['profile_path'] ?? null);
        $deathDate = self::text($data['deathday'] ?? null);
        $this->people->enrichTmdbPerson($tmdbId, $externalIds, [
            'name' => self::text($data['name'] ?? null),
            'profile_url' => $path === null ? null : 'https://image.tmdb.org/t/p/w185' . $path,
            'biography' => self::text($data['biography'] ?? null),
            'aliases' => $aliases,
            'birth_date' => self::text($data['birthday'] ?? null),
            'death_date' => $deathDate,
            'is_deceased' => $deathDate !== null,
            'birthplace' => self::text($data['place_of_birth'] ?? null),
            'gender' => $gender,
        ]);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
