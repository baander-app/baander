<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application\MessageHandler;

use App\Tests\Fixtures\Metadata\ProviderSwitches;

use App\Catalog\Application\Port\PersonIdentityPortInterface;
use App\Metadata\Application\Message\SyncPeopleMessage;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\MessageHandler\SyncPeopleHandler;
use App\Metadata\Application\Port\TmdbPersonLookupInterface;
use App\Shared\Application\JobCancelledException;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Tests\Fixtures\Messaging\CancelAtCheckpoint;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class SyncPeopleHandlerTest extends TestCase
{
    public function testPendingPeopleEnrichmentPreservesIdentityAndGenderMeaning(): void
    {
        $people = $this->createMock(PersonIdentityPortInterface::class);
        $people->expects(self::once())->method('pendingTmdbPeople')->with(5, '41')->willReturn(['42']);
        $people->expects(self::once())->method('enrichTmdbPerson')->willReturnCallback(
            static function (string $id, array $ids, array $profile): void {
                self::assertSame('42', $id);
                self::assertSame(['imdb_name' => 'nm123'], $ids);
                self::assertSame('non-binary', $profile['gender']);
                self::assertArrayNotHasKey('sex', $profile);
                self::assertSame(['Stage Name'], $profile['aliases']);
                self::assertSame('1950-01-02', $profile['birth_date']);
                self::assertSame('2020-03-04', $profile['death_date']);
                self::assertTrue($profile['is_deceased']);
            },
        );
        $http = new MockHttpClient(new MockResponse(json_encode([
            'id' => 42,
            'name' => 'Person',
            'gender' => 3,
            'birthday' => '1950-01-02',
            'deathday' => '2020-03-04',
            'also_known_as' => ['Stage Name', null],
            'external_ids' => ['imdb_id' => 'nm123'],
        ], JSON_THROW_ON_ERROR)));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        (new SyncPeopleHandler($people, $this->adapter($http), $bus, new CancelAtCheckpoint()))(new SyncPeopleMessage('41'));
    }

    public function testFullPageQueuesBoundedContinuationAndSkipsMismatchedProfiles(): void
    {
        $ids = array_map(strval(...), range(1, 5));
        $people = $this->createMock(PersonIdentityPortInterface::class);
        $people->expects(self::once())->method('pendingTmdbPeople')->with(5, null)->willReturn($ids);
        $people->expects(self::never())->method('enrichTmdbPerson');
        $http = new MockHttpClient(static fn(): MockResponse => new MockResponse('{"id":999}'));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(new SyncPeopleMessage('5'))->willReturn(new Envelope(new SyncPeopleMessage('5')));
        $cancellation = new CancelAtCheckpoint();

        (new SyncPeopleHandler($people, $this->adapter($http), $bus, $cancellation))(new SyncPeopleMessage());

        self::assertSame(5, $cancellation->checks);
    }

    public function testNoCredentialsLeavesPendingPeopleAvailableForLaterSync(): void
    {
        $people = $this->createMock(PersonIdentityPortInterface::class);
        $people->expects(self::never())->method('pendingTmdbPeople');
        $people->expects(self::never())->method('enrichTmdbPerson');
        $http = new MockHttpClient(static function (): never {
            self::fail('An unconfigured sync must make no provider requests.');
        });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        (new SyncPeopleHandler($people, $this->adapter($http, ''), $bus, new CancelAtCheckpoint()))(new SyncPeopleMessage());
    }

    public function testDisabledProviderLeavesPendingPeopleUnclaimed(): void
    {
        $people = $this->createMock(PersonIdentityPortInterface::class);
        $people->expects(self::never())->method('pendingTmdbPeople');
        $people->expects(self::never())->method('enrichTmdbPerson');
        $http = new MockHttpClient(static function (): never {
            self::fail('A disabled person provider must make no request.');
        });
        $tmdb = new TmdbAdapter('fixture-token', new NullLogger(), new JsonEncoder(), new PublicHttpClient(
            $http,
            new PublicDestinationPolicy(static fn (string $host): array => ['1.1.1.1']),
        ), ProviderSwitches::withDisabled(MetadataProvider::Tmdb));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        (new SyncPeopleHandler($people, $tmdb, $bus, new CancelAtCheckpoint()))(new SyncPeopleMessage());
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testCancellationLeavesClaimRecoveryToTheLeaseWithoutCallingTheProvider(): void
    {
        $people = $this->createMock(PersonIdentityPortInterface::class);
        $people->expects(self::once())->method('pendingTmdbPeople')->with(5, null)->willReturn(['42']);
        $people->expects(self::never())->method('enrichTmdbPerson');
        $tmdb = $this->createMock(TmdbPersonLookupInterface::class);
        $tmdb->method('isConfigured')->willReturn(true);
        $tmdb->method('isEnabled')->willReturn(true);
        $tmdb->expects(self::never())->method('lookupPerson');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $this->expectException(JobCancelledException::class);

        (new SyncPeopleHandler($people, $tmdb, $bus, new CancelAtCheckpoint(passes: 0)))(new SyncPeopleMessage());
    }

    public function testProviderFailureKeepsTheFailedPageForLeaseRecovery(): void
    {
        $people = $this->createMock(PersonIdentityPortInterface::class);
        $people->expects(self::once())->method('pendingTmdbPeople')->with(5, null)->willReturn(['42', '43']);
        $people->expects(self::never())->method('enrichTmdbPerson');
        $tmdb = $this->createMock(TmdbPersonLookupInterface::class);
        $tmdb->method('isConfigured')->willReturn(true);
        $tmdb->method('isEnabled')->willReturn(true);
        $tmdb->expects(self::once())->method('lookupPerson')->with(42)->willThrowException(new \RuntimeException('Provider unavailable.'));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        try {
            (new SyncPeopleHandler($people, $tmdb, $bus, new CancelAtCheckpoint()))(new SyncPeopleMessage());
            self::fail('A provider outage must schedule a retry after claim expiry.');
        } catch (RecoverableMessageHandlingException $exception) {
            self::assertSame(300100, $exception->getRetryDelay());
            self::assertFalse($exception->forceRetry());
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    private function adapter(MockHttpClient $http, string $token = 'fixture-token'): TmdbAdapter
    {
        return new TmdbAdapter($token, new NullLogger(), new JsonEncoder(), new PublicHttpClient(
            $http,
            new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']),
        ), ProviderSwitches::allEnabled());
    }
}
