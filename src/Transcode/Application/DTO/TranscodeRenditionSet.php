<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Shared\Domain\Model\PublicId;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final class TranscodeRenditionSet
{
    /** @param list<array{jobId: string, generation: int}> $pairs */
    public static function encode(array $pairs): string
    {
        if ($pairs === [] || count($pairs) > 3) {
            throw new \InvalidArgumentException('A rendition set must contain one to three jobs.');
        }
        $entries = [];
        foreach ($pairs as $pair) {
            $id = PublicId::fromString($pair['jobId'])->toString();
            if ($pair['generation'] < 1 || isset($entries[$id])) {
                throw new \InvalidArgumentException('Rendition generations must be positive and jobs unique.');
            }
            $entries[$id] = $id.'.'.$pair['generation'];
        }
        ksort($entries, SORT_STRING);
        return implode(',', $entries);
    }

    /** @return list<array{jobId: string, generation: int}> */
    public static function parse(string $encoded): array
    {
        $entries = explode(',', $encoded);
        if (count($entries) > 3) {
            throw new \InvalidArgumentException('A rendition set cannot exceed three jobs.');
        }
        $pairs = [];
        foreach ($entries as $entry) {
            $parts = explode('.', $entry);
            if (count($parts) !== 2 || !ctype_digit($parts[1])) {
                throw new \InvalidArgumentException('Invalid rendition set.');
            }
            $generation = filter_var($parts[1], FILTER_VALIDATE_INT);
            if ($generation === false || $generation < 1) {
                throw new \InvalidArgumentException('Invalid rendition generation.');
            }
            $pairs[] = ['jobId' => PublicId::fromString($parts[0])->toString(), 'generation' => $generation];
        }
        if (self::encode($pairs) !== $encoded) {
            throw new \InvalidArgumentException('Rendition sets must use canonical ordering.');
        }
        return $pairs;
    }
}
