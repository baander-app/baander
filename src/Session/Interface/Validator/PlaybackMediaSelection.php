<?php

declare(strict_types=1);

namespace App\Session\Interface\Validator;

use App\Shared\Domain\Model\PublicId;
use Attribute;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Attribute]
final class PlaybackMediaSelection extends Assert\Compound
{
    /**
     * @param array<string, mixed> $options
     * @return list<Assert\Collection|Assert\Callback>
     */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Collection(fields: [
                'kind' => new Assert\Choice(choices: ['song', 'video']),
                'publicId' => new Assert\Regex(pattern: PublicId::REGEX),
                'viewing' => new Assert\Optional(constraints: [
                    new Assert\NotNull(),
                    new Assert\Collection(fields: [
                        'titleKind' => [new Assert\NotBlank(), new Assert\Choice(choices: ['episode', 'movie', 'extra'])],
                        'titleId' => [new Assert\NotBlank(), new Assert\Regex(pattern: PublicId::REGEX)],
                    ]),
                ]),
            ]),
            new Assert\Callback([self::class, 'validateViewing']),
        ];
    }

    public static function validateViewing(mixed $media, ExecutionContextInterface $context): void
    {
        if (is_array($media) && array_key_exists('viewing', $media) && ($media['kind'] ?? null) !== 'video') {
            $context->buildViolation('Only video playback may carry a viewing context.')->atPath('[viewing]')->addViolation();
        }
    }
}
