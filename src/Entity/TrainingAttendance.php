<?php

namespace App\Entity;

use App\Repository\TrainingAttendanceRepository;
use Doctrine\ORM\Mapping as ORM;

// Pivot entity between Training and Member — stores attendance status per entry.
#[ORM\Entity(repositoryClass: TrainingAttendanceRepository::class)]
#[ORM\UniqueConstraint(name: 'unique_training_member', columns: ['training_id', 'member_id'])]
class TrainingAttendance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Training::class, inversedBy: 'attendances')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Training $training = null;

    // CASCADE: deleting a member removes their attendance records.
    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Member $member = null;

    // Defaults to 'abwesend' — coach marks attendance after creation.
    #[ORM\Column(length: 20)]
    private string $status = 'abwesend';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTraining(): ?Training
    {
        return $this->training;
    }

    public function setTraining(?Training $training): static
    {
        $this->training = $training;

        return $this;
    }

    public function getMember(): ?Member
    {
        return $this->member;
    }

    public function setMember(?Member $member): static
    {
        $this->member = $member;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }
}
