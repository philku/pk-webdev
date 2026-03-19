<?php

namespace App\Repository;

use App\Entity\Team;
use App\Entity\Training;
use App\Entity\TrainingAttendance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingAttendance>
 */
class TrainingAttendanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingAttendance::class);
    }

    // Eager-joins member to avoid N+1 queries.
    /** @return TrainingAttendance[] */
    public function findByTraining(Training $training): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.member', 'm')
            ->where('a.training = :training')
            ->setParameter('training', $training)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Per-member attendance rate — percentage calculated in Twig.
    public function getAttendanceQuote(Team $team): array
    {
        return $this->createQueryBuilder('a')
            ->select(
                'm.id as memberId',
                'm.name as memberName',
                'COUNT(a.id) as totalTrainings',
                "SUM(CASE WHEN a.status = 'anwesend' THEN 1 ELSE 0 END) as presentCount"
            )
            ->join('a.member', 'm')
            ->join('a.training', 't')
            ->where('t.team = :team')
            ->setParameter('team', $team)
            ->groupBy('m.id')
            ->orderBy('presentCount', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
