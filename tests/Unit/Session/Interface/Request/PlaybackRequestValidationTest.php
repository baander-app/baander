<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Interface\Request;

use App\Session\Interface\Request\AcknowledgePlaybackRequest;
use App\Session\Interface\Request\ControlPlaybackRequest;
use App\Session\Interface\Request\ObservePlaybackRequest;
use App\Session\Interface\Request\ReconcilePlaybackRequest;
use App\Session\Interface\Request\RegisterPlaybackEndpointRequest;
use App\Session\Interface\Request\TransferPlaybackRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class PlaybackRequestValidationTest extends TestCase
{
    public function testAllPlaybackRequestMetadataLoadsAndAcceptsValidTypedPayloads(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $uuid = new Uuid()->toString();
        $requests = [new RegisterPlaybackEndpointRequest($uuid, $uuid), new TransferPlaybackRequest($uuid, 0, $uuid),
            new AcknowledgePlaybackRequest($uuid, $uuid, 1, $uuid, 1, 'prepared'), new ObservePlaybackRequest($uuid, 1, 'paused', 12.5),
            new ReconcilePlaybackRequest(1), new ControlPlaybackRequest($uuid, 0, 'volume', volume: 0.5), new ControlPlaybackRequest($uuid, 0, 'load', 12.5, ['kind' => 'video', 'publicId' => new PublicId()->toString()])];
        foreach ($requests as $request) {
            self::assertCount(0, $validator->validate($request), $request::class);
        }
    }

    public function testAcknowledgementAcceptsOnlyTerminalCompatibilityReasons(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $id = new Uuid()->toString();
        $accepted = new AcknowledgePlaybackRequest($id, $id, 1, $id, 1, 'rejected', rejectionReason: 'video_codec_unsupported');
        self::assertCount(0, $validator->validate($accepted));
        self::assertSame('video_codec_unsupported', $accepted->toArray()['rejectionReason']);
        self::assertNotCount(0, $validator->validate(new AcknowledgePlaybackRequest($id, $id, 1, $id, 1, 'rejected', rejectionReason: 'raw error message')));
        self::assertNotCount(0, $validator->validate(new AcknowledgePlaybackRequest($id, $id, 1, $id, 1, 'prepared', rejectionReason: 'unknown')));
    }

    public function testMalformedMediaAndEndpointAuthorityAreRejectedBeforeTheUseCase(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $invalidEndpoint = new RegisterPlaybackEndpointRequest('wrong', 'wrong', '', ['unknown']);
        self::assertGreaterThanOrEqual(4, count($validator->validate($invalidEndpoint)));
        $invalidMedia = new ControlPlaybackRequest(new Uuid()->toString(), -1, 'load', -2.0, ['kind' => 'unknown', 'publicId' => 'wrong']);
        self::assertGreaterThanOrEqual(4, count($validator->validate($invalidMedia)));
    }
}
