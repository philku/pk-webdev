<?php

namespace App\Entity;

use App\Repository\TrainingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// Uses a pivot entity (TrainingAttendance) instead of ManyToMany
// to store per-member attendance status.
#[ORM\Entity(repositoryClass: TrainingRepository::class)]
class Training
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime')]
    #[Assert\NotNull(message: 'Bitte Datum und Uhrzeit angeben.')]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Team::class, inversedBy: 'trainings')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Bitte ein Team auswählen.')]
    private ?Team $team = null;

    /** @var Collection<int, TrainingAttendance> */
    #[ORM\OneToMany(targetEntity: TrainingAttendance::class, mappedBy: 'training', cascade: ['persist'], orphanRemoval: true)]
    private Collection $attendances;

    public function __construct()
    {
        $this->attendances = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScheduledAt(): ?\DateTimeInterface
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeInterface $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    /** @return Collection<int, TrainingAttendance> */
    public function getAttendances(): Collection
    {
        return $this->attendances;
    }

    public function addAttendance(TrainingAttendance $attendance): static
    {
        if (!$this->attendances->contains($attendance)) {
            $this->attendances->add($attendance);
            $attendance->setTraining($this);
        }

        return $this;
    }

    public function removeAttendance(TrainingAttendance $attendance): static
    {
        if ($this->attendances->removeElement($attendance)) {
            if ($attendance->getTraining() === $this) {
                $attendance->setTraining(null);
            }
        }

        return $this;
    }
}
