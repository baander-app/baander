<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Interface\Request;

use App\Session\Interface\Request\ControlPlaybackRequest;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ControlPlaybackQueueBoundsTest extends TestCase
{
    private const string SONG = 'aB3dE5fG7hJ9kL1mN3pQr';

    public function testAQueueOf10000PublicIdsIsAccepted(): void
    {
        self::assertSame([], $this->paths($this->queue(array_fill(0, 10_000, self::SONG))));
    }

    public function testALongerQueueIsRefused(): void
    {
        self::assertSame(['queue'], $this->paths($this->queue(array_fill(0, 10_001, self::SONG))));
    }

    public function testAQueueSentAsAnObjectIsRefused(): void
    {
        self::assertSame(['queue'], $this->paths($this->queue(['first' => self::SONG])));
    }

    /** @param array<array-key, string> $queue */
    private function queue(array $queue): ControlPlaybackRequest
    {
        return new ControlPlaybackRequest(new Uuid()->toString(), 0, 'queue', queue: $queue, currentTrackIndex: 0);
    }

    /** @return list<string> */
    private function paths(ControlPlaybackRequest $request): array
    {
        $paths = [];
        foreach ($this->validator()->validate($request) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return array_values(array_unique($paths));
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
