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

    // Erlaubte Sortierfelder — Whitelist gegen SQL-Injection.
    // Nur diese Werte dürfen als $sortBy übergeben werden.
    private const ALLOWED_SORT_FIELDS = [
        'name' => 'm.name',
        'email' => 'm.email',
        'team' => 't.name',
        'role' => 'm.role',
    ];

    /**
     * Durchsucht Mitglieder nach Name, E-Mail, Teamname und Rolle.
     * Unterstützt Sortierung und Paginierung.
     *
     * @return Member[]
     */
    public function search(string $query, string $sortBy = 'name', string $sortDirection = 'ASC', int $page = 1, int $perPage = 5): array
    {
        // QueryBuilder = Doctrine's Werkzeug, um SQL-Abfragen in PHP zu bauen.
        // 'm' ist ein Alias für die Member-Entity (wie "m" in "SELECT m FROM member m").
        $qb = $this->createQueryBuilder('m')
            // JOIN: Wir brauchen Zugriff auf den Team-Namen (für Suche + Sortierung).
            // innerJoin() verknüpft Member.team mit der Team-Entity.
            // 't' = Alias für Team (wie bei SQL: JOIN team t ON ...).
            ->innerJoin('m.team', 't');

        // Suchfilter nur anwenden wenn etwas eingegeben wurde.
        if ('' !== trim($query)) {
            $qb
                // WHERE mit LIKE: Suche in mehreren Feldern gleichzeitig.
                // :q ist ein Platzhalter (Parameter), der unten mit setParameter() befüllt wird.
                // Das schützt vor SQL-Injection — Doctrine escaped den Wert automatisch.
                ->where('LOWER(m.name) LIKE LOWER(:q)')
                ->orWhere('LOWER(m.email) LIKE LOWER(:q)')
                ->orWhere('LOWER(t.name) LIKE LOWER(:q)')
                ->orWhere('LOWER(m.role) LIKE LOWER(:q)')
                // Der % vor und nach dem Suchbegriff = "enthält" (nicht nur "beginnt mit").
                // z.B. Suche nach "tor" findet "Torwart" und "Viktor".
                ->setParameter('q', '%' . $query . '%');
        }

        // Sortierung: Nur erlaubte Felder akzeptieren (Whitelist).
        // Falls jemand einen ungültigen Wert schickt, wird nach Name sortiert.
        $sortColumn = self::ALLOWED_SORT_FIELDS[$sortBy] ?? self::ALLOWED_SORT_FIELDS['name'];
        $direction = 'DESC' === strtoupper($sortDirection) ? 'DESC' : 'ASC';
        $qb->orderBy($sortColumn, $direction);

        // Paginierung mit setFirstResult() und setMaxResults().
        // setFirstResult() = Offset (wie OFFSET in SQL) — wie viele Zeilen übersprungen werden.
        // setMaxResults() = Limit (wie LIMIT in SQL) — wie viele Zeilen zurückkommen.
        // Seite 1: offset 0, Seite 2: offset 5, Seite 3: offset 10, ...
        $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    /**
     * Zählt die Treffer für eine Suchanfrage.
     * Wird gebraucht um die Gesamtzahl der Seiten zu berechnen.
     */
    public function countSearch(string $query): int
    {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->innerJoin('m.team', 't');

        if ('' !== trim($query)) {
            $qb
                ->where('LOWER(m.name) LIKE LOWER(:q)')
                ->orWhere('LOWER(m.email) LIKE LOWER(:q)')
                ->orWhere('LOWER(t.name) LIKE LOWER(:q)')
                ->orWhere('LOWER(m.role) LIKE LOWER(:q)')
                ->setParameter('q', '%' . $query . '%');
        }

        // getSingleScalarResult() gibt einen einzelnen Wert zurück (die Zahl).
        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
