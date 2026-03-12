<?php

namespace App\Repository;

use App\Entity\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Member>
 *
 * Hier kommen später eigene Abfragen rein,
 * z.B. searchByName() für die Live-Suche.
 */
class MemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Member::class);
    }

    /**
     * Durchsucht Mitglieder nach Name, E-Mail, Teamname und Rolle.
     *
     * @return Member[]
     */
    public function search(string $query): array
    {
        // Wenn nichts eingegeben wurde, alle Mitglieder zurückgeben.
        if ('' === trim($query)) {
            return $this->findAll();
        }

        // QueryBuilder = Doctrine's Werkzeug, um SQL-Abfragen in PHP zu bauen.
        // 'm' ist ein Alias für die Member-Entity (wie "m" in "SELECT m FROM member m").
        return $this->createQueryBuilder('m')
            // JOIN: Wir brauchen Zugriff auf den Team-Namen.
            // innerJoin() verknüpft Member.team mit der Team-Entity.
            // 't' = Alias für Team (wie bei SQL: JOIN team t ON ...).
            ->innerJoin('m.team', 't')

            // WHERE mit LIKE: Suche in mehreren Feldern gleichzeitig.
            // :q ist ein Platzhalter (Parameter), der unten mit setParameter() befüllt wird.
            // Das schützt vor SQL-Injection — Doctrine escaped den Wert automatisch.
            ->where('LOWER(m.name) LIKE LOWER(:q)')
            ->orWhere('LOWER(m.email) LIKE LOWER(:q)')
            ->orWhere('LOWER(t.name) LIKE LOWER(:q)')
            ->orWhere('LOWER(m.role) LIKE LOWER(:q)')

            // Der % vor und nach dem Suchbegriff = "enthält" (nicht nur "beginnt mit").
            // z.B. Suche nach "tor" findet "Torwart" und "Viktor".
            ->setParameter('q', '%' . $query . '%')

            // Ergebnisse alphabetisch nach Name sortieren.
            ->orderBy('m.name', 'ASC')

            // getQuery() = QueryBuilder → fertige Doctrine Query
            // getResult() = Query ausführen → Array von Member-Objekten
            ->getQuery()
            ->getResult();
    }
}
