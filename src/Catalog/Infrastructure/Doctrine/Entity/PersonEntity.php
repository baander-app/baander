<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Catalog\Domain\Model\Person;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'people')]
#[ORM\UniqueConstraint(name: 'uniq_people_public_id', columns: ['public_id'])]
#[ORM\Index(name: 'idx_people_image_id', columns: ['image_id'])]
class PersonEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $profileUrl = null;

    #[ORM\ManyToOne(targetEntity: ImageEntity::class)]
    #[ORM\JoinColumn(name: 'image_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ImageEntity $image = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $biography = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $aliases = [];

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $birthDate = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $birthPrecision = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $deathDate = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $deathPrecision = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isDeceased = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $birthplace = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sex = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $gender = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $profileOverrides = [];

    public function __construct(string $name, ?string $profileUrl = null)
    {
        $this->id = Uuid::v7();
        $this->publicId = new PublicId();
        $this->name = $name;
        $this->profileUrl = $profileUrl;
    }

    public static function fromPerson(Person $person, ?string $profileUrl): self
    {
        $entity = new self($person->getName(), $profileUrl);
        $entity->id = $person->getId();
        $entity->publicId = $person->getPublicId();

        return $entity;
    }

    /** @param array<string, mixed> $values
     *  @param array<string, mixed> $overrides
     */
    public function applyProfile(array $values, array $overrides, ?ImageEntity $image): void
    {
        $textFields = [
            'name' => 'name',
            'profile_url' => 'profileUrl',
            'biography' => 'biography',
            'birthplace' => 'birthplace',
            'sex' => 'sex',
            'gender' => 'gender',
        ];
        foreach ($textFields as $column => $property) {
            if (array_key_exists($column, $values)) {
                $this->{$property} = $values[$column];
            }
        }
        foreach (['birth' => 'birthDate', 'death' => 'deathDate'] as $prefix => $property) {
            if (array_key_exists($prefix . '_date', $values)) {
                $value = $values[$prefix . '_date'];
                $this->{$property} = $value === null ? null : new DateTimeImmutable($value);
                $precision = $prefix . 'Precision';
                $this->{$precision} = $values[$prefix . '_precision'];
            }
        }
        if (array_key_exists('aliases', $values)) {
            $this->aliases = json_decode($values['aliases'], true, flags: JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('is_deceased', $values)) {
            $this->isDeceased = $values['is_deceased'];
        }
        if (array_key_exists('image_id', $values)) {
            $this->image = $image;
        }
        $this->profileOverrides = $overrides;
    }

    public function updateImportedIdentity(string $name, ?string $profileUrl): void
    {
        if (!array_key_exists('name', $this->profileOverrides)) {
            $this->name = $name;
        }
        if (!array_key_exists('profile_url', $this->profileOverrides) && $this->profileUrl === null) {
            $this->profileUrl = $profileUrl;
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProfileUrl(): ?string
    {
        return $this->profileUrl;
    }
}
