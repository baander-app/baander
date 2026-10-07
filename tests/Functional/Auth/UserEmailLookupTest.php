<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Shared\Domain\Model\Email;
use App\Tests\Functional\TestCase;

/**
 * Login, password reset and registration find users by email through UserRepository.
 * users.email is CITEXT with the unique index uniq_users_email.
 */
final class UserEmailLookupTest extends TestCase
{
    public function testLookupIgnoresTheCaseOfTheStoredAddress(): void
    {
        $user = $this->createTestUser('mixed.case@baander.app');
        // Email lowercases its input, so only rows written outside it (imports, manual edits) keep
        // upper case. CITEXT must still match them.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE users SET email = :email WHERE id = :id',
            ['email' => 'Mixed.CASE@Baander.App', 'id' => $user->getId()->toString()],
        );
        $this->entityManager->clear();

        foreach (['mixed.case@baander.app', 'MIXED.CASE@BAANDER.APP', 'Mixed.Case@baander.app'] as $input) {
            $found = $this->userRepository->findByEmail(new Email($input));
            self::assertNotNull($found, $input);
            self::assertTrue($user->getId()->equals($found->getId()), $input);
            self::assertTrue($this->userRepository->existsWithEmail(new Email($input)), $input);
        }

        self::assertNull($this->userRepository->findByEmail(new Email('nobody.case@baander.app')));
        self::assertFalse($this->userRepository->existsWithEmail(new Email('other.case@baander.app')));
    }

    public function testLookupIsServedByTheUniqueEmailIndex(): void
    {
        $user = $this->createTestUser('indexed.lookup@baander.app');
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('ANALYZE users');
        // The test table holds a handful of rows, so a sequential scan is cheapest whatever the
        // predicate. With it disabled, the planner still scans sequentially when no index can
        // answer the predicate, as with LOWER(email) = LOWER(:email).
        $connection->executeStatement('SET LOCAL enable_seqscan = off');

        $before = $this->scans();
        $found = $this->userRepository->findByEmail(new Email('INDEXED.Lookup@baander.app'));
        $after = $this->scans();

        self::assertNotNull($found);
        self::assertTrue($user->getId()->equals($found->getId()));
        self::assertSame(1, $after['email_index'] - $before['email_index'], 'uniq_users_email serves the lookup.');
        self::assertSame(0, $after['sequential'] - $before['sequential'], 'The lookup does not scan users sequentially.');
    }

    /**
     * Scans in the current transaction, which include counts not yet flushed to the cumulative
     * statistics. The functional tests run inside one transaction.
     *
     * @return array{email_index: int, sequential: int}
     */
    private function scans(): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT pg_stat_get_xact_numscans('uniq_users_email'::regclass) AS email_index,
                    pg_stat_get_xact_numscans('users'::regclass) AS sequential",
        );
        self::assertIsArray($row);

        return ['email_index' => (int) $row['email_index'], 'sequential' => (int) $row['sequential']];
    }
}
