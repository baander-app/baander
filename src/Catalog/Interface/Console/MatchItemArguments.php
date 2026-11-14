<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The arguments the MusicBrainz match commands name their item by, its type and public ID as the
 * match routes' path does, and the candidate a comparison or apply takes.
 */
final class MatchItemArguments
{
    private const string TYPE = 'type';
    private const string PUBLIC_ID = 'public-id';
    private const string CANDIDATE = 'candidate';

    public static function add(Command $command): void
    {
        $command
            ->addArgument(self::TYPE, InputArgument::REQUIRED, 'What to match: ' . MatchItemType::names())
            ->addArgument(self::PUBLIC_ID, InputArgument::REQUIRED, 'The public ID of the album, song or artist');
    }

    /** @throws InvalidInputException when the argument names no match type */
    public static function type(InputInterface $input): MatchItemType
    {
        $type = $input->getArgument(self::TYPE);

        return MatchItemType::fromInput(is_string($type) ? $type : '');
    }

    public static function publicId(InputInterface $input): string
    {
        $publicId = $input->getArgument(self::PUBLIC_ID);

        return is_string($publicId) ? $publicId : '';
    }

    public static function addCandidate(Command $command): void
    {
        $command->addArgument(self::CANDIDATE, InputArgument::REQUIRED, 'The candidate\'s MusicBrainz ID, as app:metadata:match:search lists it');
    }

    public static function candidate(InputInterface $input): string
    {
        $candidate = $input->getArgument(self::CANDIDATE);

        return is_string($candidate) ? $candidate : '';
    }
}
