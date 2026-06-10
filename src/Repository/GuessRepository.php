<?php

namespace App\Repository;

use App\Entity\Game;
use App\Entity\Guess;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class GuessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Guess::class);
    }

    public function findByGame(Game $game): array
    {
        return $this->findBy(['game' => $game], ['createdAt' => 'DESC']);
    }

    public function nameExistsForGame(Game $game, string $playerName): bool
    {
        return $this->count(['game' => $game, 'playerName' => $playerName]) > 0;
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    public function getDailyCreatedTrend(\DateTimeImmutable $since): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DATE(created_at) as day, COUNT(*) as cnt FROM guess WHERE created_at >= :since GROUP BY DATE(created_at) ORDER BY day ASC',
            ['since' => $since->format('Y-m-d')]
        );
    }

    public function findRecentWithGame(int $limit = 10): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT g.player_name, g.created_at,
                    COALESCE(gm.title, CONCAT(\'Baby \', u.last_name)) as display_title,
                    gm.token, gm.slug
             FROM guess g
             JOIN game gm ON g.game_id = gm.id
             JOIN user u ON gm.user_id = u.id
             ORDER BY g.created_at DESC
             LIMIT ' . max(1, (int) $limit)
        );
    }

    public function findRecentWithGameAndUser(int $limit = 50): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT g.player_name, g.player_email, g.created_at,
                    COALESCE(gm.title, CONCAT(\'Baby \', u.last_name)) as display_title,
                    gm.token, gm.slug, u.first_name, u.last_name
             FROM guess g
             JOIN game gm ON g.game_id = gm.id
             JOIN user u ON gm.user_id = u.id
             ORDER BY g.created_at DESC
             LIMIT ' . max(1, (int) $limit)
        );
    }
}
