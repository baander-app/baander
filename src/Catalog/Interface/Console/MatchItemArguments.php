<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/** The arguments the MusicBrainz match commands name their item by: its type and public ID, as the match routes' path does. */
final class MatchItemArguments
{
    private const string TYPE = 'type';
    private const string PUBLIC_ID = 'public-id';

    public static function add(Command $command): void
    {
        $command
            ->addArgument(self::TYPE, InputArgument::REQUIRED, 'What to match: ' . self::types())
            ->addArgument(self::PUBLIC_ID, InputArgument::REQUIRED, 'The public ID of the album, song or artist');
    }

    /** @throws InvalidInputException when the type is not album, song or artist */
    public static function type(InputInterface $input): MatchItemType
    {
        $type = $input->getArgument(self::TYPE);

        return (is_string($type) ? MatchItemType::tryFrom($type) : null)
            ?? throw new InvalidInputException(sprintf('The type must be %s.', self::types()));
    }

    public static function publicId(InputInterface $input): string
    {
        $publicId = $input->getArgument(self::PUBLIC_ID);

        return is_string($publicId) ? $publicId : '';
    }

    /** The types in words, such as "album, song or artist". */
    private static function types(): string
    {
        $types = array_column(MatchItemType::cases(), 'value');
        $last = array_pop($types);

        return implode(', ', $types) . ' or ' . $last;
    }
}
