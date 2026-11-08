<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Supprime un compte avec ses jeux, pronostics et images téléversées.
 * Utilisé par la suppression depuis le profil et par la modération.
 */
class UserAccountRemover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /** Valide aussi les écritures en attente (par exemple une trace de modération persistée avant l'appel). */
    public function remove(User $user): void
    {
        $images = [];
        foreach ($user->getGames() as $game) {
            if ($game->getImage()) {
                $images[] = $this->projectDir . '/public/uploads/games/' . basename($game->getImage());
            }
        }

        $this->em->remove($user);
        $this->em->flush();

        // Les fichiers ne sont supprimés qu'après la base : si l'écriture échoue, aucune image n'est perdue
        foreach ($images as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
