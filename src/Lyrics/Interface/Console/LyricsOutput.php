<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Console;

use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a song's lyrics, as LyricsResource renders them, for app:song:lyrics:fetch and
 * app:lyrics:apply.
 */
final class LyricsOutput
{
    /**
     * @param array<string, mixed> $lyrics the LyricsResource payload the API returns
     */
    public static function show(SymfonyStyle $io, array $lyrics): void
    {
        $source = $lyrics['source'] ?? null;
        $synced = $lyrics['syncedLyrics'] ?? null;
        $plain = $lyrics['plainLyrics'] ?? null;

        $io->definitionList(
            ['Source' => is_string($source) ? $source : '-'],
            ['Synced lyrics' => AdminCommandSupport::yesNo(is_string($synced) && $synced !== '')],
            ['Instrumental' => AdminCommandSupport::yesNo(($lyrics['isInstrumental'] ?? false) === true)],
        );

        if (is_string($plain) && $plain !== '') {
            $io->writeln($plain);
        }
    }
}
