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
}
