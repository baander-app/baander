<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use App\Shared\Domain\Model\Setting\SettingViolation;
use RuntimeException;

/**
 * A settings write was rejected; nothing was written.
 */
final class InvalidSettingValuesException extends RuntimeException
{
    /**
     * @param non-empty-list<SettingViolation> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(implode(' ', array_map(
            static fn (SettingViolation $violation): string => sprintf('%s: %s', $violation->key, $violation->message),
            $violations,
        )));
    }

    /**
     * Messages per setting key, in the validation error envelope's shape.
     *
     * @return array<string, list<string>>
     */
    public function messagesByKey(): array
    {
        $messages = [];
        foreach ($this->violations as $violation) {
            $messages[$violation->key][] = $violation->message;
        }

        return $messages;
    }
}
