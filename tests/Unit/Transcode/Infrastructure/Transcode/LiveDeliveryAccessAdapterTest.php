<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Catalog\Domain\Model\Video;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeJobState;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Transcode\LiveDeliveryAccessAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LiveDeliveryAccessAdapterTest extends TestCase
{
    private Video $video;
    private Uuid $owner;
    /** @var array<string, TranscodeJob> jobs by public ID */
    private array $jobs = [];
    /** @var list<string> the owner and video public ID pairs whose library scope shows the video */
    private array $visible = [];

    protected function setUp(): void
    {
        $this->video = Video::create('/media/film.mkv', 'film-hash');
        $this->owner = Uuid::generate();
        $this->jobs = [];
        $this->visible = [$this->grant($this->owner, $this->video)];
    }

    public function testJobsWhoseOwnerMayPlayTheirVideoStream(): void
    {
        $first = $this->job($this->owner);
        $second = $this->job($this->owner);

        $this->adapter()->assertOwnerMayStream($first->getPublicId());
        $this->adapter()->assertOwnersMayStream([$first->getPublicId(), $second->getPublicId()]);

        $this->addToAssertionCount(1);
    }

    public function testAJobWhoseOwnerMayNoLongerPlayItsVideoIsRefused(): void
    {
        $job = $this->job($this->owner);
        $this->visible = [];

        $this->expectException(ConflictException::class);
        $this->adapter()->assertOwnerMayStream($job->getPublicId());
    }

    public function testASetIsRefusedWhenTheOwnerOfOneOfItsJobsMayNotPlayTheVideo(): void
    {
        $set = [$this->job($this->owner)->getPublicId(), $this->job(Uuid::generate())->getPublicId()];

        $this->expectException(ConflictException::class);
        $this->adapter()->assertOwnersMayStream($set);
    }

    /** @return iterable<string, array{bool}> */
    public static function jobsWithoutAnOwner(): iterable
    {
        yield 'a job that no longer exists' => [false];
        yield 'a job without an owner' => [true];
    }

    #[DataProvider('jobsWithoutAnOwner')]
    public function testAJobWithoutAnOwnerWhoseAccessCanBeCheckedIsRefused(bool $exists): void
    {
        $job = $this->job($this->owner);
        $jobId = $job->getPublicId();
        unset($this->jobs[$jobId->toString()]);
        if ($exists) {
            $ownerless = TranscodeJob::reconstitute(new TranscodeJobState(...[
                ...get_object_vars($job->getState()),
                'ownerUserId' => null,
            ]));
            $this->jobs[$jobId->toString()] = $ownerless;
        }

        $this->expectException(ConflictException::class);
        $this->adapter()->assertOwnerMayStream($jobId);
    }

    private function job(Uuid $owner): TranscodeJob
    {
        $job = TranscodeJob::create($this->video->getId(), QualityTier::p720(), '/transcode', $owner);
        $this->jobs[$job->getPublicId()->toString()] = $job;

        return $job;
    }

    private function grant(Uuid $owner, Video $video): string
    {
        return $owner->toString() . ' ' . $video->getPublicId()->toString();
    }

    private function adapter(): LiveDeliveryAccessAdapter
    {
        $jobs = $this->createStub(TranscodeJobRepositoryInterface::class);
        $jobs->method('findByPublicId')->willReturnCallback(fn (PublicId $id): ?TranscodeJob => $this->jobs[$id->toString()] ?? null);
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?Video => $id->equals($this->video->getId()) ? $this->video : null);
        $access = new readonly class ($this->visible) implements PlaybackMediaAccessInterface {
            /** @param list<string> $visible */
            public function __construct(private array $visible)
            {
            }

            public function assertTrackAccess(Uuid $owner, PublicId $track): void
            {
                throw new \LogicException('Live delivery checks video access only.');
            }

            public function assertVideoAccess(Uuid $owner, PublicId $video): void
            {
                if (!in_array($owner->toString() . ' ' . $video->toString(), $this->visible, true)) {
                    throw new ConflictException('Playback media is unavailable.');
                }
            }
        };

        return new LiveDeliveryAccessAdapter($jobs, $videos, $access);
    }
}
