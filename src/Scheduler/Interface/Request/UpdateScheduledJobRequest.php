<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Request;

use App\Scheduler\Interface\Validator\CronExpression;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateScheduledJobRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,

        #[Assert\NotBlank]
        #[CronExpression]
        public string $expression,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['messenger', 'console'])]
        public string $jobType,

        #[Assert\NotBlank]
        public string $command,

        public ?string $description = null,

        public array $parameters = [],
    ) {
    }
}
