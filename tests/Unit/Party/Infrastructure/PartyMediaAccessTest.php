<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Infrastructure;

use App\Party\Application\Exception\PartyMediaNotFoundException;
use App\Party\Application\Exception\TranscodeJobVideoMismatchException;
use App\Party\Infrastructure\PartyMediaAccess;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\PlaybackAccessInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The playback authority here knows three videos, two of them playable, and the jobs that encode
 * them with their owners: the host owns three, another user owns one of the party's video.
 */
final class PartyMediaAccessTest extends TestCase
{
    private Uuid $host;
    private Uuid $otherUser;
    private Uuid $video;
    private Uuid $otherVideo;
    private Uuid $hiddenVideo;
    private Uuid $job;
    private Uuid $otherJob;
    private Uuid $hiddenJob;
    private Uuid $foreignJob;

    protected function setUp(): void
    {
        $this->host = Uuid::v7();
        $this->otherUser = Uuid::v7();
        $this->video = Uuid::v7();
        $this->otherVideo = Uuid::v7();
        $this->hiddenVideo = Uuid::v7();
        $this->job = Uuid::v7();
        $this->otherJob = Uuid::v7();
        $this->hiddenJob = Uuid::v7();
        $this->foreignJob = Uuid::v7();
    }

    public function testAPlayableVideoIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $this->access()->assertCanPlay($this->video);
    }

    public function testAPlayableVideoWithoutAJobIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $this->access()->assertHostCanPlay($this->host, $this->video, null);
    }

    public function testTheHostsOwnJobOfThePartysVideoIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $this->access()->assertHostCanPlay($this->host, $this->video, $this->job);
    }

    public function testUpperCaseIdentifiersMatchTheStoredJobVideo(): void
    {
        $this->expectNotToPerformAssertions();
        $this->access()->assertHostCanPlay($this->host, $this->video->toString() |> strtoupper(...) |> Uuid::fromString(...), $this->job);
    }

    public function testAMissingOrInaccessibleVideoIsNotFound(): void
    {
        $this->expectExceptionObject(PartyMediaNotFoundException::video());
        $this->access()->assertCanPlay($this->hiddenVideo);
    }

    public function testAPartyOfAMissingOrInaccessibleVideoIsNotFound(): void
    {
        $this->expectExceptionObject(PartyMediaNotFoundException::video());
        $this->access()->assertHostCanPlay($this->host, $this->hiddenVideo, null);
    }

    public function testAMissingJobIsNotFound(): void
    {
        $this->expectExceptionObject(PartyMediaNotFoundException::transcodeJob());
        $this->access()->assertHostCanPlay($this->host, $this->video, Uuid::v7());
    }

    public function testAnotherUsersJobOfThePartysVideoIsNotFound(): void
    {
        $this->expectExceptionObject(PartyMediaNotFoundException::transcodeJob());
        $this->access()->assertHostCanPlay($this->host, $this->video, $this->foreignJob);
    }

    public function testAJobOfAVideoTheHostMayNotPlayIsNotFound(): void
    {
        $this->expectExceptionObject(PartyMediaNotFoundException::transcodeJob());
        $this->access()->assertHostCanPlay($this->host, $this->video, $this->hiddenJob);
    }

    public function testAJobOfAnotherPlayableVideoIsAMismatch(): void
    {
        $this->expectException(TranscodeJobVideoMismatchException::class);
        $this->access()->assertHostCanPlay($this->host, $this->video, $this->otherJob);
    }

    public function testOnlyTheJobsOwnerOwnsIt(): void
    {
        $access = $this->access();

        self::assertTrue($access->ownsTranscodeJob($this->host, $this->job));
        self::assertTrue($access->ownsTranscodeJob($this->otherUser, $this->foreignJob));
        self::assertFalse($access->ownsTranscodeJob($this->otherUser, $this->job));
        self::assertFalse($access->ownsTranscodeJob($this->host, $this->foreignJob));
        self::assertFalse($access->ownsTranscodeJob($this->host, Uuid::v7()));
    }

    private function access(): PartyMediaAccess
    {
        $playable = [$this->video->toString(), $this->otherVideo->toString()];
        $jobs = [
            $this->job->toString() => [$this->video, $this->host],
            $this->otherJob->toString() => [$this->otherVideo, $this->host],
            $this->hiddenJob->toString() => [$this->hiddenVideo, $this->host],
            $this->foreignJob->toString() => [$this->video, $this->otherUser],
        ];
        $playback = $this->createStub(PlaybackAccessInterface::class);
        $playback->method('assertAccess')->willReturnCallback(static function (Uuid $videoId) use ($playable): void {
            $videoIdString = $videoId->toString();
            if (!in_array(strtolower($videoIdString), $playable, true)) {
                throw new AccessDeniedException('Video is not accessible.');
            }
        });
        $playback->method('findOwnedTranscodeJobVideoId')->willReturnCallback(
            static function (Uuid $jobId, Uuid $ownerUserId) use ($jobs): ?Uuid {
                [$videoId, $owner] = $jobs[$jobId->toString()] ?? [null, null];

                return $owner?->equals($ownerUserId) === true ? $videoId : null;
            },
        );

        return new PartyMediaAccess($playback);
    }
}
