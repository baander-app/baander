<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Application;

use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\Service\LyricsFetchRequester;
use App\Lyrics\Application\Settings\LyricsSettingDefinitions;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Application\Service\SystemSettings;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class LyricsFetchRequestTest extends TestCase
{
    public function testToggleOnQueuesOneAsynchronousFetchPerSong(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $envelopes = [];

        $this->requester([true], $envelopes)->requestFetch($first, $second);

        self::assertCount(2, $envelopes);
        foreach ([$first, $second] as $index => $songId) {
            $message = $envelopes[$index]->getMessage();
            self::assertInstanceOf(FetchLyricsCommand::class, $message);
            self::assertTrue($message->getSongId()->equals($songId));
            self::assertSame(['async'], $envelopes[$index]->last(TransportNamesStamp::class)?->getTransportNames());
        }
    }

    public function testToggleOffQueuesNothing(): void
    {
        $envelopes = [];

        $this->requester([false], $envelopes)->requestFetch(Uuid::v7(), Uuid::v7());

        self::assertSame([], $envelopes);
    }

    public function testUnsetToggleFollowsTheOffDefault(): void
    {
        $envelopes = [];

        $this->requester([null], $envelopes)->requestFetch(Uuid::v7());

        self::assertSame([], $envelopes);
    }

    public function testEveryRequestReadsTheToggleAfresh(): void
    {
        $envelopes = [];
        $requester = $this->requester([false, true], $envelopes);

        $requester->requestFetch(Uuid::v7());
        self::assertSame([], $envelopes, 'The first request sees the toggle off.');

        $requester->requestFetch(Uuid::v7());
        self::assertCount(1, $envelopes, 'The second request sees the admin turn the toggle on.');
    }

    /**
     * @param list<bool|null> $storedValues the stored toggle value seen by each successive read
     * @param list<Envelope> $envelopes
     */
    private function requester(array $storedValues, array &$envelopes): LyricsFetchRequester
    {
        $store = $this->createMock(SystemSettingStoreInterface::class);
        $store->expects($this->exactly(count($storedValues)))->method('find')
            ->with(LyricsSettingDefinitions::AUTO_FETCH)
            ->willReturnOnConsecutiveCalls(...$storedValues);
        $settings = new SystemSettings(new SettingDefinitionRegistry([new LyricsSettingDefinitions()]), $store);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps) use (&$envelopes): Envelope {
            $envelope = new Envelope($message, $stamps);
            $envelopes[] = $envelope;

            return $envelope;
        });

        return new LyricsFetchRequester($settings, $bus);
    }
}
