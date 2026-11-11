<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

// Rows change only through the playback store's guarded statements, never through a managed-entity flush.
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'playback_destinations')]
#[ORM\UniqueConstraint(name: 'uniq_playback_destinations_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_playback_destinations_id_user_id', columns: ['id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_playback_destinations_user_id_device_id_endpoint_id', columns: ['user_id', 'device_id', 'endpoint_id'])]
final class PlaybackDestinationEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'text')]
    private string $publicId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $deviceId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $endpointId;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text', options: ['default' => 'browser'])]
    private string $kind = 'browser';

    /** @var list<string> */
    // The default is written with the spacing PostgreSQL reports for the stored jsonb value.
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '["song", "video"]'])]
    private array $capabilities;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param list<string> $capabilities */
    public function __construct(
        Uuid $id,
        PublicId $publicId,
        Uuid $userId,
        Uuid $deviceId,
        ?Uuid $endpointId,
        string $name,
        array $capabilities,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->publicId = $publicId->toString();
        $this->userId = $userId;
        $this->deviceId = $deviceId;
        $this->endpointId = $endpointId;
        $this->name = $name;
        $this->capabilities = $capabilities;
        $this->createdAt = $createdAt;
    }
}
