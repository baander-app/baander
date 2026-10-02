<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Application\DTO;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchedulerOccurrenceTest extends TestCase
{
    public function testEquivalentOffsetInstantIsNormalizedToExactUtcMinute(): void
    {
        $local = new DateTimeImmutable('2026-10-02T10:15:00+02:00');
        $occurrence = $this->occurrence(scheduledFor: $local);
        self::assertSame('2026-10-02T08:15:00+00:00', $occurrence->scheduledFor->format('c'));
        self::assertSame('UTC', $occurrence->scheduledFor->getTimezone()->getName());
        self::assertSame('10:15:00', $local->format('H:i:s'), 'Normalization does not mutate the caller instant.');
    }

    #[DataProvider('nonMinuteInstants')]
    public function testRejectsSecondsAndMicrosecondsInsteadOfTruncating(string $instant): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->occurrence(scheduledFor: new DateTimeImmutable($instant));
    }

    /** @return iterable<string,array{string}> */
    public static function nonMinuteInstants(): iterable
    {
        yield 'seconds' => ['2026-10-02T08:15:01Z'];
        yield 'fraction' => ['2026-10-02T08:15:00.000001Z'];
        yield 'offset with seconds' => ['2026-10-02T08:15:00+00:00:30'];
    }

    public function testCopiesNestedReferencesAndPreservesInvocationOrderAndFloatTokens(): void
    {
        $referenced = 'original';
        $nested = ['referenced' => &$referenced];
        $parameters = [1 => 'first-positional', 0 => 'second-positional', 'named' => &$nested, 'integralFloat' => 1.0, 'largeFloat' => 1e18, 'negativeZero' => -0.0];
        $occurrence = $this->occurrence(parameters: $parameters);
        $encoded = $occurrence->parametersJson();
        $referenced = 'mutated';
        $nested['new'] = 'later';
        $parameters[1] = 'changed';
        self::assertSame([1, 0, 'named', 'integralFloat', 'largeFloat', 'negativeZero'], array_keys($occurrence->parameters));
        self::assertSame('first-positional', $occurrence->parameters[1]);
        self::assertSame(['referenced' => 'original'], $occurrence->parameters['named']);
        self::assertSame($encoded, $occurrence->parametersJson());
        self::assertStringContainsString('"integralFloat":1.0', $encoded);
        self::assertStringContainsString('"largeFloat":1.0e+18', $encoded);
        self::assertStringContainsString('"negativeZero":-0.0', $encoded);
        self::assertSame($occurrence->parameters, json_decode($encoded, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testReadonlySnapshotCannotBeMutatedThroughItsPublicArray(): void
    {
        $occurrence = $this->occurrence(parameters: ['nested' => ['value' => 'original']]);
        $this->expectException(Error::class);
        (new \ReflectionProperty($occurrence, 'parameters'))->setValue($occurrence, ['nested' => ['value' => 'changed']]);
    }

    public function testListsAndMapsKeepTheirDistinctShapesAndOrdering(): void
    {
        self::assertSame('["first","second"]', $this->occurrence(parameters: ['first', 'second'])->parametersJson());
        self::assertSame('{"1":"first","0":"second"}', $this->occurrence(parameters: [1 => 'first', 0 => 'second'])->parametersJson());
        self::assertSame('{"z":1,"a":2}', $this->occurrence(parameters: ['z' => 1, 'a' => 2])->parametersJson());
    }

    public function testExactCommandJsonAndNestingLimitsAreAccepted(): void
    {
        self::assertSame(512, strlen($this->occurrence(command: str_repeat('c', 512))->command));
        self::assertSame(16384, strlen($this->occurrence(parameters: [str_repeat('x', 16380)])->parametersJson()));
        $nested = ['leaf'];
        for ($level = 1; $level < 8; ++$level) {
            $nested = [$nested];
        }
        self::assertSame($nested, $this->occurrence(parameters: $nested)->parameters);
    }

    #[DataProvider('invalidCommands')]
    public function testRejectsInvalidCommand(string $command): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->occurrence(command: $command);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidCommands(): iterable
    {
        yield 'empty' => [''];
        yield 'over budget' => [str_repeat('c', 513)];
        yield 'NUL' => ["app:command\0unsafe"];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsUnsupportedOrUnboundedParameterValues(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->occurrence(parameters: [$value]);
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'object' => [new \stdClass()];
        yield 'infinite' => [INF];
        yield 'NaN' => [NAN];
        yield 'invalid UTF8' => ["\xff"];
        yield 'JSON over budget' => [str_repeat('x', 16381)];
        yield 'encoded escapes exceed budget' => [str_repeat("\n", 8191)];
        $nested = ['leaf'];
        for ($level = 1; $level < 8; ++$level) {
            $nested = [$nested];
        }
        yield 'ninth array level' => [$nested];
    }

    public function testAggregateStringsAreRejectedBeforeAllocatingOversizedJson(): void
    {
        try {
            $this->occurrence(parameters: [str_repeat('x', 8193), str_repeat('y', 8193)]);
            self::fail('Aggregate strings must respect the traversal byte budget.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('bounded JSON budget', $error->getMessage());
        }
    }

    public function testResourceCannotEnterSnapshot(): void
    {
        $resource = tmpfile();
        self::assertIsResource($resource);
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->occurrence(parameters: [$resource]);
        } finally {
            fclose($resource);
        }
    }

    public function testCyclicReferenceFailsAtBoundedDepth(): void
    {
        $parameters = [];
        $parameters['self'] = &$parameters;
        $this->expectException(InvalidArgumentException::class);
        $this->occurrence(parameters: $parameters);
    }

    /** @param array<array-key,mixed> $parameters */
    private function occurrence(?DateTimeImmutable $scheduledFor = null, string $command = 'app:occurrence', array $parameters = []): SchedulerOccurrence
    {
        return new SchedulerOccurrence(new Uuid(), new Uuid(), $scheduledFor ?? new DateTimeImmutable('2026-10-02T08:15:00Z'), JobType::Console, $command, $parameters);
    }
}
