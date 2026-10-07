<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application;

use App\Library\Application\Port\LibraryPortInterface;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\MetadataSyncOrchestrator;
use App\Metadata\Application\Service\AlbumMetadataSyncRequester;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Application\Service\SystemSettings;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AlbumMetadataSyncRequestTest extends TestCase
{
    public function testToggleOnQueuesOneSyncPerAlbum(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $messages = [];

        $this->requester([true], $messages)->requestSync($first, $second);

        self::assertCount(2, $messages);
        foreach ([$first, $second] as $index => $albumId) {
            $message = $messages[$index];
            self::assertInstanceOf(SyncAlbumMessage::class, $message);
            self::assertTrue($message->albumId->equals($albumId));
            self::assertFalse($message->forceUpdate);
        }
    }

    public function testToggleOffQueuesNothing(): void
    {
        $messages = [];

        $this->requester([false], $messages)->requestSync(Uuid::v7(), Uuid::v7());

        self::assertSame([], $messages);
    }

    public function testUnsetToggleFollowsTheOffDefault(): void
    {
        $messages = [];

        $this->requester([null], $messages)->requestSync(Uuid::v7());

        self::assertSame([], $messages);
    }

    public function testEveryRequestReadsTheToggleAfresh(): void
    {
        $messages = [];
        $requester = $this->requester([false, true], $messages);

        $requester->requestSync(Uuid::v7());
        self::assertSame([], $messages, 'The first request sees the toggle off.');

        $requester->requestSync(Uuid::v7());
        self::assertCount(1, $messages, 'The second request sees the admin turn the toggle on.');
    }

    public function testDirectOrchestratorSyncIgnoresTheToggle(): void
    {
        $messages = [];
        $albumId = Uuid::v7();

        // The requester reads the toggle once and stays quiet.
        $this->requester([false], $messages)->requestSync(Uuid::v7());
        // Admin and CLI requests call the orchestrator directly.
        $this->orchestrator($messages)->syncAlbum($albumId->toString());

        self::assertCount(1, $messages);
        self::assertInstanceOf(SyncAlbumMessage::class, $messages[0]);
        self::assertTrue($messages[0]->albumId->equals($albumId));
    }

    /**
     * @param list<bool|null> $storedValues the stored toggle value seen by each successive read
     * @param list<object> $messages
     */
    private function requester(array $storedValues, array &$messages): AlbumMetadataSyncRequester
    {
        $store = $this->createMock(SystemSettingStoreInterface::class);
        $store->expects($this->exactly(count($storedValues)))->method('find')
            ->with(MetadataSettingDefinitions::AUTO_SYNC)
            ->willReturnOnConsecutiveCalls(...$storedValues);
        $settings = new SystemSettings(new SettingDefinitionRegistry([new MetadataSettingDefinitions()]), $store);

        return new AlbumMetadataSyncRequester($settings, $this->orchestrator($messages));
    }

    /** @param list<object> $messages */
    private function orchestrator(array &$messages): MetadataSyncOrchestrator
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$messages): Envelope {
            $messages[] = $message;

            return new Envelope($message, $stamps);
        });

        return new MetadataSyncOrchestrator($this->createStub(LibraryPortInterface::class), $bus, new NullLogger());
    }
}
