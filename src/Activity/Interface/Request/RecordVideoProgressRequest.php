<?php

declare(strict_types=1);

namespace App\Activity\Interface\Request;

use App\Activity\Application\Command\VideoProgressAction;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use App\Shared\Interface\Request\QueryParameters;
use DateTimeImmutable;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'titleKind' => new OA\Schema(type: 'string', enum: ['episode', 'movie', 'extra']),
        'titleId' => new OA\Schema(type: 'string', description: 'Episode or movie public ID, or the placement public ID for an extra', minLength: PublicId::LENGTH, maxLength: PublicId::LENGTH, pattern: PublicId::SCHEMA_PATTERN),
        'positionSeconds' => new OA\Schema(type: 'number', format: 'double', minimum: 0),
        'action' => new OA\Schema(type: 'string', enum: [VideoProgressAction::class]),
        'reportedAt' => new OA\Schema(type: 'string', format: 'date-time', description: 'Time the player observed the event, with a timezone and at most six fractional digits. Future reports use server receipt time. By default, observations must be newer than the saved time. The server can allow an older observation within its configured clock tolerance. Reports at or before a manual clear or completion cannot restore the position.'),
    ],
    required: ['titleKind', 'titleId', 'positionSeconds', 'action', 'reportedAt'],
    component: 'RecordVideoProgressRequest',
)]
final readonly class RecordVideoProgressRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['episode', 'movie', 'extra'])]
        public string $titleKind,
        #[Assert\Regex(pattern: PublicId::REGEX)]
        public string $titleId,
        #[Assert\PositiveOrZero]
        public float $positionSeconds,
        #[Assert\Choice(choices: ['progress', 'pause', 'seek', 'end'])]
        public string $action,
        #[Assert\NotBlank]
        public string $reportedAt,
    ) {}

    public function reportedTime(): DateTimeImmutable
    {
        try {
            return QueryParameters::optionalDateTime(new InputBag(['reportedAt' => $this->reportedAt]), 'reportedAt')
                ?? throw new InvalidInputException('A report time is required.');
        } catch (InvalidQueryParameter $error) {
            throw new InvalidInputException('The report time must be an RFC 3339 instant with a timezone.', ['reportedAt' => [$error->getMessage()]], $error);
        }
    }
}
