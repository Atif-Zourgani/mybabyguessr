<?php

namespace App\Repository;

use App\Admin\DisplayTitle;
use App\Admin\Page;
use App\Entity\Game;
use App\Entity\User;
use App\Enum\GameStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Game>
 */
class GameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Game::class);
    }

    /** @return Game[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.user = :user')
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByToken(string $token): ?Game
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function getDailyCreatedTrend(\DateTimeImmutable $since): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DATE(created_at) as day, COUNT(*) as cnt FROM game WHERE created_at >= :since GROUP BY DATE(created_at) ORDER BY day ASC',
            ['since' => $since->format('Y-m-d')]
        );
    }

    public function findRecentWithGuessCount(int $limit = 8): array
    {
        return DisplayTitle::apply($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT gm.status, gm.created_at,
                    gm.title, u.last_name AS owner_last_name,
                    gm.token, gm.slug, u.first_name, u.last_name,
                    COUNT(g.id) as guess_count
             FROM game gm
             JOIN user u ON gm.user_id = u.id
             LEFT JOIN guess g ON g.game_id = gm.id
             GROUP BY gm.id
             ORDER BY gm.created_at DESC
             LIMIT ' . max(1, (int) $limit)
        ));
    }

    public function getCategoryStats(): array
    {
        return $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT SUM(guess_gender) as gender, SUM(guess_name) as name, SUM(guess_date) as date_cat, SUM(guess_weight) as weight, SUM(guess_height) as height FROM game'
        ) ?: [];
    }

    public function findLogsWithUserAndGuessCount(int $limit = 30): array
    {
        return DisplayTitle::apply($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT gm.status, gm.created_at,
                    gm.title, u.last_name AS owner_last_name,
                    gm.token, gm.slug, u.first_name, u.last_name, u.email,
                    COUNT(g.id) as guess_count
             FROM game gm
             JOIN user u ON gm.user_id = u.id
             LEFT JOIN guess g ON g.game_id = gm.id
             GROUP BY gm.id
             ORDER BY gm.created_at DESC
             LIMIT ' . max(1, (int) $limit)
        ));
    }

    public function findRevealedWithGuessCount(int $limit = 30): array
    {
        return DisplayTitle::apply($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT gm.updated_at, gm.answer_gender, gm.answer_name,
                    gm.title, u.last_name AS owner_last_name,
                    gm.token, gm.slug, u.first_name, u.last_name,
                    COUNT(g.id) as guess_count
             FROM game gm
             JOIN user u ON gm.user_id = u.id
             LEFT JOIN guess g ON g.game_id = gm.id
             WHERE gm.status = \'revealed\'
             GROUP BY gm.id
             ORDER BY gm.updated_at DESC
             LIMIT ' . max(1, (int) $limit)
        ));
    }

    private const ADMIN_SORTS = [
        'created' => 'g.createdAt',
        'title'   => 'g.title',
        'owner'   => 'u.lastName',
        'status'  => 'g.status',
        'guesses' => 'guessCount',
    ];

    /**
     * Liste paginée pour l'administration, avec recherche, filtres et tri.
     *
     * @param ?bool $hidden null = tous
     * @return Page<array{game: Game, guesses: int}>
     */
    public function searchForAdmin(string $q = '', ?GameStatus $status = null, ?bool $hidden = null, ?User $owner = null, string $sort = 'created', string $dir = 'desc', int $page = 1, int $perPage = Page::PER_PAGE): Page
    {
        $page = max(1, $page);

        $total = (int) $this->applyAdminFilters(
            $this->createQueryBuilder('g')->select('COUNT(g.id)')->join('g.user', 'u'),
            $q, $status, $hidden, $owner
        )->getQuery()->getSingleScalarResult();

        $sortColumn = self::ADMIN_SORTS[$sort] ?? self::ADMIN_SORTS['created'];
        $direction  = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->applyAdminFilters(
            $this->createQueryBuilder('g')->join('g.user', 'u')->addSelect('u'),
            $q, $status, $hidden, $owner
        )
            ->addSelect('(SELECT COUNT(gs.id) FROM App\\Entity\\Guess gs WHERE gs.game = g) AS guessCount')
            ->orderBy($sortColumn, $direction)
            ->addOrderBy('g.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $items = array_map(fn (array $row) => ['game' => $row[0], 'guesses' => (int) $row['guessCount']], $rows);

        return new Page($items, $total, $page, $perPage);
    }

    private function applyAdminFilters(QueryBuilder $qb, string $q, ?GameStatus $status, ?bool $hidden, ?User $owner): QueryBuilder
    {
        // % et _ sont des jokers de LIKE : on les retire plutôt que de les échapper (portable MySQL / SQLite)
        $q = trim(str_replace(['%', '_'], ' ', $q));
        if ($q !== '') {
            $qb->andWhere('g.title LIKE :q OR g.slug LIKE :q OR u.email LIKE :q OR u.lastName LIKE :q OR u.firstName LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        if ($status !== null) {
            $qb->andWhere('g.status = :status')->setParameter('status', $status);
        }

        if ($hidden === true) {
            $qb->andWhere('g.hiddenAt IS NOT NULL');
        } elseif ($hidden === false) {
            $qb->andWhere('g.hiddenAt IS NULL');
        }

        if ($owner !== null) {
            $qb->andWhere('g.user = :owner')->setParameter('owner', $owner);
        }

        return $qb;
    }

    /** @return array<string, int> nombre de jeux par statut ('open', 'closed', 'revealed') */
    public function countByStatus(): array
    {
        $counts = [];
        foreach ($this->createQueryBuilder('g')->select('g.status AS status, COUNT(g.id) AS total')->groupBy('g.status')->getQuery()->getArrayResult() as $row) {
            $status = $row['status'] instanceof GameStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['total'];
        }

        return $counts;
    }

    public function countHidden(): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->where('g.hiddenAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
