<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Shared\Infrastructure\Doctrine\Type\InstantType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class InstantTypeTest extends TestCase
{
    private InstantType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new InstantType();
        $this->platform = new PostgreSQLPlatform();
    }

    public function testItDeclaresTimestamptzWithoutPrecision(): void
    {
        self::assertSame('TIMESTAMPTZ', $this->type->getSQLDeclaration(['precision' => 0], $this->platform));
    }

    public function testItWritesTheUtcInstantWithOffsetAndMicroseconds(): void
    {
        $kathmandu = new \DateTimeImmutable('2026-10-11 03:44:59.654321', new \DateTimeZone('Asia/Kathmandu'));

        self::assertSame('2026-10-10 21:59:59.654321+00:00', $this->type->convertToDatabaseValue($kathmandu, $this->platform));
    }

    public function testNullRoundTripsAsNull(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testItRejectsAMutableDateTime(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToDatabaseValue(new \DateTime('2026-10-10 21:59:59'), $this->platform);
    }

    /** @return iterable<string, array{string, string}> PostgreSQL text => the UTC instant it names */
    public static function postgresText(): iterable
    {
        yield 'whole second, UTC session' => ['2026-10-10 21:59:59+00', '2026-10-10 21:59:59.000000'];
        yield 'microseconds, UTC session' => ['2026-10-10 21:59:59.654321+00', '2026-10-10 21:59:59.654321'];
        yield 'trimmed fraction' => ['2026-10-10 21:59:59.5+00', '2026-10-10 21:59:59.500000'];
        yield 'Kathmandu session' => ['2026-10-11 03:44:59.654321+05:45', '2026-10-10 21:59:59.654321'];
        yield 'New York session' => ['2026-10-10 17:59:59.654321-04', '2026-10-10 21:59:59.654321'];
        yield 'zone-less column' => ['2026-03-29 02:30:00', '2026-03-29 02:30:00.000000'];
    }

    #[DataProvider('postgresText')]
    public function testItReadsPostgresTextAsAUtcInstant(string $text, string $utc): void
    {
        $instant = $this->type->convertToPHPValue($text, $this->platform);

        self::assertInstanceOf(\DateTimeImmutable::class, $instant);
        self::assertSame($utc, $instant->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $instant->getTimezone()->getName());
        self::assertStringEndsWith('+00:00', $instant->format(DATE_ATOM));
    }

    public function testAZoneLessValueIsUtcWhateverPhpsZone(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            $instant = $this->type->convertToPHPValue('2026-10-10 21:59:59', $this->platform);
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertSame('2026-10-10T21:59:59+00:00', $instant?->format(DATE_ATOM));
    }

    public function testAnObjectIsReturnedInUtc(): void
    {
        $kathmandu = new \DateTimeImmutable('2026-10-11 03:44:59.654321', new \DateTimeZone('Asia/Kathmandu'));

        self::assertSame('2026-10-10T21:59:59.654321+00:00', $this->type->convertToPHPValue($kathmandu, $this->platform)?->format('Y-m-d\TH:i:s.uP'));
    }

    /** @return iterable<string, array{string}> */
    public static function notAnInstant(): iterable
    {
        yield 'relative' => ['tomorrow'];
        yield 'month 13' => ['2026-13-01 00:00:00+00'];
        yield 'seven fraction digits' => ['2026-10-10 21:59:59.1234567+00'];
        yield 'infinity' => ['infinity'];
    }

    #[DataProvider('notAnInstant')]
    public function testItRejectsTextThatIsNotAnInstant(string $text): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue($text, $this->platform);
    }

    public function testTheRegistrarInstallsItUnderBothDoctrineNames(): void
    {
        CustomTypesRegistrar::register();

        self::assertInstanceOf(InstantType::class, Type::getType(Types::DATETIME_IMMUTABLE));
        self::assertInstanceOf(InstantType::class, Type::getType(Types::DATETIMETZ_IMMUTABLE));
    }

    /** The Swoole kernel reads the registrar; DoctrineBundle reads the configuration. */
    public function testTheRegistrarAndTheConfigurationNameTheSameTypes(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 6) . '/config/packages/doctrine.yaml');
        $registrar = (new \ReflectionClassConstant(CustomTypesRegistrar::class, 'TYPES'))->getValue();
        self::assertIsArray($registrar);
        ksort($registrar);
        $configured = $config['doctrine']['dbal']['types'];
        ksort($configured);

        self::assertSame($registrar, $configured);
        self::assertSame(Types::DATETIME_IMMUTABLE, $config['doctrine']['dbal']['mapping_types']['timestamptz']);
    }
}
