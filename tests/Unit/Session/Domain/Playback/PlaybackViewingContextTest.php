<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain\Playback;

use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlaybackViewingContextTest extends TestCase
{
    public function testVideoSelectionRoundTripsItsOpenedTitleAndKeepsLegacyPayloadsUnchanged(): void
    {
        foreach (['episode', 'movie', 'extra'] as $kind) {
            $media = $this->video($kind);
            self::assertSame($media, PlaybackMedia::fromArray($media)->toArray());
        }
        foreach (['song', 'video'] as $kind) {
            $media = ['kind' => $kind, 'publicId' => new PublicId()->toString()];
            self::assertSame($media, PlaybackMedia::fromArray($media)->toArray());
        }
    }

    public function testAudioCannotCarryAVideoTitleContext(): void
    {
        $media = ['kind' => 'song'] + $this->video();
        $this->expectException(InvalidArgumentException::class);
        PlaybackMedia::fromArray($media);
    }

    public function testContextlessLoadsClearTheOpenedTitleForTheSameVideoOrAnotherMedia(): void
    {
        $media = $this->video();
        foreach ([['kind' => 'video', 'publicId' => $media['publicId']],
            ['kind' => 'video', 'publicId' => new PublicId()->toString()],
            ['kind' => 'song', 'publicId' => new PublicId()->toString()]] as $next) {
            $session = ListeningSession::create(Uuid::v7(), [], 0, 0);
            $session->control('load', PlaybackMedia::fromArray($media), [], 0, 15, PlaybackState::Paused);
            $session->control('load', PlaybackMedia::fromArray($next), [], 0, 0, PlaybackState::Paused);
            self::assertSame($next, $session->media()?->toArray());
        }
    }

    public function testLegacyMatchingCheckpointRetainsAuthoritativeContextWhileStaleCheckpointCannotChangeIt(): void
    {
        $media = $this->video();
        $session = ListeningSession::create(Uuid::v7(), [], 0, 0);
        $session->control('load', PlaybackMedia::fromArray($media), [], 0, 15, PlaybackState::Paused);
        $legacy = new PlaybackMedia('video', PublicId::fromString($media['publicId']));
        $session->recoverLocalRunner($legacy, 0, $session->revision(), 20, true, true);
        self::assertSame(20.0, $session->getPosition());
        self::assertSame($media, $session->media()?->toArray());

        $forgedContext = $this->video('movie');
        $forgedContext['publicId'] = $media['publicId'];
        $session->recoverLocalRunner(PlaybackMedia::fromArray($forgedContext), 0, $session->revision() - 1, 100, true, true);
        self::assertSame(20.0, $session->getPosition());
        self::assertSame($media, $session->media()?->toArray());
    }

    /** @return array{kind:string,publicId:string,viewing:array{titleKind:string,titleId:string}} */
    private function video(string $kind = 'episode'): array
    {
        return [
            'kind' => 'video',
            'publicId' => new PublicId()->toString(),
            'viewing' => ['titleKind' => $kind, 'titleId' => new PublicId()->toString()],
        ];
    }
}
