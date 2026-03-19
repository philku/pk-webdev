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

    /** @return Training[] */
    public function findByTeam(Team $team): array
    {
        return $this->findBy(
            ['team' => $team],
            ['scheduledAt' => 'DESC']
        );
    }
}
