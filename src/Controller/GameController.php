<?php

namespace App\Controller;

use App\Entity\Game;
use App\Enum\NameMode;
use App\Repository\GameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/games')]
class GameController extends AbstractController
{
    #[Route('/new', name: 'app_game_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
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

            $title = trim($post['title'] ?? '');
            if ($title !== '') {
                $game->setTitle(mb_substr($title, 0, 100));
            }

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
            if ($imageFile !== null && $imageFile->isValid()) {
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
                if (
                    in_array($imageFile->getMimeType(), $allowedMimes, true)
                    && $imageFile->getSize() <= 2 * 1024 * 1024
                ) {
                    try {
                        $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/games';
                        $ext        = $imageFile->guessExtension() ?? 'jpg';
                        $filename   = bin2hex(random_bytes(16)) . '.' . $ext;
                        $imageFile->move($uploadsDir, $filename);
                        $game->setImage('uploads/games/' . $filename);
                    } catch (\Exception) {
                        // image ignorée si déplacement impossible — le jeu est quand même créé
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

    #[Route('/{token}/share', name: 'app_game_share', methods: ['GET'])]
    public function share(string $token, GameRepository $gameRepository): Response
    {
        $game = $gameRepository->findOneByToken($token);

        if ($game === null || $game->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
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

        return $this->render('games/show.html.twig', ['game' => $game]);
    }
}
