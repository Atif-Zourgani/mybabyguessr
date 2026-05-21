<?php

namespace App\Controller;

use App\Entity\Guess;
use App\Enum\AnswerGender;
use App\Repository\GameRepository;
use App\Repository\GuessRepository;
use Doctrine\ORM\EntityManagerInterface;
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

        $hasErrors = !empty($errors) || $nameExists;

        return $this->render('games/play.html.twig', [
            'game'       => $game,
            'errors'     => $errors,
            'nameExists' => $nameExists,
            'formData'   => $formData,
        ], new Response('', $hasErrors ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/play/{slug}/{token}/go', name: 'app_game_guess', methods: ['GET', 'POST'])]
    public function guess(
        string $slug,
        string $token,
        Request $request,
        GameRepository $gameRepository,
        EntityManagerInterface $entityManager,
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

        if ($request->isMethod('GET')) {
            return $this->render('games/play_guess.html.twig', [
                'game'     => $game,
                'player'   => $player,
                'errors'   => [],
                'formData' => [],
            ]);
        }

        // POST
        if (!$this->isCsrfTokenValid('play_guess', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $errors          = [];
        $formData        = [];
        $guessDateParsed = null;
        $weightGrams     = null;
        $heightMm        = null;

        if ($game->isGuessGender()) {
            $gender             = $request->request->get('guess_gender', '');
            $formData['gender'] = $gender;
            if (!in_array($gender, ['boy', 'girl'], true)) {
                $errors['gender'] = true;
            }
        }

        if ($game->isGuessName()) {
            $guessName        = mb_substr(trim(strip_tags($request->request->get('guess_name', ''))), 0, 100);
            $formData['name'] = $guessName;
            if ($guessName === '') {
                $errors['name'] = true;
            }
        }

        if ($game->isGuessDate()) {
            $dateStr          = $request->request->get('guess_date', '');
            $formData['date'] = $dateStr;
            if ($dateStr === '') {
                $errors['date'] = true;
            } else {
                try {
                    $guessDateParsed = new \DateTimeImmutable($dateStr);
                } catch (\Throwable) {
                    $errors['date'] = true;
                }
            }
        }

        if ($game->isGuessWeight()) {
            $weightRaw          = $request->request->get('guess_weight', '');
            $weightStr          = str_replace(',', '.', $weightRaw);
            $formData['weight'] = $weightRaw;
            if ($weightStr === '' || !is_numeric($weightStr)) {
                $errors['weight'] = true;
            } else {
                $kg = (float) $weightStr;
                if ($kg < 0.5 || $kg > 7.0) {
                    $errors['weight'] = true;
                } else {
                    $weightGrams = (int) round($kg * 1000);
                }
            }
        }

        if ($game->isGuessHeight()) {
            $heightRaw          = $request->request->get('guess_height', '');
            $heightStr          = str_replace(',', '.', $heightRaw);
            $formData['height'] = $heightRaw;
            if ($heightStr === '' || !is_numeric($heightStr)) {
                $errors['height'] = true;
            } else {
                $cm = (float) $heightStr;
                if ($cm < 20.0 || $cm > 70.0) {
                    $errors['height'] = true;
                } else {
                    $heightMm = (int) round($cm * 10);
                }
            }
        }

        if (!empty($errors)) {
            return $this->render('games/play_guess.html.twig', [
                'game'     => $game,
                'player'   => $player,
                'errors'   => $errors,
                'formData' => $formData,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $guess = new Guess();
        $guess->setGame($game);
        $guess->setPlayerName($player['name']);
        $guess->setPlayerEmail($player['email'] ?? null);

        if ($game->isGuessGender()) {
            $guess->setGuessGender(AnswerGender::from($formData['gender']));
        }
        if ($game->isGuessName()) {
            $guess->setGuessName($formData['name']);
        }
        if ($game->isGuessDate() && $guessDateParsed !== null) {
            $guess->setGuessDate($guessDateParsed);
        }
        if ($game->isGuessWeight() && $weightGrams !== null) {
            $guess->setGuessWeight($weightGrams);
        }
        if ($game->isGuessHeight() && $heightMm !== null) {
            $guess->setGuessHeight($heightMm);
        }

        $entityManager->persist($guess);
        $entityManager->flush();

        $request->getSession()->set('guess_' . $token, $guess->getId());

        return $this->redirectToRoute('app_game_done', [
            '_locale' => $request->getLocale(),
            'slug'    => $game->getSlug(),
            'token'   => $token,
        ]);
    }

    #[Route('/play/{slug}/{token}/done', name: 'app_game_done', methods: ['GET'])]
    public function done(
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

        $guessId    = $request->getSession()->get('guess_' . $token);
        $guess      = $guessId ? $guessRepository->find($guessId) : null;
        $player     = $request->getSession()->get('player_' . $token);
        $playerName = $guess?->getPlayerName() ?? ($player['name'] ?? '');

        return $this->render('games/play_done.html.twig', [
            'game'       => $game,
            'guess'      => $guess,
            'playerName' => $playerName,
        ]);
    }
}
