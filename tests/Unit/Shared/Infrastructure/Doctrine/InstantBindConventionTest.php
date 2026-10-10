<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Tests\Support\Doctrine\InstantBindScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Application code binds an instant with the project instant type (`Types::DATETIME_IMMUTABLE`)
 * or as a `Y-m-d H:i:s.uP` string, never zone-less or to the whole second. API output keeps its
 * whole-second format; it lives outside database code or is listed below.
 */
final class InstantBindConventionTest extends TestCase
{
    private const string TYPE_REGISTRAR = 'src/Shared/Infrastructure/Doctrine/Type/CustomTypesRegistrar.php';

    /**
     * Database code that formats an instant for output, not for a bind: path => number of uses.
     * Add an entry only after checking that the value never reaches SQL.
     */
    private const array OUTPUT_FORMATS = [
        // The instant type's read formats: PostgreSQL's own text, with and without fraction or zone.
        'src/Shared/Infrastructure/Doctrine/Type/InstantType.php' => 3,
        // The API `lastSyncAt` field, read from PostgreSQL text.
        'src/Lyrics/Infrastructure/Doctrine/Repository/LyricsAdminRepository.php' => 1,
        'src/Metadata/Infrastructure/Admin/MetadataAdminService.php' => 1,
        // The API `last_generated_at` field.
        'src/Recommendation/Infrastructure/Doctrine/Repository/RecommendationInsightsRepository.php' => 1,
        // The webhook payload's `timestamp` field.
        'src/Notification/Infrastructure/Webhook/WebhookDeliveryService.php' => 1,
        // The analytics `failedAt` API field and the `at` field inside the audit-log JSON.
        'src/Shared/Infrastructure/Messenger/JobMonitorService.php' => 2,
    ];

