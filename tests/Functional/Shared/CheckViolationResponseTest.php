<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\MemberInputBoundViolation;
use App\Tests\Functional\TestCase;
use Doctrine\DBAL\Exception\DriverException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * The transaction port answers a violated member-input bound as a conflict. A violation of any
 * other check is a defect in the code that wrote the row, and stays a logged 500.
 */
final class CheckViolationResponseTest extends TestCase
{
    public function testAViolatedCheckThatIsNotAMemberInputBoundIsALogged500(): void
    {
        $user = $this->createTestUser();
        $source = $this->song($user);
        $target = $this->song($user);
        $connection = $this->entityManager->getConnection();
        // Every member row now violates the origin check, as a defect in its writer would.
        $connection->executeStatement('ALTER TABLE recommendations DROP CONSTRAINT chk_recommendations_origin');
        $connection->executeStatement("ALTER TABLE recommendations ADD CONSTRAINT chk_recommendations_origin CHECK (origin = 'generator') NOT VALID");
        $logs = new TestHandler();
        $logger = static::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($logs);

        $response = $this->authenticatedRequest('POST', '/api/recommendations/', $user, ['source_type' => 'song', 'source_id' => $source, 'target_type' => 'song', 'target_id' => $target, 'score' => 0.9]);

        self::assertSame(['error' => ['message' => 'An unexpected error occurred.', 'code' => 500]], $this->assertJsonResponse($response, 500));
        $violations = array_filter(
            $logs->getRecords(),
            static fn (LogRecord $record): bool => ($record->context['exception'] ?? null) instanceof DriverException
                && str_contains($record->context['exception']->getMessage(), '"chk_recommendations_origin"'),
        );
        self::assertCount(1, $violations);
    }

    public function testEveryMemberInputBoundTheConflictNamesIsACheckOfTheSchema(): void
    {
        self::assertSame(MemberInputBoundViolation::CONSTRAINTS, $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE contype = 'c' AND connamespace = current_schema()::regnamespace AND conname = ANY (string_to_array(?, ',')) ORDER BY conname COLLATE \"C\"",
            [implode(',', MemberInputBoundViolation::CONSTRAINTS)],
        ));
    }

    /** @return string the public id of a song the user may read */
    private function song(User $user): string
    {
        $connection = $this->entityManager->getConnection();
        $library = Uuid::v7()->toString();
        $album = Uuid::v7()->toString();
        $song = Uuid::v7()->toString();
        $public = new PublicId()->toString();
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s.uP');
        $connection->insert('libraries', ['id' => $library, 'public_id' => new PublicId()->toString(), 'slug' => 'check-violation-' . (random_bytes(6) |> bin2hex(...)), 'name' => 'Check violation fixture', 'path' => '/tmp/check-violation-' . (random_bytes(6) |> bin2hex(...)), 'type' => 'music', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $connection->insert('user_library_access', ['user_id' => $user->getId()->toString(), 'library_id' => $library, 'granted_at' => $now]);
        $connection->insert('albums', ['id' => $album, 'public_id' => new PublicId()->toString(), 'library_id' => $library, 'title' => 'Check violation album', 'type' => 'studio', 'locked_fields' => '{}', 'merged_from' => '[]', 'created_at' => $now, 'updated_at' => $now]);
        $connection->insert('songs', ['id' => $song, 'public_id' => $public, 'album_id' => $album, 'path' => '/tmp/' . $public . '.mp3', 'hash' => hash('sha256', $song), 'title' => 'Check violation song', 'size' => 1000, 'mime_type' => 'audio/mpeg', 'created_at' => $now, 'updated_at' => $now]);

        return $public;
    }
}
