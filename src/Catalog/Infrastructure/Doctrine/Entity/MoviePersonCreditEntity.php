<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'movie_person_credits')]
#[ORM\UniqueConstraint(name: 'uniq_movie_person_credits_movie_id_credit_key', columns: ['movie_id', 'credit_key'])]
#[ORM\Index(name: 'idx_movie_person_credits_person_id', columns: ['person_id'])]
class MoviePersonCreditEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MovieEntity::class)]
    #[ORM\JoinColumn(name: 'movie_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MovieEntity $movie;

    #[ORM\ManyToOne(targetEntity: PersonEntity::class)]
    #[ORM\JoinColumn(name: 'person_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonEntity $person;

    #[ORM\Column(type: 'text')]
    private string $category;

    #[ORM\Column(type: 'text')]
    private string $role;

    #[ORM\Column(type: 'text')]
    private string $job;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $characterName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $department = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $creditedName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $billingOrder = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $providerCreditId = null;

    #[ORM\Column(type: 'text')]
    private string $creditKey;

    public function __construct(MovieEntity $movie, PersonEntity $person, string $category, string $role, string $job, string $creditKey)
    {
        $this->id = Uuid::v7();
        $this->movie = $movie;
        $this->person = $person;
        $this->category = $category;
        $this->role = $role;
        $this->job = $job;
        $this->creditKey = $creditKey;
    }

    public static function director(MovieEntity $movie, PersonEntity $person): self
    {
        return new self($movie, $person, 'crew', 'director', 'Director', 'director:' . $person->getId()->toString());
    }

    public function getMovie(): MovieEntity
    {
        return $this->movie;
    }

    public function getPerson(): PersonEntity
    {
        return $this->person;
    }
}
