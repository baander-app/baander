<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Interface\Request;

use App\Session\Interface\Request\RecoverLocalPlaybackRequest;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class LocalRecoveryRequestTest extends TestCase
{
    public function testRecoveryAcceptsAnEmptySelectionCheckpoint(): void
    {
        $request = new RecoverLocalPlaybackRequest(
            new Uuid()->toString(),
            new Uuid()->toString(),
            str_repeat('a', 64),
            ['revision' => 1, 'media' => null, 'currentTrackIndex' => 0, 'position' => 4.5],
        );
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertCount(0, $validator->validate($request));
    }

    public function testRecoveryRejectsInvalidBindingsAndCheckpoint(): void
    {
        $request = new RecoverLocalPlaybackRequest(
            'wrong',
            'wrong',
            'short',
            ['revision' => -1, 'media' => ['kind' => 'unknown', 'publicId' => 'wrong'], 'currentTrackIndex' => -1, 'position' => -1],
        );
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertGreaterThanOrEqual(7, count($validator->validate($request)));
    }
}
