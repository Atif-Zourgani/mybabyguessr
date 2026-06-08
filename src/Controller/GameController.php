<?php

namespace App\Controller;

use App\Entity\Game;
use App\Enum\AnswerGender;
use App\Enum\GameStatus;
use App\Enum\NameMode;
use App\Repository\GameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
#[Route('/games')]
class GameController extends AbstractController
{
    #[Route('/new', name: 'app_game_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('game_new', $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException();
            }

            $post = $request->request->all();

            $guessGender = ($post['guessGender'] ?? '0') === '1';
            $guessName   = ($post['guessName']   ?? '0') === '1';
            $guessDate   = ($post['guessDate']   ?? '0') === '1';
            $guessWeight = ($post['guessWeight'] ?? '0') === '1';
            $guessHeight = ($post['guessHeight'] ?? '0') === '1';

            if (!($guessGender || $guessName || $guessDate || $guessWeight || $guessHeight)) {
                $this->addFlash('error', 'games.new.error_category');
                return $this->render('games/new.html.twig');
            }

            $game = new Game();
            $game->setUser($this->getUser());
            $game->setGuessGender($guessGender);
            $game->setGuessName($guessName);
            $game->setGuessDate($guessDate);
            $game->setGuessWeight($guessWeight);
            $game->setGuessHeight($guessHeight);

            $title = mb_substr(trim(strip_tags($post['title'] ?? '')), 0, 100);
            if ($title !== '') {
                $game->setTitle($title);
            }

            $slugBase = $title !== '' ? $title : ('Baby ' . ($this->getUser()?->getLastName() ?? 'Guessr'));
            $game->setSlug($slugger->slug($slugBase)->lower()->toString());

            if ($guessDate) {
                $dueDateStr = trim($post['dueDate'] ?? '');
                if ($dueDateStr !== '') {
                    $dueDate = \DateTimeImmutable::createFromFormat('Y-m-d', $dueDateStr);
                    $today   = new \DateTimeImmutable('today');
                    if ($dueDate !== false && $dueDate >= $today) {
                        $game->setDueDate($dueDate);
                    }
                }
            }

            if ($guessName) {
                $nameModeStr = trim($post['nameMode'] ?? '');
                $answerName  = mb_substr(trim($post['answerName'] ?? ''), 0, 100);

                if ($nameModeStr === NameMode::Hints->value && $answerName !== '') {
                    $game->setNameMode(NameMode::Hints);
                    $game->setAnswerName($answerName);
                } else {
                    $game->setNameMode(NameMode::Free);
                }
            }

            $imageFile = $request->files->get('imageFile');
            if ($imageFile !== null) {
                if (!$imageFile->isValid()) {
                    $this->addFlash('error', 'games.new.error_image_upload');
                } else {
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
                    if (!in_array($imageFile->getMimeType(), $allowedMimes, true)) {
                        $this->addFlash('error', 'games.new.error_image_type');
                    } elseif ($imageFile->getSize() > 4 * 1024 * 1024) {
                        $this->addFlash('error', 'games.new.error_image_size');
                    } else {
                        try {
                            $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/games';
                            $ext        = $imageFile->guessExtension() ?? 'jpg';
                            $filename   = bin2hex(random_bytes(16)) . '.' . $ext;
                            $imageFile->move($uploadsDir, $filename);
                            chmod($uploadsDir . '/' . $filename, 0644);
                            $game->setImage('uploads/games/' . $filename);
                        } catch (\Exception) {
                            // image ignorée si déplacement impossible — le jeu est quand même créé
                        }
                    }
                }
            }

            $em->persist($game);
            $em->flush();

            return $this->redirectToRoute('app_game_share', [
                '_locale' => $request->getLocale(),
                'token'   => $game->getToken(),
            ]);
        }

        return $this->render('games/new.html.twig');
    }

    #[Route('/{token}/delete', name: 'app_game_delete', methods: ['POST'])]
    public function delete(string $token, GameRepository $gameRepository, EntityManagerInterface $em, Request $request): Response
    {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null || $game->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('game_delete_' . $token, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($game->getImage()) {
            $imagePath = $this->getParameter('kernel.project_dir') . '/public/' . $game->getImage();
            if (file_exists($imagePath)) {
                @unlink($imagePath);
            }
        }

        $em->remove($game);
        $em->flush();

        $this->addFlash('success', 'games.delete.success');

        return $this->redirectToRoute('app_dashboard', [
            '_locale' => $request->getLocale(),
        ]);
    }

    #[Route('/{token}/toggle-status', name: 'app_game_toggle_status', methods: ['POST'])]
    public function toggleStatus(string $token, GameRepository $gameRepository, EntityManagerInterface $em, Request $request): Response
    {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null || $game->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('game_toggle_' . $token, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($game->getStatus() === GameStatus::Open) {
            $game->setStatus(GameStatus::Closed);
        } elseif ($game->getStatus() === GameStatus::Closed) {
            $game->setStatus(GameStatus::Open);
        }

        $em->flush();

        return $this->redirectToRoute('app_game_show', [
            '_locale' => $request->getLocale(),
            'token'   => $token,
        ]);
    }

    #[Route('/{token}/share', name: 'app_game_share', methods: ['GET'])]
    public function share(string $token, GameRepository $gameRepository, Request $request): Response
    {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null || $game->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if ($game->getStatus() === GameStatus::Closed) {
            $this->addFlash('warning', 'games.share.closed_warning');
            return $this->redirectToRoute('app_game_show', [
                '_locale' => $request->getLocale(),
                'token'   => $token,
            ]);
        }

        return $this->render('games/share.html.twig', ['game' => $game]);
    }

    #[Route('/{token}', name: 'app_game_show', methods: ['GET'])]
    public function show(string $token, GameRepository $gameRepository): Response
    {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null || $game->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $guesses = $game->getGuesses()->toArray();
        $stats   = $this->computeStats($game, $guesses);

        return $this->render('games/show.html.twig', [
            'game'          => $game,
            'guesses'       => $guesses,
            'stats'         => $stats,
            'guessesRanked' => $game->isRevealed() ? $this->rankGuesses($game, $guesses, $stats) : null,
        ]);
    }

    #[Route('/{token}/reveal', name: 'app_game_reveal', methods: ['GET', 'POST'])]
    public function reveal(
        string $token,
        GameRepository $gameRepository,
        EntityManagerInterface $em,
        Request $request,
        MailerInterface $mailer,
        LoggerInterface $logger,
        TranslatorInterface $translator,
    ): Response {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null || $game->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $guesses = $game->getGuesses()->toArray();
        $playersWithEmail = count(array_filter($guesses, fn($g) => $g->getPlayerEmail() !== null));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('game_reveal_' . $token, $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException();
            }

            $wasAlreadyRevealed = $game->isRevealed();
            $post = $request->request->all();

            if ($game->isGuessGender()) {
                $genderStr = $post['answerGender'] ?? '';
                $game->setAnswerGender($genderStr !== '' ? AnswerGender::tryFrom($genderStr) : null);
            }

            if ($game->isGuessName()) {
                $name = mb_substr(trim(strip_tags($post['answerName'] ?? '')), 0, 100);
                $game->setAnswerName($name !== '' ? $name : null);
            }

            if ($game->isGuessDate()) {
                $dateStr = trim($post['answerDate'] ?? '');
                $timeStr = trim($post['answerTime'] ?? '');
                if ($dateStr !== '') {
                    if ($timeStr !== '') {
                        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateStr . ' ' . $timeStr);
                    } else {
                        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateStr . ' 00:00');
                    }
                    $game->setAnswerDate($date !== false ? $date : null);
                } else {
                    $game->setAnswerDate(null);
                }
            }

            if ($game->isGuessWeight()) {
                $weightRaw = str_replace(',', '.', $post['answerWeight'] ?? '');
                if ($weightRaw !== '' && is_numeric($weightRaw)) {
                    $kg = (float) $weightRaw;
                    $game->setAnswerWeight($kg >= 0.5 && $kg <= 7.0 ? (int) round($kg * 1000) : null);
                } else {
                    $game->setAnswerWeight(null);
                }
            }

            if ($game->isGuessHeight()) {
                $heightRaw = str_replace(',', '.', $post['answerHeight'] ?? '');
                if ($heightRaw !== '' && is_numeric($heightRaw)) {
                    $cm = (float) $heightRaw;
                    $game->setAnswerHeight($cm >= 20 && $cm <= 70 ? (int) round($cm * 10) : null);
                } else {
                    $game->setAnswerHeight(null);
                }
            }

            $game->setStatus(GameStatus::Revealed);
            $em->flush();

            $sendEmails = !$wasAlreadyRevealed
                && ($post['notifyPlayers'] ?? '0') === '1'
                && $playersWithEmail > 0;

            if ($sendEmails) {
                $this->sendRevealEmails($game, $mailer, $request->getLocale(), $logger, $translator);
            }

            if ($wasAlreadyRevealed) {
                $this->addFlash('success', 'games.reveal.updated');
                return $this->redirectToRoute('app_game_show', [
                    '_locale' => $request->getLocale(),
                    'token'   => $token,
                ]);
            }

            return $this->redirectToRoute('app_game_reveal_share', [
                '_locale' => $request->getLocale(),
                'token'   => $token,
            ]);
        }

        return $this->render('games/reveal.html.twig', [
            'game'             => $game,
            'playersWithEmail' => $playersWithEmail,
        ]);
    }

    #[Route('/{token}/reveal/share', name: 'app_game_reveal_share', methods: ['GET'])]
    public function revealShare(string $token, GameRepository $gameRepository, Request $request): Response
    {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null || $game->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if (!$game->isRevealed()) {
            return $this->redirectToRoute('app_game_reveal', [
                '_locale' => $request->getLocale(),
                'token'   => $token,
            ]);
        }

        return $this->render('games/reveal_share.html.twig', ['game' => $game]);
    }

    private function sendRevealEmails(Game $game, MailerInterface $mailer, string $locale, LoggerInterface $logger, TranslatorInterface $translator): void
    {
        $revealUrl = $this->generateUrl('app_game_reveal_public', [
            '_locale' => $locale,
            'slug'    => $game->getSlug(),
            'token'   => $game->getToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $subject = $translator->trans('games.reveal_share.email_subject', ['%game%' => $game->getDisplayTitle()], locale: $locale);

        $emailsSent = [];
        foreach ($game->getGuesses() as $guess) {
            $email = $guess->getPlayerEmail();
            if ($email === null || in_array($email, $emailsSent, true)) {
                continue;
            }
            $emailsSent[] = $email;

            $message = (new TemplatedEmail())
                ->from(new Address('noreply@mybabyguessr.com', 'MyBabyGuessr'))
                ->to($email)
                ->subject($subject)
                ->htmlTemplate('emails/reveal.html.twig')
                ->textTemplate('emails/reveal.txt.twig')
                ->context([
                    'locale'     => $locale,
                    'game'       => $game,
                    'playerName' => $guess->getPlayerName(),
                    'revealUrl'  => $revealUrl,
                ]);

            try {
                $mailer->send($message);
            } catch (\Throwable $e) {
                $logger->error('Reveal email failed', [
                    'game'  => $game->getToken(),
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function rankGuesses(Game $game, array $guesses, array $stats): array
    {
        $maxScore = 0;
        if ($game->isGuessGender() && $game->getAnswerGender() !== null) $maxScore++;
        if ($game->isGuessName()   && $game->getAnswerName()   !== null) $maxScore++;
        if ($game->isGuessDate()   && $game->getAnswerDate()   !== null) $maxScore++;
        if ($game->isGuessWeight() && $game->getAnswerWeight() !== null) $maxScore++;
        if ($game->isGuessHeight() && $game->getAnswerHeight() !== null) $maxScore++;

        $minDateDiff   = isset($stats['date']['sorted_by_diff'])   ? $stats['date']['sorted_by_diff'][0]['diff']   : null;
        $minWeightDiff = isset($stats['weight']['sorted_by_diff']) ? $stats['weight']['sorted_by_diff'][0]['diff'] : null;
        $minHeightDiff = isset($stats['height']['sorted_by_diff']) ? $stats['height']['sorted_by_diff'][0]['diff'] : null;

        $scored = [];
        foreach ($guesses as $g) {
            $score = 0;
            if ($game->isGuessGender() && $game->getAnswerGender() !== null)
                if ($g->getGuessGender()?->value === $game->getAnswerGender()->value) $score++;
            if ($game->isGuessName() && $game->getAnswerName() !== null && $g->getGuessName() !== null)
                if (mb_strtolower(trim($g->getGuessName())) === mb_strtolower(trim($game->getAnswerName()))) $score++;
            if ($minDateDiff !== null && $g->getGuessDate() !== null)
                if (abs($g->getGuessDate()->getTimestamp() - $game->getAnswerDate()->getTimestamp()) === $minDateDiff) $score++;
            if ($minWeightDiff !== null && $g->getGuessWeight() !== null)
                if (abs($g->getGuessWeight() - $game->getAnswerWeight()) === $minWeightDiff) $score++;
            if ($minHeightDiff !== null && $g->getGuessHeight() !== null)
                if (abs($g->getGuessHeight() - $game->getAnswerHeight()) === $minHeightDiff) $score++;
            $scored[] = ['guess' => $g, 'score' => $score];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['guess']->getCreatedAt() <=> $b['guess']->getCreatedAt());
        return ['items' => $scored, 'max' => $maxScore];
    }

    private function computeStats(Game $game, array $guesses): array
    {
        $stats = ['total' => count($guesses)];
        $isRevealed = $game->isRevealed();

        if ($game->isGuessGender()) {
            $boys = 0;
            $girls = 0;
            foreach ($guesses as $g) {
                match ($g->getGuessGender()?->value) {
                    'boy'  => $boys++,
                    'girl' => $girls++,
                    default => null,
                };
            }
            $total = $boys + $girls;
            $stats['gender'] = [
                'boy'      => $boys,
                'girl'     => $girls,
                'total'    => $total,
                'boy_pct'  => $total > 0 ? (int) round($boys / $total * 100) : 0,
                'girl_pct' => $total > 0 ? (int) round($girls / $total * 100) : 0,
            ];
            if ($isRevealed && $game->getAnswerGender() !== null) {
                $correctVal = $game->getAnswerGender()->value;
                $gWinners   = array_filter($guesses, fn($g) => $g->getGuessGender()?->value === $correctVal);
                $stats['gender']['winner_names']  = array_values(array_map(fn($g) => $g->getPlayerName(), $gWinners));
                $stats['gender']['correct_count'] = count($gWinners);
                $stats['gender']['correct_pct']   = $total > 0 ? (int) round(count($gWinners) / $total * 100) : 0;
            }
        }

        if ($game->isGuessName()) {
            $named = array_values(array_filter($guesses, fn($g) => $g->getGuessName() !== null));
            if ($named) {
                $freq = [];
                foreach ($named as $g) {
                    $n = $g->getGuessName();
                    $freq[$n] = ($freq[$n] ?? 0) + 1;
                }
                arsort($freq);

                $topName  = array_key_first($freq);
                $topCount = $freq[$topName];

                $sorted = $named;
                usort($sorted, fn($a, $b) => $a->getCreatedAt() <=> $b->getCreatedAt());
                $last = end($sorted);

                $stats['name'] = [
                    'freq'         => $freq,
                    'top'          => $topName,
                    'top_count'    => $topCount,
                    'unique_count' => count($freq),
                    'last'         => $last->getGuessName(),
                    'last_player'  => $last->getPlayerName(),
                ];
                if ($isRevealed && $game->getAnswerName() !== null) {
                    $answerNorm = mb_strtolower(trim($game->getAnswerName()));
                    $nWinners   = array_filter($named, fn($g) => mb_strtolower(trim($g->getGuessName() ?? '')) === $answerNorm);
                    $stats['name']['winner_names'] = array_values(array_map(fn($g) => $g->getPlayerName(), $nWinners));
                }
            }
        }

        if ($game->isGuessDate()) {
            $dated = array_values(array_filter($guesses, fn($g) => $g->getGuessDate() !== null));
            if ($dated) {
                usort($dated, fn($a, $b) => $a->getGuessDate() <=> $b->getGuessDate());
                $timestamps = array_map(fn($g) => $g->getGuessDate()->getTimestamp(), $dated);
                $ds = [
                    'sorted'   => $dated,
                    'earliest' => $dated[0],
                    'latest'   => $dated[count($dated) - 1],
                    'avg_date' => new \DateTimeImmutable('@' . (int) round(array_sum($timestamps) / count($timestamps))),
                ];
                if ($game->getDueDate()) {
                    $dueTs = $game->getDueDate()->getTimestamp();
                    $byProx = $dated;
                    usort($byProx, fn($a, $b) =>
                        abs($a->getGuessDate()->getTimestamp() - $dueTs) <=>
                        abs($b->getGuessDate()->getTimestamp() - $dueTs)
                    );
                    $ds['closest'] = $byProx[0];
                }
                if ($isRevealed && $game->getAnswerDate() !== null) {
                    $ansTs     = $game->getAnswerDate()->getTimestamp();
                    $dWithDiff = array_map(fn($g) => ['guess' => $g, 'diff' => abs($g->getGuessDate()->getTimestamp() - $ansTs)], $dated);
                    usort($dWithDiff, fn($a, $b) => $a['diff'] <=> $b['diff']);
                    $ds['sorted_by_diff'] = $dWithDiff;
                }
                $stats['date'] = $ds;
            }
        }

        if ($game->isGuessWeight()) {
            $ws = array_values(array_filter($guesses, fn($g) => $g->getGuessWeight() !== null));
            if ($ws) {
                $vals = array_map(fn($g) => $g->getGuessWeight(), $ws);
                $min  = min($vals);
                $max  = max($vals);
                $wst  = ['avg' => array_sum($vals) / count($vals), 'min' => $min, 'max' => $max];
                foreach ($ws as $g) {
                    if ($g->getGuessWeight() === $min && !isset($wst['min_player'])) {
                        $wst['min_player'] = $g->getPlayerName();
                    }
                    if ($g->getGuessWeight() === $max && !isset($wst['max_player'])) {
                        $wst['max_player'] = $g->getPlayerName();
                    }
                }
                usort($ws, fn($a, $b) => $a->getGuessWeight() <=> $b->getGuessWeight());
                $wst['sorted'] = $ws;
                if ($isRevealed && $game->getAnswerWeight() !== null) {
                    $ansW      = $game->getAnswerWeight();
                    $wWithDiff = array_map(fn($g) => ['guess' => $g, 'diff' => abs($g->getGuessWeight() - $ansW)], $ws);
                    usort($wWithDiff, fn($a, $b) => $a['diff'] <=> $b['diff']);
                    $wst['sorted_by_diff'] = $wWithDiff;
                }
                $stats['weight'] = $wst;
            }
        }

        if ($game->isGuessHeight()) {
            $hs = array_values(array_filter($guesses, fn($g) => $g->getGuessHeight() !== null));
            if ($hs) {
                $vals = array_map(fn($g) => $g->getGuessHeight(), $hs);
                $min  = min($vals);
                $max  = max($vals);
                $hst  = ['avg' => array_sum($vals) / count($vals), 'min' => $min, 'max' => $max];
                foreach ($hs as $g) {
                    if ($g->getGuessHeight() === $min && !isset($hst['min_player'])) {
                        $hst['min_player'] = $g->getPlayerName();
                    }
                    if ($g->getGuessHeight() === $max && !isset($hst['max_player'])) {
                        $hst['max_player'] = $g->getPlayerName();
                    }
                }
                usort($hs, fn($a, $b) => $a->getGuessHeight() <=> $b->getGuessHeight());
                $hst['sorted'] = $hs;
                if ($isRevealed && $game->getAnswerHeight() !== null) {
                    $ansH      = $game->getAnswerHeight();
                    $hWithDiff = array_map(fn($g) => ['guess' => $g, 'diff' => abs($g->getGuessHeight() - $ansH)], $hs);
                    usort($hWithDiff, fn($a, $b) => $a['diff'] <=> $b['diff']);
                    $hst['sorted_by_diff'] = $hWithDiff;
                }
                $stats['height'] = $hst;
            }
        }

        return $stats;
    }
}
