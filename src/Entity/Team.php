<?php

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

// #[ORM\Entity] sagt Doctrine: "Diese Klasse ist eine Datenbanktabelle."
// repositoryClass verknüpft die Entity mit ihrem Repository.
#[ORM\Entity(repositoryClass: TeamRepository::class)]
class Team
{
    // Primärschlüssel — auto-increment ID, generiert die DB automatisch.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Spalte "name", max. 100 Zeichen, darf nicht leer sein.
    #[ORM\Column(length: 100)]
    private ?string $name = null;

    // Spalte "sport", max. 50 Zeichen.
    #[ORM\Column(length: 50)]
    private ?string $sport = null;

    // OneToMany = "Ein Team hat viele Members"
    // mappedBy: 'team' verweist auf die Property $team in der Member-Entity.
    // cascade: ['persist'] = wenn ich ein Team speichere, werden neue Members mitgespeichert.
    // orphanRemoval: true = wenn ein Member aus der Collection entfernt wird, wird er auch aus der DB gelöscht.
    /** @var Collection<int, Member> */
    #[ORM\OneToMany(targetEntity: Member::class, mappedBy: 'team', cascade: ['persist'], orphanRemoval: true)]
    private Collection $members;

    public function __construct()
    {
        // ArrayCollection ist Doctrines "intelligente Liste" — wie ein Array, aber mit Extras.
        $this->members = new ArrayCollection();
    }

    // --- Getter & Setter ---
    // Doctrine braucht diese, um auf die privaten Properties zuzugreifen.

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this; // "return $this" ermöglicht Method Chaining: $team->setName('A')->setSport('Fußball')
    }

    public function getSport(): ?string
    {
        return $this->sport;
    }

    public function setSport(string $sport): static
    {
        $this->sport = $sport;

        return $this;
    }

    /** @return Collection<int, Member> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(Member $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setTeam($this); // Setzt auch die Gegenseite der Relation
        }

        return $this;
    }

    public function removeMember(Member $member): static
    {
        if ($this->members->removeElement($member)) {
            if ($member->getTeam() === $this) {
                $member->setTeam(null);
            }
        }

        return $this;
    }
}
