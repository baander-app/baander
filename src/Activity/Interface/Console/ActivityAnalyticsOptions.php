<?php

declare(strict_types=1);

namespace App\Activity\Interface\Console;

use App\Activity\Interface\Request\ActivityAnalyticsRange;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The --from, --to and --limit options of the app:activity:* commands, read with the rules
 * the /api/admin/activity/* endpoints apply to their query parameters.
 */
final class ActivityAnalyticsOptions
{
    public static function configure(Command $command, bool $withLimit): void
    {
        $command
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First day counted, Y-m-d, from its start in the server time zone (default: 30 days before now)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last day counted, Y-m-d, up to the start of the next day; must not precede --from (default: today)');
        if ($withLimit) {
            $command->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf(
                'Results to show, 1-%d (default: %d)',
                ActivityAnalyticsRange::MAX_LIMIT,
                ActivityAnalyticsRange::DEFAULT_LIMIT,
            ));
        }
        AdminCommandSupport::addJsonOption($command);
    }

    /** @throws InvalidInputException when a date is malformed or the last day precedes the first */
    public static function range(InputInterface $input): ActivityAnalyticsRange
    {
        return self::read(static fn (): ActivityAnalyticsRange => ActivityAnalyticsRange::fromQuery(self::query($input)));
    }

    /** @throws InvalidInputException when the limit is not an integer in range */
    public static function limit(InputInterface $input): int
    {
        return self::read(static fn (): int => ActivityAnalyticsRange::limit(self::query($input)));
    }

    /** Names the instants the numbers cover, above a table. */
    public static function describe(SymfonyStyle $io, ActivityAnalyticsRange $range): void
    {
        $io->text(sprintf(
            'Plays last played from %s up to %s',
            $range->from->format(\DateTimeInterface::ATOM),
            $range->to->format(\DateTimeInterface::ATOM),
        ));
    }

    /**
     * @template T
     *
     * @param callable(): T $reader
     *
     * @return T
     */
    private static function read(callable $reader): mixed
    {
        try {
            return $reader();
        } catch (InvalidQueryParameter $exception) {
            throw new InvalidInputException(sprintf('--%s: %s', $exception->parameter, $exception->getMessage()));
        }
    }

    /**
     * The options given on the command line, as the API would receive them as query parameters.
     *
     * @return InputBag<covariant string|int|float|bool|null> the type ActivityAnalyticsRange reads
     */
    private static function query(InputInterface $input): InputBag
    {
        $values = [];
        foreach (['from', 'to', 'limit'] as $name) {
            $value = $input->hasOption($name) ? AdminCommandSupport::stringOption($input, $name) : null;
            if ($value !== null) {
                $values[$name] = $value;
            }
        }

        return new InputBag($values);
    }
}
