<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Console;

use App\Metadata\Interface\Request\ExtractMetadataRequest;
use App\Metadata\Interface\Request\MatchMetadataRequest;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads the input of app:metadata:file:extract and app:metadata:file:match.
 *
 * The argument and the option are the extract and match requests' fields, and they are checked
 * with the requests' constraints, so rejected input gets the message and the per-field messages
 * of the API's 422 response.
 */
final readonly class MetadataFileInputReader
{
    private const string PATH = 'path';
    private const string CANDIDATES = 'candidates';

    public function __construct(
        private ValidatorInterface $validator,
        private TranslatorInterface $translator,
    ) {
    }

    public static function addPathArgument(Command $command): void
    {
        $command->addArgument(self::PATH, InputArgument::REQUIRED, 'Absolute path of a media file inside a library, as this server sees it');
    }

    public static function addCandidatesOption(Command $command): void
    {
        $command->addOption(
            self::CANDIDATES,
            null,
            InputOption::VALUE_REQUIRED,
            'The records to compare, as a JSON array of objects with "title" and optionally "artist", "album", "year", "mbid", "source" and "sourceId"',
        );
    }

    /** @throws InvalidInputException */
    public function extractRequest(InputInterface $input): ExtractMetadataRequest
    {
        $request = new ExtractMetadataRequest(self::path($input));
        $this->validate($request);

        return $request;
    }

    /** @throws InvalidInputException */
    public function matchRequest(InputInterface $input): MatchMetadataRequest
    {
        $request = new MatchMetadataRequest(self::path($input), self::candidates($input));
        $this->validate($request);

        return $request;
    }

    private static function path(InputInterface $input): string
    {
        $path = $input->getArgument(self::PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * The candidates option decoded, or an empty list when it is absent, which the request's
     * constraints then reject.
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidInputException when the value is not a JSON array of objects
     */
    private static function candidates(InputInterface $input): array
    {
        $json = AdminCommandSupport::stringOption($input, self::CANDIDATES);
        if ($json === null) {
            return [];
        }

        try {
            $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        if (!is_array($decoded) || !array_all($decoded, static fn (mixed $candidate): bool => $candidate instanceof \stdClass)) {
            throw new InvalidInputException(sprintf('The --%s option must be a JSON array of objects.', self::CANDIDATES));
        }

        /** @var list<array<string, mixed>> */
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
