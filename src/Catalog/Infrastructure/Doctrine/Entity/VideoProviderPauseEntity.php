<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'video_provider_pauses')]
class VideoProviderPauseEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private string $provider;

    #[ORM\Column(type: 'text')]
    private string $failureKind;

    #[ORM\Column(type: 'smallint')]
    private int $failureCount;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $retryAt;
}
