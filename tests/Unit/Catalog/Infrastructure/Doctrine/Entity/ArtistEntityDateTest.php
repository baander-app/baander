<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure\Doctrine\Entity;

use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Shared\Domain\Model\PublicId;
use PHPUnit\Framework\TestCase;

final class ArtistEntityDateTest extends TestCase
{
    public function testLifespanCopiesMutableDatesAtPersistenceBoundary(): void
    {
        $artist = new ArtistEntity(new PublicId(), 'Artist');
        $begin = new \DateTime('1970-01-01T12:34:56.123456+02:00');
        $end = new \DateTime('2020-03-04T05:06:07.654321+02:00');
        $artist->setLifeSpanBegin($begin);
        $artist->setLifeSpanEnd($end);

        $begin->modify('+1 day');
        $end->modify('+1 day');

        self::assertSame('1970-01-01T12:34:56.123456+02:00', $artist->getLifeSpanBegin()?->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2020-03-04T05:06:07.654321+02:00', $artist->getLifeSpanEnd()?->format('Y-m-d\TH:i:s.uP'));

        $artist->setLifeSpanBegin(null);
        $artist->setLifeSpanEnd(null);

        self::assertNull($artist->getLifeSpanBegin());
        self::assertNull($artist->getLifeSpanEnd());
    }
}
