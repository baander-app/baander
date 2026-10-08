<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Console;

use App\Scheduler\Application\DTO\ScheduledJobInput;
use App\Scheduler\Interface\Request\CreateScheduledJobRequest;
use App\Scheduler\Interface\Request\UpdateScheduledJobRequest;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads a job definition from the app:scheduler:create and app:scheduler:update options.
 *
 * The options are the create and update request's fields, and they are checked with the
 * request's constraints, so rejected input gets the message and the per-field messages of
 * the admin API's 422 response.
 */
final readonly class ScheduledJobInputReader
{
    private const string NAME = 'name';
    private const string EXPRESSION = 'expression';
    private const string TYPE = 'type';
    private const string COMMAND = 'command';
    private const string DESCRIPTION = 'description';
    private const string PARAMETERS = 'parameters';

    public function __construct(
        private ValidatorInterface $validator,
        private TranslatorInterface $translator,
    ) {
    }

    /** @param bool $update whether an omitted option keeps the job's current value */
    public static function addOptions(Command $command, bool $update): void
    {
        $unchanged = $update ? '; unchanged when omitted' : '';
        $command
            ->addOption(self::NAME, null, InputOption::VALUE_REQUIRED, 'Job name, at most 255 characters' . $unchanged)
            ->addOption(self::EXPRESSION, null, InputOption::VALUE_REQUIRED, 'Cron expression, such as "0 3 * * *"' . $unchanged)
            ->addOption(self::TYPE, null, InputOption::VALUE_REQUIRED, 'messenger or console' . $unchanged)
            ->addOption(self::COMMAND, null, InputOption::VALUE_REQUIRED, 'Schedulable command, as app:scheduler:commands lists it' . $unchanged)
            ->addOption(self::DESCRIPTION, null, InputOption::VALUE_REQUIRED, 'Description; an empty value clears it' . $unchanged)
            ->addOption(self::PARAMETERS, null, InputOption::VALUE_REQUIRED, 'Command parameters as a JSON object, such as \'{"limit": 3}\'' . $unchanged);
    }

    /** @throws InvalidInputException */
    public function forCreate(InputInterface $input): ScheduledJobInput
    {
        $request = new CreateScheduledJobRequest(
            name: $this->string($input, self::NAME) ?? '',
            expression: $this->string($input, self::EXPRESSION) ?? '',
            jobType: $this->string($input, self::TYPE) ?? '',
            command: $this->string($input, self::COMMAND) ?? '',
            description: $this->description($input, null),
            parameters: $this->parameters($input) ?? [],
        );
        $this->validate($request);

        return new ScheduledJobInput(
            $request->name,
            $request->expression,
            $request->jobType,
            $request->command,
            $request->description,
            $request->parameters,
        );
    }

    /**
     * @param array<string, mixed> $current the job as a ScheduledJobResource; omitted options keep its values
     *
     * @throws InvalidInputException
     */
    public function forUpdate(InputInterface $input, array $current): ScheduledJobInput
    {
        $description = $current['description'] ?? null;
        /** @var array<string, mixed> $parameters */
        $parameters = (array) $current['parameters'];
        $request = new UpdateScheduledJobRequest(
            name: $this->string($input, self::NAME) ?? (string) $current['name'],
            expression: $this->string($input, self::EXPRESSION) ?? (string) $current['expression'],
            jobType: $this->string($input, self::TYPE) ?? (string) $current['jobType'],
            command: $this->string($input, self::COMMAND) ?? (string) $current['command'],
            description: $this->description($input, is_string($description) ? $description : null),
            parameters: $this->parameters($input) ?? $parameters,
        );
        $this->validate($request);

        return new ScheduledJobInput(
            $request->name,
            $request->expression,
            $request->jobType,
            $request->command,
            $request->description,
            $request->parameters,
        );
    }

    private function string(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return is_string($value) ? $value : null;
    }

    private function description(InputInterface $input, ?string $current): ?string
    {
        $value = $this->string($input, self::DESCRIPTION);
        if ($value === null) {
            return $current;
        }

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>|null null when the option is omitted
     *
     * @throws InvalidInputException when the option is not a JSON object
     */
    private function parameters(InputInterface $input): ?array
    {
        $json = $this->string($input, self::PARAMETERS);
        if ($json === null) {
            return null;
        }

        try {
            $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        if (!$decoded instanceof \stdClass) {
            throw new InvalidInputException('The --parameters option must be a JSON object.');
        }

        /** @var array<string, mixed> */
        return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    }

    /** @throws InvalidInputException with the API's validation message and the messages per field */
    private function validate(object $request): void
    {
        $violations = $this->validator->validate($request);
        if ($violations->count() === 0) {
            return;
        }

        $details = [];
        foreach ($violations as $violation) {
            $details[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        throw new InvalidInputException($this->translator->trans('errors.validation.failed'), $details);
    }
}
