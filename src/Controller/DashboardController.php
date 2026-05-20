<?php

namespace App\Controller;

use App\Repository\GameRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(GameRepository $gameRepository): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'games' => $gameRepository->findByUser($this->getUser()),
        ]);
    }
}
