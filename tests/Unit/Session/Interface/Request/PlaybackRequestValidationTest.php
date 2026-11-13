<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Interface\Request;

use App\Session\Interface\Request\AcknowledgePlaybackRequest;
use App\Session\Interface\Request\ClosePlaybackEndpointRequest;
use App\Session\Interface\Request\ControlPlaybackRequest;
use App\Session\Interface\Request\ObservePlaybackRequest;
use App\Session\Interface\Request\OverridePlaybackRequest;
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
        $requests = [new RegisterPlaybackEndpointRequest($uuid, $uuid), new RegisterPlaybackEndpointRequest($uuid, $uuid, pageId: $uuid),
            new TransferPlaybackRequest($uuid, 0, $uuid),
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

    public function testARegistrationCarriesItsPageIdentityOnlyAsAUuid(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $uuid = new Uuid()->toString();
        $page = new Uuid()->toString();

        self::assertSame($page, new RegisterPlaybackEndpointRequest($uuid, $uuid, pageId: $page)->toArray()['pageId']);
        self::assertNull(new RegisterPlaybackEndpointRequest($uuid, $uuid)->toArray()['pageId']);
        self::assertCount(1, $validator->validate(new RegisterPlaybackEndpointRequest($uuid, $uuid, pageId: 'this page')));
    }

    public function testACloseReportCarriesItsIncarnationAndACheckpointOfTheShapeLocalRecoveryTakes(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $uuid = new Uuid()->toString();
        $checkpoint = ['revision' => 4, 'media' => ['kind' => 'song', 'publicId' => new PublicId()->toString()], 'currentTrackIndex' => 2, 'position' => 61.5];

        foreach ([$checkpoint, ['media' => null, 'position' => 0] + $checkpoint] as $accepted) {
            $request = new ClosePlaybackEndpointRequest($uuid, $accepted);
            self::assertCount(0, $validator->validate($request));
            self::assertSame(['incarnationId' => $uuid, 'checkpoint' => $accepted], $request->toArray());
        }
        self::assertNotCount(0, $validator->validate(new ClosePlaybackEndpointRequest('the first tab', $checkpoint)));
        foreach ([['revision' => -1], ['revision' => '4'], ['currentTrackIndex' => -1], ['position' => -0.5], ['position' => 'late'],
            ['media' => ['kind' => 'radio', 'publicId' => $checkpoint['media']['publicId']]], ['media' => ['kind' => 'song', 'publicId' => 'short']]] as $change) {
            self::assertNotCount(0, $validator->validate(new ClosePlaybackEndpointRequest($uuid, $change + $checkpoint)), json_encode($change, JSON_THROW_ON_ERROR));
        }
        self::assertNotCount(0, $validator->validate(new ClosePlaybackEndpointRequest($uuid, array_diff_key($checkpoint, ['media' => true]))));
        self::assertNotCount(0, $validator->validate(new ClosePlaybackEndpointRequest($uuid, $checkpoint + ['volume' => 1])));
    }

    public function testAnOverrideCarriesItsRequestIdentityTheRevisionItExpectsAndTheAskingIncarnation(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $request = new Uuid()->toString();
        $incarnation = new Uuid()->toString();

        $accepted = new OverridePlaybackRequest($request, 0, $incarnation);
        self::assertCount(0, $validator->validate($accepted));
        self::assertSame(['requestId' => $request, 'expectedRevision' => 0, 'incarnationId' => $incarnation], $accepted->toArray());
        self::assertCount(1, $validator->validate(new OverridePlaybackRequest('again', 0, $incarnation)));
        self::assertCount(1, $validator->validate(new OverridePlaybackRequest($request, -1, $incarnation)));
        self::assertCount(1, $validator->validate(new OverridePlaybackRequest($request, 0, 'this tab')));
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
