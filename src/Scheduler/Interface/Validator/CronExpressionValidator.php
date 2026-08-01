<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Validator;

use Cron\CronExpression as CronLib;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class CronExpressionValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CronExpression) {
            throw new UnexpectedTypeException($constraint, CronExpression::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        try {
            new CronLib($value);
        } catch (\Throwable $e) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ error }}', $e->getMessage())
                ->addViolation();
        }
    }
}
