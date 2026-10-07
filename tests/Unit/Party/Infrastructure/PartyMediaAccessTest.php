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

final class PartyMediaAccessTest extends TestCase
{
    private Uuid $video;
    private Uuid $otherVideo;
    private Uuid $hiddenVideo;
    private Uuid $job;
    private Uuid $otherJob;
    private Uuid $hiddenJob;

    protected function setUp(): void
    {
        $this->video = Uuid::v7();
        $this->otherVideo = Uuid::v7();
        $this->hiddenVideo = Uuid::v7();
        $this->job = Uuid::v7();
        $this->otherJob = Uuid::v7();
        $this->hiddenJob = Uuid::v7();
    }

    public function testAPlayableVideoWithoutAJobIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $this->access()->assertHostCanPlay($this->video, null);
    }

    public function testAJobOfThePartysVideoIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $this->access()->assertHostCanPlay($this->video, $this->job);
    }

    public function testUpperCaseIdentifiersMatchTheStoredJobVideo(): void
    {
        $this->expectNotToPerformAssertions();
        $this->access()->assertHostCanPlay(Uuid::fromString(strtoupper($this->video->toString())), $this->job);
    }

    public function testAMissingOrInaccessibleVideoIsNotFound(): void
    {
        $this->expectExceptionObject(PartyMediaNotFoundException::video());
        $this->access()->assertHostCanPlay($this->hiddenVideo, null);
    }

    public function testAMissingJobIsNotFound(): void
    {
        $this->expectExceptionObject(PartyMediaNotFoundException::transcodeJob());
        $this->access()->assertHostCanPlay($this->video, Uuid::v7());
    }

    public function testAJobOfAVideoTheHostMayNotPlayIsNotFound(): void
    {
        $this->expectExceptionObject(PartyMediaNotFoundException::transcodeJob());
        $this->access()->assertHostCanPlay($this->video, $this->hiddenJob);
    }

    public function testAJobOfAnotherPlayableVideoIsAMismatch(): void
    {
        $this->expectException(TranscodeJobVideoMismatchException::class);
        $this->access()->assertHostCanPlay($this->video, $this->otherJob);
    }

    private function access(): PartyMediaAccess
    {
        $playable = [$this->video->toString(), $this->otherVideo->toString()];
        $jobs = [
            $this->job->toString() => $this->video,
            $this->otherJob->toString() => $this->otherVideo,
            $this->hiddenJob->toString() => $this->hiddenVideo,
        ];
        $playback = $this->createStub(PlaybackAccessInterface::class);
        $playback->method('assertAccess')->willReturnCallback(static function (Uuid $videoId) use ($playable): void {
            if (!in_array(strtolower($videoId->toString()), $playable, true)) {
                throw new AccessDeniedException('Video is not accessible.');
            }
        });
        $playback->method('findTranscodeJobVideoId')->willReturnCallback(static fn (Uuid $jobId): ?Uuid => $jobs[$jobId->toString()] ?? null);

        return new PartyMediaAccess($playback);
    }
}
