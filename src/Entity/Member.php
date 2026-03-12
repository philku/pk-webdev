<?php

namespace App\Entity;

use App\Repository\MemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MemberRepository::class)]
class Member
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Assert-Attribute = Validierungsregeln.
    // Symfony prüft diese automatisch, wenn ein Formular abgeschickt wird.
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Bitte einen Namen eingeben.')]
    private ?string $name = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Bitte eine E-Mail eingeben.')]
    #[Assert\Email(message: 'Bitte eine gültige E-Mail eingeben.')]
    private ?string $email = null;

    // nullable: true = dieses Feld darf in der DB leer sein.
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    // Position im Team, z.B. "Stürmer", "Torwart"
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $position = null;

    // Rolle im Verein, z.B. "Spieler", "Trainer", "Betreuer"
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Bitte eine Rolle auswählen.')]
    private ?string $role = null;

    // Typ "date" = nur Datum ohne Uhrzeit.
    // nullable: true, weil man das Beitrittsdatum vielleicht nicht immer kennt.
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $joinedAt = null;

    // ManyToOne = "Viele Members gehören zu einem Team"
    // Das ist die Gegenseite von Team::$members (OneToMany).
    // inversedBy: 'members' verweist auf die Property $members in der Team-Entity.
    #[ORM\ManyToOne(targetEntity: Team::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Bitte ein Team auswählen.')]
    private ?Team $team = null;

    // --- Getter & Setter ---

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

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getJoinedAt(): ?\DateTimeInterface
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(?\DateTimeInterface $joinedAt): static
    {
        $this->joinedAt = $joinedAt;

        return $this;
    }

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): static
    {
        $this->team = $team;

        return $this;
    }
}
