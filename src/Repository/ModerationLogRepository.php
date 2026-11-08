<?php

namespace App\Repository;

use App\Admin\Page;
use App\Entity\ModerationLog;
use App\Enum\ModerationAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModerationLog>
 */
class ModerationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModerationLog::class);
    }

    /** @return Page<ModerationLog> */
    public function searchForAdmin(string $q = '', ?ModerationAction $action = null, int $page = 1, int $perPage = Page::PER_PAGE): Page
    {
        $page = max(1, $page);

        $total = (int) $this->applyFilters($this->createQueryBuilder('l')->select('COUNT(l.id)'), $q, $action)
            ->getQuery()
            ->getSingleScalarResult();

        $items = $this->applyFilters($this->createQueryBuilder('l'), $q, $action)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return new Page($items, $total, $page, $perPage);
    }

    /** @return ModerationLog[] */
    public function forTarget(string $targetType, int $targetId, int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.targetType = :type AND l.targetId = :id')
            ->setParameter('type', $targetType)
            ->setParameter('id', $targetId)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function applyFilters(QueryBuilder $qb, string $q, ?ModerationAction $action): QueryBuilder
    {
        $q = trim(str_replace(['%', '_'], ' ', $q));
        if ($q !== '') {
            $qb->andWhere('l.adminEmail LIKE :q OR l.targetLabel LIKE :q OR l.reason LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        if ($action !== null) {
            $qb->andWhere('l.action = :action')->setParameter('action', $action);
        }

        return $qb;
    }
}
