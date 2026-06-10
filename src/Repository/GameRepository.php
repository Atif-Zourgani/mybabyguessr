<?php

namespace App\Repository;

use App\Entity\Game;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT gm.status, gm.created_at,
                    COALESCE(gm.title, CONCAT(\'Baby \', u.last_name)) as display_title,
                    gm.token, gm.slug, u.first_name, u.last_name,
                    COUNT(g.id) as guess_count
             FROM game gm
             JOIN user u ON gm.user_id = u.id
             LEFT JOIN guess g ON g.game_id = gm.id
             GROUP BY gm.id
             ORDER BY gm.created_at DESC
             LIMIT ' . max(1, (int) $limit)
        );
    }

    public function getCategoryStats(): array
    {
        return $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT SUM(guess_gender) as gender, SUM(guess_name) as name, SUM(guess_date) as date_cat, SUM(guess_weight) as weight, SUM(guess_height) as height FROM game'
        ) ?: [];
    }

    public function findLogsWithUserAndGuessCount(int $limit = 30): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT gm.status, gm.created_at,
                    COALESCE(gm.title, CONCAT(\'Baby \', u.last_name)) as display_title,
                    gm.token, gm.slug, u.first_name, u.last_name, u.email,
                    COUNT(g.id) as guess_count
             FROM game gm
             JOIN user u ON gm.user_id = u.id
             LEFT JOIN guess g ON g.game_id = gm.id
             GROUP BY gm.id
             ORDER BY gm.created_at DESC
             LIMIT ' . max(1, (int) $limit)
        );
    }

    public function findRevealedWithGuessCount(int $limit = 30): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT gm.updated_at, gm.answer_gender, gm.answer_name,
                    COALESCE(gm.title, CONCAT(\'Baby \', u.last_name)) as display_title,
                    gm.token, gm.slug, u.first_name, u.last_name,
                    COUNT(g.id) as guess_count
             FROM game gm
             JOIN user u ON gm.user_id = u.id
             LEFT JOIN guess g ON g.game_id = gm.id
             WHERE gm.status = \'revealed\'
             GROUP BY gm.id
             ORDER BY gm.updated_at DESC
             LIMIT ' . max(1, (int) $limit)
        );
    }
}
