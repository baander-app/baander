<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;
use Doctrine\DBAL\ArrayParameterType;

/**
 * UserRepository::findAll serves the admin user list and app:user:list: newest first, with
 * role and disabled filters, and a page of the result. It loads the page in one query.
 */
final class UserListTest extends TestCase
{
    /** @var array<string, string> label => email */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Created after any other user in the database, so these four lead the newest-first list.
        $users = [
            'regular' => [$this->createTestUser(), '2100-01-04 00:00:00+00', false],
            'admin' => [$this->createAdminUser(), '2100-01-03 00:00:00+00', false],
            'disabled' => [$this->createTestUser(), '2100-01-02 00:00:00+00', true],
            'disabled admin' => [$this->createAdminUser(), '2100-01-01 00:00:00+00', true],
        ];

        $connection = $this->entityManager->getConnection();
        foreach ($users as $label => [$user, $createdAt, $disabled]) {
            $connection->executeStatement(
                'UPDATE users SET created_at = :created_at, disabled = :disabled WHERE id = :id',
                ['created_at' => $createdAt, 'disabled' => $disabled ? 'true' : 'false', 'id' => $user->getId()->toString()],
            );
            $this->emails[$label] = $user->getEmail();
        }
        $this->entityManager->clear();
    }

    public function testUsersAreListedNewestFirstAndPaged(): void
    {
        self::assertSame(['regular', 'admin', 'disabled', 'disabled admin'], $this->labels($this->userRepository->findAll(limit: 4)));
        self::assertSame(['admin', 'disabled'], $this->labels($this->userRepository->findAll(limit: 2, offset: 1)));
    }

    public function testUsersCreatedInTheSameSecondArePagedOnceEach(): void
    {
        $tied = [$this->createTestUser(), $this->createTestUser(), $this->createTestUser()];
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE users SET created_at = '2100-02-01 00:00:00+00' WHERE id IN (:ids)",
            ['ids' => array_map(static fn (User $user): string => $user->getId()->toString(), $tied)],
            ['ids' => ArrayParameterType::STRING],
        );
        $this->entityManager->clear();

        $paged = [];
        foreach ([0, 1, 2] as $offset) {
            $paged[] = $this->userRepository->findAll(limit: 1, offset: $offset)[0]->getId()->toString();
        }

        $expected = array_map(static fn (User $user): string => $user->getId()->toString(), $tied);
        rsort($expected);
        self::assertSame($expected, $paged, 'Ties on the creation time are ordered by id, newest id first.');
    }

    public function testFiltersNarrowTheListByRoleAndDisabledState(): void
    {
        self::assertSame(['admin', 'disabled admin'], $this->labels($this->userRepository->findAll('ROLE_ADMIN', limit: 2)));
        self::assertSame(['disabled', 'disabled admin'], $this->labels($this->userRepository->findAll(disabledFilter: true, limit: 2)));
        self::assertSame(['admin'], $this->labels($this->userRepository->findAll('ROLE_ADMIN', false, 1)));
    }

    public function testListedUsersCarryTheirStoredState(): void
    {
        [$disabledAdmin] = $this->userRepository->findAll('ROLE_ADMIN', true, 1);

        self::assertSame($this->emails['disabled admin'], $disabledAdmin->getEmail());
        self::assertTrue($disabledAdmin->isDisabled());
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $disabledAdmin->getRoles());
        self::assertEquals(new \DateTimeImmutable('2100-01-01 00:00:00+00:00'), $disabledAdmin->getCreatedAt());
    }

    /**
     * @param list<User> $users
     *
     * @return list<string>
     */
    private function labels(array $users): array
    {
        $labels = array_flip($this->emails);

        return array_map(static fn (User $user): string => $labels[$user->getEmail()] ?? $user->getEmail(), $users);
    }
}
