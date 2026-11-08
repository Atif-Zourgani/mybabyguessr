<?php

namespace App\Repository;

use App\Admin\Page;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function countVerified(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.isVerified = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAdmins(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_ADMIN%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return User[] */
    public function findAllAdmins(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_ADMIN%')
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private const ADMIN_SORTS = [
        'id'      => 'u.id',
        'name'    => 'u.lastName',
        'email'   => 'u.email',
        'created' => 'u.createdAt',
        'login'   => 'u.lastLoginAt',
        'games'   => 'gamesCount',
    ];

    /**
     * Liste paginée pour l'administration, avec recherche, filtres et tri.
     *
     * @param ?bool $verified null = tous
     * @param ?bool $admin    null = tous
     * @return Page<array{user: User, games: int}>
     */
    public function searchForAdmin(string $q = '', ?bool $verified = null, ?bool $admin = null, string $sort = 'id', string $dir = 'desc', int $page = 1, int $perPage = Page::PER_PAGE): Page
    {
        $page = max(1, $page);

        $total = (int) $this->applyAdminFilters($this->createQueryBuilder('u')->select('COUNT(u.id)'), $q, $verified, $admin)
            ->getQuery()
            ->getSingleScalarResult();

        $sortColumn = self::ADMIN_SORTS[$sort] ?? self::ADMIN_SORTS['id'];
        $direction  = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->applyAdminFilters($this->createQueryBuilder('u'), $q, $verified, $admin)
            ->leftJoin('u.games', 'g')
            ->addSelect('COUNT(g.id) AS gamesCount')
            ->groupBy('u.id')
            ->orderBy($sortColumn, $direction)
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $items = array_map(fn (array $row) => ['user' => $row[0], 'games' => (int) $row['gamesCount']], $rows);

        return new Page($items, $total, $page, $perPage);
    }

    private function applyAdminFilters(QueryBuilder $qb, string $q, ?bool $verified, ?bool $admin): QueryBuilder
    {
        // % et _ sont des jokers de LIKE : on les retire plutôt que de les échapper (portable MySQL / SQLite)
        $q = trim(str_replace(['%', '_'], ' ', $q));
        if ($q !== '') {
            $qb->andWhere('u.email LIKE :q OR u.firstName LIKE :q OR u.lastName LIKE :q OR CONCAT(u.firstName, \' \', u.lastName) LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        if ($verified !== null) {
            $qb->andWhere('u.isVerified = :verified')->setParameter('verified', $verified);
        }

        if ($admin === true) {
            $qb->andWhere('u.roles LIKE :adminRole')->setParameter('adminRole', '%ROLE_ADMIN%');
        } elseif ($admin === false) {
            $qb->andWhere('u.roles NOT LIKE :adminRole')->setParameter('adminRole', '%ROLE_ADMIN%');
        }

        return $qb;
    }

    /** Nombre de comptes créés depuis une date (inscriptions récentes). */
    public function countCreatedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
