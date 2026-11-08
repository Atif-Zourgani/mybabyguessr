<?php

namespace App\Controller;

use App\Entity\Game;
use App\Entity\ModerationLog;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Enum\ModerationAction;
use App\Repository\GameRepository;
use App\Repository\GuessRepository;
use App\Service\ModerationLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', name: 'app_admin_')]
class AdminGameController extends AbstractController
{
    #[Route('/games', name: 'games', methods: ['GET'])]
    public function index(Request $request, GameRepository $games, GuessRepository $guesses): Response
    {
        $filters = [
            'q'          => trim($request->query->getString('q')),
            'status'     => $request->query->getString('status'),       // '' | open | closed | revealed
            'visibility' => $request->query->getString('visibility'),   // '' | hidden | visible
            'sort'       => $request->query->getString('sort', 'created'),
            'dir'        => $request->query->getString('dir', 'desc'),
        ];

        $page = $games->searchForAdmin(
            $filters['q'],
            GameStatus::tryFrom($filters['status']),
            match ($filters['visibility']) { 'hidden' => true, 'visible' => false, default => null },
            null,
            $filters['sort'],
            $filters['dir'],
            $request->query->getInt('page', 1),
        );

        $byStatus     = $games->countByStatus();
        $totalGames   = array_sum($byStatus);
        $totalGuesses = $guesses->countAll();

        return $this->render('admin/games.html.twig', [
            'page'         => $page,
            'filters'      => $filters,
            'gamesByStatus' => $byStatus,
            'totalGames'   => $totalGames,
            'hiddenGames'  => $games->countHidden(),
            'totalGuesses' => $totalGuesses,
            'avgGuesses'   => $totalGames > 0 ? round($totalGuesses / $totalGames, 1) : 0,
            'catStats'     => $games->getCategoryStats(),
        ]);
    }

    #[Route('/games/{id}/hide', name: 'game_hide', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function hide(Game $game, Request $request, EntityManagerInterface $em, ModerationLogger $logger): Response
    {
        if (!$this->isCsrfTokenValid('admin_game_moderate_' . $game->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$game->isHidden()) {
            $game->setHiddenAt(new \DateTimeImmutable());
            $logger->log($this->adminUser(), ModerationAction::GameHidden, ModerationLog::TARGET_GAME, $game->getId(), $this->label($game), trim($request->request->getString('reason')));
            $em->flush();
            $this->addFlash('success', sprintf('Jeu « %s » masqué.', $game->getDisplayTitle()));
        }

        return $this->back($request);
    }

    #[Route('/games/{id}/restore', name: 'game_restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(Game $game, Request $request, EntityManagerInterface $em, ModerationLogger $logger): Response
    {
        if (!$this->isCsrfTokenValid('admin_game_moderate_' . $game->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($game->isHidden()) {
            $game->setHiddenAt(null);
            $logger->log($this->adminUser(), ModerationAction::GameRestored, ModerationLog::TARGET_GAME, $game->getId(), $this->label($game), trim($request->request->getString('reason')));
            $em->flush();
            $this->addFlash('success', sprintf('Jeu « %s » rétabli.', $game->getDisplayTitle()));
        }

        return $this->back($request);
    }

    private function label(Game $game): string
    {
        return sprintf('%s (de %s)', $game->getDisplayTitle(), $game->getUser()?->getEmail());
    }

    /** Retour à la page d'où vient l'action, uniquement vers un chemin interne. */
    private function back(Request $request): Response
    {
        $return = $request->request->getString('_return');
        if ($return !== '' && str_starts_with($return, '/') && !str_starts_with($return, '//') && !str_contains($return, '\\')) {
            return $this->redirect($return);
        }

        return $this->redirectToRoute('app_admin_games', ['_locale' => $request->getLocale()]);
    }

    private function adminUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
