<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/games')]
class GameController extends AbstractController
{
    #[Route('/new', name: 'app_game_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('games/new.html.twig');
    }
}
