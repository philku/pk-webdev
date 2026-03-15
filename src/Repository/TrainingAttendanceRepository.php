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

    // Alle Anwesenheitseinträge eines Trainings, nach Member-Name sortiert.
    /**
     * @return TrainingAttendance[]
     */
    public function findByTraining(Training $training): array
    {
        return $this->createQueryBuilder('a')
            // JOIN zum Member, damit wir nach Name sortieren können.
            // Ohne JOIN müsste Doctrine für jeden Eintrag einen Extra-Query machen (N+1 Problem).
            ->join('a.member', 'm')
            ->where('a.training = :training')
            ->setParameter('training', $training)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Trainingsquote pro Mitglied: Wie oft war jeder anwesend?
    // Gibt ein Array zurück mit: memberId, memberName, totalTrainings, presentCount.
    // Die Prozentberechnung machen wir in Twig (DQL kann kein sauberes Rounding).
    //
    // SQL dahinter:
    //   SELECT m.id, m.name, COUNT(a.id) as total,
    //          SUM(CASE WHEN a.status = 'anwesend' THEN 1 ELSE 0 END) as present
    //   FROM training_attendance a
    //   JOIN member m ON a.member_id = m.id
    //   JOIN training t ON a.training_id = t.id
    //   WHERE t.team_id = :teamId
    //   GROUP BY m.id
    //   ORDER BY present DESC
    public function getAttendanceQuote(Team $team): array
    {
        return $this->createQueryBuilder('a')
            ->select(
                'm.id as memberId',
                'm.name as memberName',
                // COUNT = wie viele Trainings hat das Mitglied insgesamt (egal welcher Status)
                'COUNT(a.id) as totalTrainings',
                // SUM CASE WHEN = zählt nur Einträge wo status='anwesend'.
                // CASE WHEN ist wie ein IF in SQL: wenn Bedingung wahr → 1, sonst 0.
                // SUM davon = Anzahl der "anwesend"-Einträge.
                "SUM(CASE WHEN a.status = 'anwesend' THEN 1 ELSE 0 END) as presentCount"
            )
            ->join('a.member', 'm')
            ->join('a.training', 't')
            ->where('t.team = :team')
            ->setParameter('team', $team)
            // GROUP BY m.id: Ergebnisse pro Mitglied zusammenfassen.
            // Ohne GROUP BY hätten wir eine Zeile pro Attendance-Eintrag.
            ->groupBy('m.id')
            // Wer am häufigsten da war, steht oben.
            ->orderBy('presentCount', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
