<?php

declare(strict_types=1);

namespace App\Tests\Functional\Discovery;

use App\Auth\Domain\Model\User;
use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\Model\PairingSessionState;
use App\Discovery\Domain\Model\ServerInstance;
use App\Discovery\Domain\Repository\PairingSessionRepositoryInterface;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Discovery\Domain\ValueObject\PairingCode;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/discovery/complete-pairing answers an unknown, an expired and another server's code
 * with one 404 body, so the answer does not tell whether a code is live for some server, and
 * logs none of them as an error.
 */
final class CompletePairingRefusalTest extends TestCase
{
    /** Channels the request passes through: the application's, the kernel's and the bus's. */
    private const array LOGGERS = ['logger', 'monolog.logger.request', 'monolog.logger.messenger', 'monolog.logger.discovery'];

    public function testUnknownExpiredAndForeignCodesAnswerTheSameNotFoundWithoutAnErrorLog(): void
    {
        $server = $this->server('https://pairing-refusal.baander.app');
        $other = $this->server('https://pairing-refusal-other.baander.app');
        $expired = $this->storedSession($server, new DateTimeImmutable('-1 minute'));
        $foreign = $this->storedSession($other, new DateTimeImmutable('+4 minutes'));
        $user = $this->createTestUser();
        $logs = $this->captureLogs();

        $statuses = [];
        $bodies = [];
        foreach ([
            'unknown' => 'BCDF-GHJK',
            'expired' => $expired->getPairingCode()->toString(),
            'another server' => $foreign->getPairingCode()->toString(),
            'outside the code format' => 'AEIO-UAEI',
        ] as $case => $code) {
            $response = $this->complete($user, $code, $server);
            $statuses[$case] = $response->getStatusCode();
            $bodies[$case] = (string) $response->getContent();
        }

        self::assertSame([], array_map(
            static fn ($record): string => $record->level->getName() . ': ' . $record->message,
            array_values(array_filter($logs->getRecords(), static fn ($record): bool => $record->level->value >= Level::Error->value)),
        ));
        self::assertSame(array_fill_keys(array_keys($statuses), Response::HTTP_NOT_FOUND), $statuses, json_encode($bodies, JSON_THROW_ON_ERROR));
        self::assertCount(1, array_unique($bodies), json_encode($bodies, JSON_THROW_ON_ERROR));
        self::assertSame(
            ['error' => ['message' => 'Pairing code not found or expired.', 'code' => Response::HTTP_NOT_FOUND]],
            json_decode(array_first($bodies), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testThePresentingServerCompletesItsPendingCodeAndRepeatsTheAnswer(): void
    {
        $server = $this->server('https://pairing-refusal.baander.app');
        $pending = $this->storedSession($server, new DateTimeImmutable('+4 minutes'));
        $user = $this->createTestUser();

        foreach (['first', 'repeat'] as $attempt) {
            $data = $this->assertJsonResponse($this->complete($user, $pending->getPairingCode()->toString(), $server), 200, 'data')['data'];
            self::assertSame(['serverUrl' => 'https://pairing-refusal.baander.app'], $data, $attempt);
        }
    }

    public function testAMalformedServerPublicIdIsAValidationError(): void
    {
        $response = $this->authenticatedRequest('POST', '/api/discovery/complete-pairing', $this->createTestUser(), [
            'pairingCode' => 'BCDF-GHJK',
            'serverPublicId' => 'not-a-public-id',
        ]);

        $this->assertJsonResponse($response, 422);
    }

    private function complete(User $user, string $code, ServerInstance $server): Response
    {
        return $this->authenticatedRequest('POST', '/api/discovery/complete-pairing', $user, [
            'pairingCode' => $code,
            'serverPublicId' => $server->getPublicId()->toString(),
        ]);
    }

    private function server(string $url): ServerInstance
    {
        return static::getContainer()->get(ServerInstancePortInterface::class)->register($url, 'Home Server', '1.0.0', 'private-key');
    }

    private function storedSession(ServerInstance $server, DateTimeImmutable $expiresAt): PairingSession
    {
        $session = PairingSession::reconstitute(new PairingSessionState(
            id: new Uuid(),
            publicId: new PublicId(),
            serverId: $server->getId(),
            serverPublicId: $server->getPublicId(),
            serverUrl: $server->getServerUrl(),
            serverName: $server->getName(),
            pairingCode: PairingCode::generate(),
            method: AuthenticationMethod::ServerCode,
            expiresAt: $expiresAt,
            createdAt: $expiresAt->modify('-5 minutes'),
        ));
        static::getContainer()->get(PairingSessionRepositoryInterface::class)->save($session);

        return $session;
    }

    private function captureLogs(): TestHandler
    {
        $handler = new TestHandler();
        foreach (self::LOGGERS as $id) {
            $logger = static::getContainer()->get($id);
            self::assertInstanceOf(Logger::class, $logger, $id);
            $logger->pushHandler($handler);
        }

        return $handler;
    }
}
