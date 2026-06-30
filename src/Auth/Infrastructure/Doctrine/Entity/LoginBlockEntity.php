<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity;

use App\Auth\Domain\Model\LoginBlock;
use App\Auth\Domain\Model\LoginBlockState;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'login_blocks')]
class LoginBlockEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

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
        string $ipAddress,
        string $email,
        string $fieldValue,
        string $userAgent,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
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
            ipAddress: $this->ipAddress,
            email: $this->email,
            fieldValue: $this->fieldValue,
            userAgent: $this->userAgent,
            createdAt: $this->createdAt,
        ));
    }
}
