<?php

namespace App\Service;

use App\Entity\ModerationLog;
use App\Entity\User;
use App\Enum\ModerationAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistre une action de modération. Ne fait pas de flush : l'appelant valide l'action
 * et sa trace dans une même transaction.
 */
class ModerationLogger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function log(User $admin, ModerationAction $action, string $targetType, ?int $targetId, string $targetLabel, ?string $reason = null): void
    {
        $this->em->persist(new ModerationLog($admin, $action, $targetType, $targetId, $targetLabel, $reason));
    }
}
