<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity;

use App\Auth\Domain\Model\LoginBlock;
use App\Auth\Domain\Model\LoginBlockState;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'login_blocks')]
#[ORM\UniqueConstraint(name: 'uniq_login_blocks_public_id', columns: ['public_id'])]
#[ORM\Index(name: 'idx_login_blocks_email', columns: ['email'])]
#[ORM\Index(name: 'idx_login_blocks_ip_address_created_at', columns: ['ip_address', 'created_at'])]
class LoginBlockEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'text')]
    private string $ipAddress;

    #[ORM\Column(type: 'text')]
    private string $email;

    #[ORM\Column(type: 'text')]
    private string $fieldValue;

    #[ORM\Column(type: 'text')]
    private string $userAgent;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $id,
        PublicId $publicId,
        string $ipAddress,
        string $email,
        string $fieldValue,
        string $userAgent,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->publicId = $publicId;
        $this->ipAddress = $ipAddress;
        $this->email = $email;
        $this->fieldValue = $fieldValue;
        $this->userAgent = $userAgent;
        $this->createdAt = $createdAt;
    }

    public function toDomain(): LoginBlock
    {
        return LoginBlock::reconstitute(new LoginBlockState(
            id: $this->id,
            publicId: $this->publicId,
            ipAddress: $this->ipAddress,
            email: $this->email,
            fieldValue: $this->fieldValue,
            userAgent: $this->userAgent,
            createdAt: $this->createdAt,
        ));
    }
}
