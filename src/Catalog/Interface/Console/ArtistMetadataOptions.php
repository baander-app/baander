<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * The metadata options app:artist:create and app:artist:update share, one per field the artist API accepts.
 */
final class ArtistMetadataOptions
{
    public static function add(Command $command): void
    {
        $command
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'The country, such as GB')
            ->addOption('gender', null, InputOption::VALUE_REQUIRED, 'The gender of a person')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'The artist type, such as person or group')
            ->addOption('disambiguation', null, InputOption::VALUE_REQUIRED, 'A comment that tells artists of the same name apart')
            ->addOption('sort-name', null, InputOption::VALUE_REQUIRED, 'The name to sort by, such as "Beatles, The"')
            ->addOption('biography', null, InputOption::VALUE_REQUIRED, 'The biography');
    }
}
