<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface;

use App\Shared\Application\PublicDiagnosticText;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use App\Shared\Interface\Request\QueryParameters;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class PublicDiagnosticIdentityTest extends TestCase
{
    public function testErrorsDoNotRevealInternalIdentitiesInTextOrNestedDetails(): void
    {
        $id = new Uuid();
        $publicId = new PublicId();
        $error = new ApiError('Missing entity ' . $id, 404, ['items' => [['id' => $id->toString(), 'publicId' => $publicId->toString()]]]);
        $output = $error->toArray();

        self::assertSame('Missing entity [internal ID]', $output['error']['message']);
        self::assertSame('[internal ID]', $output['error']['details']['items'][0]['id']);
        self::assertSame($publicId->toString(), $output['error']['details']['items'][0]['publicId']);
        self::assertSame('failed [internal ID]', PublicDiagnosticText::redact('failed ' . strtoupper($id->toString())));
    }

    public function testPublicQueryIdentityAcceptsPublicIdsAndRejectsInternalIds(): void
    {
        self::assertNull(QueryParameters::optionalPublicId(new InputBag(), 'cursor'));
        $publicId = new PublicId();
        self::assertEquals($publicId, QueryParameters::optionalPublicId(new InputBag(['cursor' => $publicId->toString()]), 'cursor'));

        $this->expectException(InvalidQueryParameter::class);
        QueryParameters::optionalPublicId(new InputBag(['cursor' => (new Uuid())->toString()]), 'cursor');
    }
}
