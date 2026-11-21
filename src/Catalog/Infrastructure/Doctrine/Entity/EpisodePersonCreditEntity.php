<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'episode_person_credits')]
#[ORM\UniqueConstraint(name: 'uniq_episode_person_credits_episode_id_credit_key', columns: ['episode_id', 'credit_key'])]
#[ORM\Index(name: 'idx_episode_person_credits_person_id', columns: ['person_id'])]
class EpisodePersonCreditEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\ManyToOne(targetEntity: TvEpisodeEntity::class)]
    #[ORM\JoinColumn(name: 'episode_id', nullable: false, onDelete: 'CASCADE')]
    public TvEpisodeEntity $target;

    #[ORM\ManyToOne(targetEntity: PersonEntity::class)]
    #[ORM\JoinColumn(name: 'person_id', nullable: false, onDelete: 'CASCADE')]
    public PersonEntity $person;

    #[ORM\Column(name: 'category', type: 'text')]
    public string $category;

    #[ORM\Column(name: 'role', type: 'text')]
    public string $role;

    #[ORM\Column(name: 'job', type: 'text')]
    public string $job;

    #[ORM\Column(name: 'character_name', type: 'text', nullable: true)]
    public ?string $characterName = null;

    #[ORM\Column(name: 'department', type: 'text', nullable: true)]
    public ?string $department = null;

    #[ORM\Column(name: 'credited_name', type: 'text', nullable: true)]
    public ?string $creditedName = null;

    #[ORM\Column(name: 'billing_order', type: 'integer', nullable: true)]
    public ?int $billingOrder = null;

    #[ORM\Column(name: 'provider_credit_id', type: 'text', nullable: true)]
    public ?string $providerCreditId = null;

    #[ORM\Column(name: 'credit_key', type: 'text')]
    public string $creditKey;
}
