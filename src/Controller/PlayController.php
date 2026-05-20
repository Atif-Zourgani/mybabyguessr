<?php

namespace App\Controller;

use App\Repository\GameRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PlayController extends AbstractController
{
    #[Route('/play/{slug}/{token}', name: 'app_game_play', methods: ['GET'])]
    public function play(string $slug, string $token, GameRepository $gameRepository): Response
    {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('games/play.html.twig', ['game' => $game]);
    }
}
