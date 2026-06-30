<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Interface\Validator;

use App\Scheduler\Interface\Validator\CronExpression;
use App\Scheduler\Interface\Validator\CronExpressionValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

final class CronExpressionValidatorTest extends TestCase
{
    private CronExpressionValidator $validator;
    private ExecutionContextInterface&\PHPUnit\Framework\MockObject\MockObject $context;

    protected function setUp(): void
    {
        $this->validator = new CronExpressionValidator();
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    // --- Valid expressions ---

    #[DataProvider('validExpressionProvider')]
    public function testValidExpressionsPass(string $expression): void
    {
        $constraint = new CronExpression();

        // No violations should be built
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($expression, $constraint);
    }

    public static function validExpressionProvider(): array
    {
        return [
            'every minute' => ['* * * * *'],
            'hourly' => ['0 * * * *'],
            'daily at midnight' => ['0 0 * * *'],
            'every 5 minutes' => ['*/5 * * * *'],
            'weekly on monday' => ['0 0 * * 1'],
            'monthly on 1st' => ['0 0 1 * *'],
            'specific range' => ['0-30/5 * * * *'],
            'multiple values' => ['0 9,17 * * *'],
        ];
    }

    // --- Invalid expressions ---

    public function testInvalidExpressionTriggersViolation(): void
    {
        $constraint = new CronExpression();

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('setParameter')
            ->with('{{ error }}', $this->callback(fn (string $v) => strlen($v) > 0))
            ->willReturnSelf();
        $violationBuilder->expects($this->once())->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($constraint->message)
            ->willReturn($violationBuilder);

        // Pass something that will definitely fail cron parsing
        $this->validator->validate('not-a-cron-expression-12345', $constraint);
    }

    // --- Null and empty values ---

    public function testNullPassesWithoutViolation(): void
    {
        $constraint = new CronExpression();
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate(null, $constraint);
    }

    public function testEmptyStringPassesWithoutViolation(): void
    {
        $constraint = new CronExpression();
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate('', $constraint);
    }

    // --- Wrong constraint type ---

    public function testWrongConstraintTypeThrowsException(): void
    {
        $wrongConstraint = $this->createMock(Constraint::class);

        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('* * * * *', $wrongConstraint);
    }
}
