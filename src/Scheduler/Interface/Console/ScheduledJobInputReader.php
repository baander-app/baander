<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Console;

use App\Scheduler\Application\DTO\ScheduledJobInput;
use App\Scheduler\Interface\Request\CreateScheduledJobRequest;
use App\Scheduler\Interface\Request\UpdateScheduledJobRequest;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
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
            name: AdminCommandSupport::stringOption($input, self::NAME) ?? '',
            expression: AdminCommandSupport::stringOption($input, self::EXPRESSION) ?? '',
            jobType: AdminCommandSupport::stringOption($input, self::TYPE) ?? '',
            command: AdminCommandSupport::stringOption($input, self::COMMAND) ?? '',
            description: $this->description($input, null),
            parameters: AdminCommandSupport::jsonObjectOption($input, self::PARAMETERS) ?? [],
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
            name: AdminCommandSupport::stringOption($input, self::NAME) ?? (string) $current['name'],
            expression: AdminCommandSupport::stringOption($input, self::EXPRESSION) ?? (string) $current['expression'],
            jobType: AdminCommandSupport::stringOption($input, self::TYPE) ?? (string) $current['jobType'],
            command: AdminCommandSupport::stringOption($input, self::COMMAND) ?? (string) $current['command'],
            description: $this->description($input, is_string($description) ? $description : null),
            parameters: AdminCommandSupport::jsonObjectOption($input, self::PARAMETERS) ?? $parameters,
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

    private function description(InputInterface $input, ?string $current): ?string
    {
        $value = AdminCommandSupport::stringOption($input, self::DESCRIPTION);
        if ($value === null) {
            return $current;
        }

        return $value === '' ? null : $value;
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