    public function testApplicationCodeBindsInstantsExactly(): void
    {
        $violations = InstantBindScanner::scan(dirname(__DIR__, 5), 'src', self::OUTPUT_FORMATS, self::TYPE_REGISTRAR);

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /** @return iterable<string, array{string, string}> */
    public static function inexactBinds(): iterable
    {
        yield 'zone-less string bind' => [<<<'PHP'
            <?php
            use Doctrine\DBAL\Connection;
            final class Probe
            {
                public function __construct(private Connection $connection) {}
                public function touch(\DateTimeImmutable $at): void
                {
                    $this->connection->executeStatement('UPDATE t SET at = ?', [$at->format('Y-m-d H:i:s')]);
                }
            }
            PHP, "'Y-m-d H:i:s'"];
        yield 'zone-less bind through PDO' => [<<<'PHP'
            <?php
            final class Probe
            {
                public function touch(\PDO $pdo): void
                {
                    $pdo->prepare('UPDATE t SET at = ?')->execute([(new \DateTime())->format("Y-m-d H:i:s.u")]);
                }
            }
            PHP, '"Y-m-d H:i:s.u"'];
        yield 'whole-second offset bind' => [<<<'PHP'
            <?php
            use Doctrine\DBAL\Connection;
            final class Probe
            {
                public function __construct(private Connection $connection) {}
                public function touch(\DateTimeImmutable $at): void
                {
                    $this->connection->insert('t', ['at' => $at->format(\DateTimeImmutable::ATOM)]);
                }
            }
            PHP, '\DateTimeImmutable::ATOM'];
        yield 'global whole-second constant' => [<<<'PHP'
            <?php
            use Doctrine\ORM\EntityManagerInterface;
            final class Probe
            {
                public function __construct(private EntityManagerInterface $manager) {}
                public function touch(\DateTimeImmutable $at): void
                {
                    $this->manager->getConnection()->executeStatement('UPDATE t SET at = ?', [$at->format(DATE_RFC3339)]);
                }
            }
            PHP, 'DATE_RFC3339'];
        yield 'ISO 8601 letter' => [<<<'PHP'
            <?php
            use Doctrine\DBAL\Connection;
            final class Probe
            {
                public function __construct(private Connection $connection) {}
                public function touch(\DateTimeImmutable $at): void
                {
                    $this->connection->executeStatement('UPDATE t SET at = ?', [$at->format('c')]);
                }
            }
            PHP, "'c'"];
        yield 'datetimetz type constant' => [<<<'PHP'
            <?php
            use Doctrine\DBAL\Connection;
            use Doctrine\DBAL\Types\Types;
            final class Probe
            {
                public function __construct(private Connection $connection) {}
                public function touch(\DateTimeImmutable $at): void
                {
                    $this->connection->executeStatement('UPDATE t SET at = :at', ['at' => $at], ['at' => Types::DATETIMETZ_IMMUTABLE]);
                }
            }
            PHP, 'Types::DATETIMETZ_IMMUTABLE'];
        yield 'mutable type constant' => [<<<'PHP'
            <?php
            final class Probe
            {
                public function touch(\Doctrine\ORM\QueryBuilder $query, \DateTime $at): void
                {
                    $query->setParameter('at', $at, \Doctrine\DBAL\Types\Types::DATETIME_MUTABLE);
                }
            }
            PHP, 'Types::DATETIME_MUTABLE'];
        yield 'type name string' => [<<<'PHP'
            <?php
            use Doctrine\DBAL\Connection;
            final class Probe
            {
                public function __construct(private Connection $connection) {}
                public function touch(\DateTimeImmutable $at): void
                {
                    $this->connection->executeStatement('UPDATE t SET at = :at', ['at' => $at], ['at' => 'datetimetz_immutable']);
                }
            }
            PHP, "'datetimetz_immutable'"];
    }

    #[DataProvider('inexactBinds')]
    public function testAnInexactBindFails(string $source, string $offender): void
    {
        $violations = InstantBindScanner::scanSource('src/Probe.php', $source);

        self::assertCount(1, $violations, implode("\n", $violations));
        self::assertStringStartsWith('src/Probe.php:', $violations[0]);
        self::assertStringContainsString($offender, $violations[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function exactBindsAndOutput(): iterable
    {
        yield 'instant type bind' => [<<<'PHP'
            <?php
            use Doctrine\DBAL\Connection;
            use Doctrine\DBAL\Types\Types;
            final class Probe
            {
                public function __construct(private Connection $connection) {}
                public function touch(\DateTimeImmutable $at): void
                {
                    $this->connection->executeStatement('UPDATE t SET at = :at', ['at' => $at], ['at' => Types::DATETIME_IMMUTABLE]);
                }
            }
            PHP];
        yield 'offset and fraction string bind' => [<<<'PHP'
            <?php
            final class Probe
            {
                public function touch(\PDO $pdo, \DateTimeImmutable $at): void
                {
                    $pdo->prepare('UPDATE t SET at = ?')->execute([$at->format('Y-m-d H:i:s.uP')]);
                }
            }
            PHP];
        yield 'API resource outside database code' => [<<<'PHP'
            <?php
            final class ProbeResource
            {
                public function __construct(private \DateTimeImmutable $createdAt) {}
                /** Bind Types::DATETIMETZ_IMMUTABLE? No: comments never count, nor does 'Y-m-d H:i:s' here. */
                public function toArray(): array
                {
                    return ['createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM), 'day' => $this->createdAt->format('c')];
                }
            }
            PHP];
        yield 'console display outside database code' => [<<<'PHP'
            <?php
            final class ProbeCommand
            {
                public function describe(\DateTimeImmutable $cutoff): string
                {
                    return sprintf('Pruned before %s.', $cutoff->format('Y-m-d H:i:s'));
                }
            }
            PHP];
    }

    #[DataProvider('exactBindsAndOutput')]
    public function testExactBindsAndOutputFormattingPass(string $source): void
    {
        self::assertSame([], InstantBindScanner::scanSource('src/Probe.php', $source));
    }

    public function testAnAllowanceCoversOnlyItsReviewedCount(): void
    {
        $source = <<<'PHP'
            <?php
            use Doctrine\DBAL\Connection;
            final class Probe
            {
                public function __construct(private Connection $connection) {}
                public function lastSync(): string
                {
                    return (new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT max(at) FROM t')))->format(DATE_ATOM);
                }
            }
            PHP;

        self::assertSame([], InstantBindScanner::scanSource('src/Probe.php', $source, 1));
        self::assertCount(1, InstantBindScanner::scanSource('src/Probe.php', $source, 0), 'An unreviewed use fails.');
        self::assertCount(1, InstantBindScanner::scanSource('src/Probe.php', $source, 2), 'A stale allowance fails.');
    }
}
