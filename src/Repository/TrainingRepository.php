<?php

namespace App\Repository;

use App\Entity\Team;
use App\Entity\Training;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Training>
 */
class TrainingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Training::class);
    }

    // Alle Trainings eines Teams, sortiert nach Datum (neueste zuerst).
    // orderBy DESC: Der Trainer will zuerst das nächste anstehende Training sehen,
    // nicht das älteste aus der Vergangenheit.
    /**
     * @return Training[]
     */
    public function findByTeam(Team $team): array
    {
        return $this->findBy(
            ['team' => $team],
            ['scheduledAt' => 'DESC']
        );
    }
}
