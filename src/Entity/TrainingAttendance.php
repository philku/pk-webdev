<?php

namespace App\Entity;

use App\Repository\TrainingAttendanceRepository;
use Doctrine\ORM\Mapping as ORM;

// Pivot-Entity zwischen Training und Member.
// Warum keine einfache ManyToMany? Weil wir den Status (anwesend/abwesend/entschuldigt)
// pro Eintrag speichern müssen. Eine ManyToMany-Tabelle hat nur die zwei Fremdschlüssel,
// keine Extra-Spalten. Die Lösung: eine eigene Entity mit zwei ManyToOne-Relationen.
//
// UniqueConstraint: Ein Member kann pro Training nur einmal vorkommen.
// Verhindert doppelte Einträge auf DB-Ebene (zusätzlich zur PHP-Logik).
#[ORM\Entity(repositoryClass: TrainingAttendanceRepository::class)]
#[ORM\UniqueConstraint(name: 'unique_training_member', columns: ['training_id', 'member_id'])]
class TrainingAttendance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ManyToOne zum Training — inversedBy verknüpft mit Training::$attendances.
    #[ORM\ManyToOne(targetEntity: Training::class, inversedBy: 'attendances')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Training $training = null;

    // ManyToOne zum Member — onDelete CASCADE:
    // Wenn ein Mitglied gelöscht wird, werden seine Anwesenheitseinträge mitgelöscht.
    // Ohne CASCADE würde ein Constraint-Fehler kommen, weil die Attendance
    // noch auf den gelöschten Member verweist.
    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Member $member = null;

    // Status: 'anwesend', 'abwesend' oder 'entschuldigt'.
    // Default ist 'abwesend' — beim Erstellen eines Trainings sind erstmal alle abwesend,
    // der Trainer markiert dann die Anwesenden.
    #[ORM\Column(length: 20)]
    private string $status = 'abwesend';

    // --- Getter & Setter ---

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
