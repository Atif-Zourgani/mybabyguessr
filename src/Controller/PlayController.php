<?php

namespace App\Controller;

use App\Entity\Game;
use App\Entity\Guess;
use App\Enum\AnswerGender;
use App\Enum\GameStatus;
use App\Repository\GameRepository;
use App\Repository\GuessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

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

        if ($game->getStatus() === GameStatus::Closed) {
            return $this->render('games/play_closed.html.twig', ['game' => $game]);
        }

        if ($game->getStatus() === GameStatus::Revealed) {
            return $this->redirectToRoute('app_game_reveal_public', [
                '_locale' => $request->getLocale(),
                'slug'    => $game->getSlug(),
                'token'   => $token,
            ]);
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

    #[Route('/play/{slug}/{token}/hint/{attempt}', name: 'app_game_hint', methods: ['POST'])]
    public function hint(
        string $slug,
        string $token,
        int $attempt,
        Request $request,
        GameRepository $gameRepository,
        TranslatorInterface $translator,
    ): JsonResponse {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null || !$game->isGuessName() || $game->getNameMode()?->value !== 'hints') {
            return new JsonResponse(['error' => 'invalid'], 400);
        }

        if ($game->getStatus() !== GameStatus::Open) {
            return new JsonResponse(['error' => 'closed'], 403);
        }

        if (!$request->getSession()->has('player_' . $token)) {
            return new JsonResponse(['error' => 'no_session'], 401);
        }

        if (!in_array($attempt, [1, 2, 3], true)) {
            return new JsonResponse(['error' => 'invalid_attempt'], 400);
        }

        $hintsKey     = 'hints_' . $token;
        $hints        = $request->getSession()->get($hintsKey, []);
        $alreadyDone  = count(array_filter([
            $hints['attempt1'] ?? null,
            $hints['attempt2'] ?? null,
            $hints['attempt3'] ?? null,
        ]));

        if ($attempt !== $alreadyDone + 1) {
            return new JsonResponse(['error' => 'out_of_order'], 400);
        }

        $name = mb_substr(trim(strip_tags($request->request->get('name', ''))), 0, 100);
        if ($name === '') {
            return new JsonResponse(['error' => 'empty_name'], 422);
        }

        $hints['attempt' . $attempt] = $name;
        $request->getSession()->set($hintsKey, $hints);

        if ($attempt >= 3) {
            return new JsonResponse(['done' => true]);
        }

        $answerName = $game->getAnswerName() ?? '';
        $clue = $this->computeHintClueText($answerName, $name, $attempt + 1, $translator);

        return new JsonResponse(['clue' => $clue, 'done' => false]);
    }

    #[Route('/play/{slug}/{token}/go', name: 'app_game_guess', methods: ['GET', 'POST'])]
    public function guess(
        string $slug,
        string $token,
        Request $request,
        GameRepository $gameRepository,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null) {
            throw $this->createNotFoundException();
        }

        if ($game->getStatus() === GameStatus::Closed) {
            return $this->render('games/play_closed.html.twig', ['game' => $game]);
        }

        if ($game->getStatus() === GameStatus::Revealed) {
            return $this->redirectToRoute('app_game_reveal_public', [
                '_locale' => $request->getLocale(),
                'slug'    => $game->getSlug(),
                'token'   => $token,
            ]);
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
                'game'       => $game,
                'player'     => $player,
                'errors'     => [],
                'formData'   => [],
                'hintsState' => $this->buildHintsState($game, $token, $request, $translator),
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
            if ($game->getNameMode()?->value === 'hints') {
                $hints = $request->getSession()->get('hints_' . $token, []);
                if (empty($hints['attempt1']) || empty($hints['attempt2']) || empty($hints['attempt3'])) {
                    $errors['name'] = true;
                }
            } else {
                $guessName        = mb_substr(trim(strip_tags($request->request->get('guess_name', ''))), 0, 100);
                $formData['name'] = $guessName;
                if ($guessName === '') {
                    $errors['name'] = true;
                }
            }
        }

        if ($game->isGuessDate()) {
            $dateStr          = $request->request->get('guess_date', '');
            $timeStr          = trim($request->request->get('guess_time', ''));
            $formData['date'] = $dateStr;
            $formData['time'] = $timeStr;
            if ($dateStr === '') {
                $errors['date'] = true;
            } else {
                try {
                    $dateTimeStr     = $dateStr . ' ' . ($timeStr !== '' ? $timeStr : '00:00');
                    $guessDateParsed = new \DateTimeImmutable($dateTimeStr);
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
                'game'       => $game,
                'player'     => $player,
                'errors'     => $errors,
                'formData'   => $formData,
                'hintsState' => $this->buildHintsState($game, $token, $request, $translator),
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
            if ($game->getNameMode()?->value === 'hints') {
                $hints = $request->getSession()->get('hints_' . $token, []);
                $a1 = $hints['attempt1'] ?? null;
                $a2 = $hints['attempt2'] ?? null;
                $a3 = $hints['attempt3'] ?? null;
                $guess->setNameAttempt1($a1);
                $guess->setNameAttempt2($a2);
                $guess->setNameAttempt3($a3);
                $guess->setGuessName($a3 ?? $a2 ?? $a1);
                $guess->setNameHintsUsed(count(array_filter([$a1, $a2, $a3])));
            } else {
                $guess->setGuessName($formData['name']);
            }
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

    #[Route('/play/{slug}/{token}/reveal', name: 'app_game_reveal_public', methods: ['GET'])]
    public function revealPublic(
        string $slug,
        string $token,
        GameRepository $gameRepository,
    ): Response {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null) {
            throw $this->createNotFoundException();
        }

        if (!$game->isRevealed()) {
            return $this->redirectToRoute('app_game_play', [
                'slug'  => $game->getSlug(),
                'token' => $token,
            ]);
        }

        $guesses = $game->getGuesses()->toArray();
        usort($guesses, fn($a, $b) => $a->getCreatedAt() <=> $b->getCreatedAt());

        return $this->render('games/play_reveal.html.twig', [
            'game'    => $game,
            'winners' => $this->computeRevealWinners($game, $guesses),
        ]);
    }

    private function buildHintsState(Game $game, string $token, Request $request, TranslatorInterface $translator): array
    {
        $empty = ['clues' => [], 'proposals' => [], 'step' => 1, 'completed' => false];

        if (!$game->isGuessName() || $game->getNameMode()?->value !== 'hints') {
            return $empty;
        }

        $answer = $game->getAnswerName();
        if ($answer === null) {
            return $empty;
        }

        $hints    = $request->getSession()->get('hints_' . $token, []);
        $a1       = $hints['attempt1'] ?? null;
        $a2       = $hints['attempt2'] ?? null;
        $a3       = $hints['attempt3'] ?? null;
        $proposals = array_values(array_filter([$a1, $a2, $a3]));

        $clues   = [$this->computeHintClueText($answer, null, 1, $translator)];
        if ($a1 !== null) $clues[] = $this->computeHintClueText($answer, $a1, 2, $translator);
        if ($a2 !== null) $clues[] = $this->computeHintClueText($answer, $a2, 3, $translator);

        $done = count($proposals) >= 3;

        return [
            'clues'     => array_slice($clues, 0, $done ? 3 : count($proposals) + 1),
            'proposals' => $proposals,
            'step'      => $done ? 3 : count($proposals) + 1,
            'completed' => $done,
        ];
    }

    private function computeHintClueText(string $answer, ?string $prev, int $clueNumber, TranslatorInterface $translator): string
    {
        return match ($clueNumber) {
            1 => $this->hint1Text($answer, $translator),
            2 => $this->hint2Text($answer, $prev ?? '', $translator),
            3 => $this->hint3Text($answer, $prev ?? '', $translator),
            default => '',
        };
    }

    private function hint1Text(string $answer, TranslatorInterface $translator): string
    {
        $last = mb_strtolower(mb_substr($answer, -1));
        $vowels = ['a','e','i','o','u','y','à','â','ä','á','ã','é','è','ê','ë','î','ï','í','ì','ô','ö','ó','ò','ù','û','ü','ú','ÿ','ý'];
        $key = in_array($last, $vowels) ? 'play.guess.hint1_vowel' : 'play.guess.hint1_consonant';
        return $translator->trans($key);
    }

    private function hint2Text(string $answer, string $prev, TranslatorInterface $translator): string
    {
        $al = mb_strlen($answer);
        $pl = mb_strlen($prev);
        $key = $al > $pl ? 'play.guess.hint2_longer' : ($al < $pl ? 'play.guess.hint2_shorter' : 'play.guess.hint2_same');
        return $translator->trans($key);
    }

    private function hint3Text(string $answer, string $prev, TranslatorInterface $translator): string
    {
        $normalize = static function (string $s): string {
            $c = mb_strtolower(mb_substr($s, 0, 1));
            return strtr($c, ['à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                               'î'=>'i','ï'=>'i','í'=>'i','ì'=>'i','ô'=>'o','ö'=>'o','ó'=>'o','ò'=>'o',
                               'ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u','ÿ'=>'y','ý'=>'y','ç'=>'c','ñ'=>'n']);
        };
        $af = $normalize($answer);
        $pf = $normalize($prev);
        $letter = mb_strtoupper(mb_substr($prev, 0, 1));
        $key = $af < $pf ? 'play.guess.hint3_before' : ($af > $pf ? 'play.guess.hint3_after' : 'play.guess.hint3_same');
        return $translator->trans($key, ['%letter%' => $letter]);
    }

    private function computeRevealWinners(Game $game, array $guesses): array
    {
        $winners = [];

        // Sexe — tous les corrects, ordre chronologique
        if ($game->isGuessGender() && $game->getAnswerGender() !== null) {
            $correct = array_values(array_filter(
                $guesses, fn($g) => $g->getGuessGender() === $game->getAnswerGender()
            ));
            $winners['gender'] = array_map(fn($g) => ['guess' => $g, 'diff' => null], $correct);
        }

        // Prénom — tous les corrects
        if ($game->isGuessName() && $game->getAnswerName() !== null) {
            $norm    = mb_strtolower(trim($game->getAnswerName()));
            $correct = array_values(array_filter(
                $guesses,
                fn($g) => $g->getGuessName() !== null
                    && mb_strtolower(trim($g->getGuessName())) === $norm
            ));
            if ($game->getNameMode()?->value === 'hints') {
                // Hints : tri par nb d'essais ASC, puis chronologique (moins = meilleur)
                usort($correct, fn($a, $b) =>
                    $a->getNameHintsUsed() <=> $b->getNameHintsUsed()
                    ?: $a->getCreatedAt() <=> $b->getCreatedAt()
                );
                $winners['name'] = array_map(
                    fn($g) => ['guess' => $g, 'diff' => null, 'attempts' => $g->getNameHintsUsed()],
                    $correct
                );
            } else {
                // Libre : ordre chronologique (premier = meilleur)
                $winners['name'] = array_map(fn($g) => ['guess' => $g, 'diff' => null], $correct);
            }
        }

        // Date — top 3 les plus proches, diff en secondes
        if ($game->isGuessDate() && $game->getAnswerDate() !== null) {
            $answerTs = $game->getAnswerDate()->getTimestamp();
            $dated    = array_values(array_filter($guesses, fn($g) => $g->getGuessDate() !== null));
            if ($dated) {
                usort($dated, fn($a, $b) =>
                    abs($a->getGuessDate()->getTimestamp() - $answerTs) <=>
                    abs($b->getGuessDate()->getTimestamp() - $answerTs)
                );
                $winners['date'] = array_map(
                    fn($g) => ['guess' => $g, 'diff' => abs($g->getGuessDate()->getTimestamp() - $answerTs)],
                    array_slice($dated, 0, 3)
                );
            }
        }

        // Poids — top 3 les plus proches, diff en grammes
        if ($game->isGuessWeight() && $game->getAnswerWeight() !== null) {
            $answerW  = $game->getAnswerWeight();
            $weighted = array_values(array_filter($guesses, fn($g) => $g->getGuessWeight() !== null));
            if ($weighted) {
                usort($weighted, fn($a, $b) =>
                    abs($a->getGuessWeight() - $answerW) <=>
                    abs($b->getGuessWeight() - $answerW)
                );
                $winners['weight'] = array_map(
                    fn($g) => ['guess' => $g, 'diff' => abs($g->getGuessWeight() - $answerW)],
                    array_slice($weighted, 0, 3)
                );
            }
        }

        // Taille — top 3 les plus proches, diff en mm
        if ($game->isGuessHeight() && $game->getAnswerHeight() !== null) {
            $answerH  = $game->getAnswerHeight();
            $heighted = array_values(array_filter($guesses, fn($g) => $g->getGuessHeight() !== null));
            if ($heighted) {
                usort($heighted, fn($a, $b) =>
                    abs($a->getGuessHeight() - $answerH) <=>
                    abs($b->getGuessHeight() - $answerH)
                );
                $winners['height'] = array_map(
                    fn($g) => ['guess' => $g, 'diff' => abs($g->getGuessHeight() - $answerH)],
                    array_slice($heighted, 0, 3)
                );
            }
        }

        return $winners;
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
