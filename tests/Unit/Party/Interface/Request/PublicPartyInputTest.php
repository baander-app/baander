<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Interface\Request;

use App\Party\Interface\Request\CreatePartySessionRequest;
use App\Party\Interface\Request\TransferHostRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class PublicPartyInputTest extends TestCase
{
    public function testPublicMediaAndUserIdentitiesAreAcceptedWhileInternalUuidsAreRejected(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $video = (new PublicId())->toString();
        $job = (new PublicId())->toString();
        $user = (new PublicId())->toString();
        self::assertCount(0, $validator->validate(new CreatePartySessionRequest($video, $job)));
        self::assertCount(0, $validator->validate(new CreatePartySessionRequest($video)));
        self::assertCount(0, $validator->validate(new TransferHostRequest($user)));

        foreach ([
            new CreatePartySessionRequest((new Uuid())->toString(), $job),
            new CreatePartySessionRequest($video, (new Uuid())->toString()),
            new TransferHostRequest((new Uuid())->toString()),
        ] as $request) {
            self::assertGreaterThan(0, count($validator->validate($request)));
        }
    }
}
