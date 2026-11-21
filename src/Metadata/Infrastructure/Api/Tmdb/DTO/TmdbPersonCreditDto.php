<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tmdb\DTO;

use App\Catalog\Application\Port\PersonCreditInput;

final class TmdbPersonCreditDto
{
    /**
     * A malformed member makes its partition unavailable, avoiding destructive partial replacement.
     *
     * @return list<PersonCreditInput>|null
     */
    public static function partition(mixed $data, string $category): ?array
    {
        if (!is_array($data) || !array_is_list($data)) {
            return null;
        }
        $credits = [];
        foreach ($data as $member) {
            if (!is_array($member) || !is_int($member['id'] ?? null) || $member['id'] <= 0 ||
                !is_string($member['name'] ?? null) || trim($member['name']) === '') {
                return null;
            }
            $job = $category === 'cast' ? 'Actor' : ($member['job'] ?? null);
            if (!is_string($job) || trim($job) === '') {
                return null;
            }
            $path = self::text($member['profile_path'] ?? null);
            $credits[] = new PersonCreditInput(
                tmdbId: (string) $member['id'],
                name: $member['name'],
                category: $category,
                role: $category === 'cast' ? 'actor' : self::role($job),
                job: $job,
                characterName: self::text($member['character'] ?? null),
                department: self::text($member['department'] ?? null),
                billingOrder: is_int($member['order'] ?? null) ? $member['order'] : null,
                providerCreditId: self::text($member['credit_id'] ?? null),
                profileUrl: $path === null ? null : 'https://image.tmdb.org/t/p/w185' . $path,
            );
        }

        return $credits;
    }

    private static function role(string $job): string
    {
        $role = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($job)) ?? '', '_');

        return $role === '' ? 'crew' : $role;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
