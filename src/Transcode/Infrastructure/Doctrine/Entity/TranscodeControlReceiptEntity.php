<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'transcode_control_receipts')]
final class TranscodeControlReceiptEntity
{
    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'uuid')]
    private Uuid $sessionId;

    #[ORM\Id]
    #[ORM\Column(name: 'request_id', type: 'text')]
    private string $requestId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
}
