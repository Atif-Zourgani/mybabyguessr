<?php

namespace App\Controller;

use App\Repository\GameRepository;
use App\Repository\GuessRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PlayController extends AbstractController
{
    #[Route('/play/{slug}/{token}', name: 'app_game_play', methods: ['GET', 'POST'])]
    public function identify(
        string $slug,
        string $token,
        Request $request,
        GameRepository $gameRepository,
        GuessRepository $guessRepository,
    ): Response {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null) {
            throw $this->createNotFoundException();
        }

        $sessionKey = 'player_' . $token;

        if ($request->isMethod('GET') && $request->getSession()->has($sessionKey)) {
            return $this->redirectToRoute('app_game_guess', [
                '_locale' => $request->getLocale(),
                'slug'    => $game->getSlug(),
                'token'   => $token,
            ]);
        }

        $errors    = [];
        $nameExists = false;
        $formData  = ['player_name' => '', 'player_email' => ''];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('play_identify', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $playerName  = mb_substr(trim(strip_tags($request->request->get('player_name', ''))), 0, 100);
            $playerEmail = trim($request->request->get('player_email', ''));
            $skipCheck   = (bool) $request->request->get('_skip_name_check', false);

            $formData = ['player_name' => $playerName, 'player_email' => $playerEmail];

            if ($playerName === '') {
                $errors['name'] = true;
            }

            if ($playerEmail !== '' && (!filter_var($playerEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($playerEmail) > 255)) {
                $errors['email'] = true;
            }

            if (empty($errors)) {
                if (!$skipCheck && $guessRepository->nameExistsForGame($game, $playerName)) {
                    $nameExists = true;
                } else {
                    $request->getSession()->set($sessionKey, [
                        'name'  => $playerName,
                        'email' => $playerEmail !== '' ? $playerEmail : null,
                    ]);

                    return $this->redirectToRoute('app_game_guess', [
                        '_locale' => $request->getLocale(),
                        'slug'    => $game->getSlug(),
                        'token'   => $token,
                    ]);
                }
            }
        }

        return $this->render('games/play.html.twig', [
            'game'       => $game,
            'errors'     => $errors,
            'nameExists' => $nameExists,
            'formData'   => $formData,
        ]);
    }

    #[Route('/play/{slug}/{token}/go', name: 'app_game_guess', methods: ['GET'])]
    public function guess(
        string $slug,
        string $token,
        Request $request,
        GameRepository $gameRepository,
    ): Response {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null) {
            throw $this->createNotFoundException();
        }

        $sessionKey = 'player_' . $token;

        if (!$request->getSession()->has($sessionKey)) {
            return $this->redirectToRoute('app_game_play', [
                '_locale' => $request->getLocale(),
                'slug'    => $game->getSlug(),
                'token'   => $token,
            ]);
        }

        $player = $request->getSession()->get($sessionKey);

        return $this->render('games/play_guess.html.twig', [
            'game'   => $game,
            'player' => $player,
        ]);
    }
}
