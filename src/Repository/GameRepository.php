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
}
