<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * The shared plumbing of admin console commands.
 *
 * A command dispatches the Application message its admin controller dispatches, acts
 * with full authority, and reports the same outcome: a not-found or conflict exception
 * exits with FAILURE and an invalid-input exception with INVALID, with the message on
 * stderr. Read-only commands print a table, or with `--json` exactly the API response's
 * `data` payload on stdout. Destructive commands ask on a terminal and otherwise need
 * `--force`.
 */
final readonly class AdminCommandSupport
{
    public const string JSON_OPTION = 'json';
    public const string FORCE_OPTION = 'force';

    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Dispatches synchronously through the bus the admin API uses and returns the handler's result.
     *
     * @throws Throwable the handler's own exception, unwrapped from HandlerFailedException
     */
    public function dispatch(object $message): mixed
    {
        try {
            return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $exception) {
            throw HandlerFailure::cause($exception);
        }
    }

    public static function addJsonOption(Command $command): void
    {
        $command->addOption(self::JSON_OPTION, null, InputOption::VALUE_NONE, 'Print the data as the admin API returns it, in JSON');
    }

    public static function addForceOption(Command $command): void
    {
        $command->addOption(self::FORCE_OPTION, null, InputOption::VALUE_NONE, 'Make the change without asking; required when no terminal is attached');
    }

    public static function wantsJson(InputInterface $input): bool
    {
        return $input->getOption(self::JSON_OPTION) === true;
    }

    /**
     * Writes the data as JSON to stdout, and nothing else.
     *
     * @param mixed $data the `data` payload of the matching API response, built with the same resource
     */
    public static function json(SymfonyStyle $io, mixed $data): int
    {
        $io->writeln(self::prettyJson($data), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    /** The data as indented JSON, with slashes and Unicode unescaped. */
    public static function prettyJson(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /** The value of a string option, or null when it is absent. */
    public static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }

    /**
     * The value of an integer option, or null when it is absent.
     *
     * @throws InvalidInputException when the value is not an integer
     */
    public static function integerOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new InvalidInputException(sprintf('--%s must be an integer.', $name));
        }

        return $integer;
    }

    /**
     * The value of an option that holds a JSON object, decoded, or null when the option is absent.
     *
     * @return array<string, mixed>|null
     *
     * @throws InvalidInputException when the value is not a JSON object
     */
    public static function jsonObjectOption(InputInterface $input, string $option): ?array
    {
        $json = self::stringOption($input, $option);
        if ($json === null) {
            return null;
        }

        try {
            $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        if (!$decoded instanceof \stdClass) {
            throw new InvalidInputException(sprintf('The --%s option must be a JSON object.', $option));
        }

        /** @var array<string, mixed> */
        return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $label what the value identifies, such as "The job ID", as the error message names it
     *
     * @throws InvalidInputException when the value is not a UUID
     */
    public static function uuid(mixed $value, string $label): Uuid
    {
        try {
            return Uuid::fromString(is_scalar($value) ? (string) $value : '');
        } catch (\InvalidArgumentException $error) {
            throw new InvalidInputException(sprintf('%s must be a UUID.', $label), previous: $error);
        }
    }

    /**
     * Prints a list as a table with one row per item, or with `--json` as the API's `data` array.
     *
     * @param list<array<string, mixed>>                     $items   the resource collection the controller returns
     * @param list<string>                                   $headers
     * @param callable(array<string, mixed>): list<mixed>    $row     the table cells of one item
     */
    public static function list(InputInterface $input, SymfonyStyle $io, array $items, array $headers, callable $row, string $emptyMessage): int
    {
        if (self::wantsJson($input)) {
            return self::json($io, $items);
        }

        if ($items === []) {
            $io->text($emptyMessage);

            return Command::SUCCESS;
        }

        $io->table($headers, array_map($row, $items));

        return Command::SUCCESS;
    }

    /**
     * Asks before a destructive change: on a terminal the operator confirms, otherwise `--force` is required.
     *
     * @return int|null null when the command may go ahead, otherwise the exit code to return:
     *                  INVALID without a terminal and without `--force`, FAILURE when the operator declines
     */
    public static function confirm(InputInterface $input, SymfonyStyle $io, string $question): ?int
    {
        if ($input->getOption(self::FORCE_OPTION) === true) {
            return null;
        }

        if (!self::hasTerminal($input)) {
            $io->getErrorStyle()->error('No terminal is attached to confirm this change. Run the command again with --force.');

            return Command::INVALID;
        }

        // On stderr, so `out=$(… --json)` on a terminal captures only the data.
        if ($io->getErrorStyle()->confirm($question, false)) {
            return null;
        }

        $io->getErrorStyle()->text('Nothing was changed.');

        return Command::FAILURE;
    }

    /**
     * Prints the failure's message on stderr, followed by the messages per field of rejected
     * input as the API's 422 response details them, and returns its exit code.
     */
    public static function fail(SymfonyStyle $io, Throwable $failure): int
    {
        $errors = $io->getErrorStyle();
        $errors->error($failure->getMessage());

        if ($failure instanceof InvalidInputException && $failure->details !== []) {
            $lines = [];
            foreach ($failure->details as $field => $messages) {
                foreach ((array) $messages as $message) {
                    $lines[] = sprintf('%s: %s', $field, $message);
                }
            }
            $errors->listing($lines);
        }

        return self::exitCode($failure);
    }

    /** INVALID for rejected input; FAILURE for an unknown target, a conflict and anything else. */
    public static function exitCode(Throwable $failure): int
    {
        return $failure instanceof InvalidInputException ? Command::INVALID : Command::FAILURE;
    }

    /**
     * Answers can be read when the input is interactive and stdin is a terminal, or when the
     * caller gave the input its own answer stream.
     */
    private static function hasTerminal(InputInterface $input): bool
    {
        if (!$input->isInteractive()) {
            return false;
        }

        if ($input instanceof StreamableInputInterface && $input->getStream() !== null) {
            return true;
        }

        return defined('STDIN') && stream_isatty(STDIN);
    }
}
