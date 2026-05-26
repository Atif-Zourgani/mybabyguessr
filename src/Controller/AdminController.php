<?php

namespace App\Controller;

use App\Repository\GameRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', name: 'app_admin_')]
class AdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        UserRepository $userRepository,
        GameRepository $gameRepository,
        EntityManagerInterface $em,
    ): Response {
        $users = $userRepository->findBy([], ['id' => 'DESC']);
        $games = $gameRepository->findBy([], ['createdAt' => 'DESC']);

        $totalGuesses = (int) $em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM guess');

        $gamesByStatus = [];
        foreach ($games as $game) {
            $gamesByStatus[$game->getStatus()->value] = ($gamesByStatus[$game->getStatus()->value] ?? 0) + 1;
        }

        return $this->render('admin/index.html.twig', [
            'users'         => $users,
            'games'         => $games,
            'totalGuesses'  => $totalGuesses,
            'gamesByStatus' => $gamesByStatus,
        ]);
    }
}
