<?php

declare(strict_types=1);

namespace App\Shared\Interface\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class JsonEncodedSizeValidator extends ConstraintValidator
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof JsonEncodedSize) {
            throw new UnexpectedTypeException($constraint, JsonEncodedSize::class);
        }

        if ($value === null) {
            return;
        }

        try {
            $size = json_encode($value, self::FLAGS) |> strlen(...);
        } catch (\JsonException) {
            $this->context->buildViolation($constraint->unencodableMessage)->addViolation();

            return;
        }

        if ($size > $constraint->max) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ limit }}', (string) $constraint->max)
                ->addViolation();
        }
    }
}
