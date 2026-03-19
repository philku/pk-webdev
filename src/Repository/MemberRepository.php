<?php

namespace App\Repository;

use App\Entity\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Member> */
class MemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Member::class);
    }

    // Whitelist — prevents arbitrary column names in ORDER BY.
    private const ALLOWED_SORT_FIELDS = [
        'name' => 'm.name',
        'email' => 'm.email',
        'team' => 't.name',
        'role' => 'm.role',
    ];

    /** @return Member[] */
    public function search(string $query, string $sortBy = 'name', string $sortDirection = 'ASC', int $page = 1, int $perPage = 5): array
    {
        $qb = $this->createQueryBuilder('m')
            ->innerJoin('m.team', 't');

        if ('' !== trim($query)) {
            $qb
                ->where('LOWER(m.name) LIKE LOWER(:q)')
                ->orWhere('LOWER(m.email) LIKE LOWER(:q)')
                ->orWhere('LOWER(t.name) LIKE LOWER(:q)')
                ->orWhere('LOWER(m.role) LIKE LOWER(:q)')
                ->setParameter('q', '%' . $query . '%');
        }

        $sortColumn = self::ALLOWED_SORT_FIELDS[$sortBy] ?? self::ALLOWED_SORT_FIELDS['name'];
        $direction = 'DESC' === strtoupper($sortDirection) ? 'DESC' : 'ASC';
        $qb->orderBy($sortColumn, $direction);

        $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    // Total count for pagination.
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

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
