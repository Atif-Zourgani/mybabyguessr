<?php

namespace App\Controller;

use App\Repository\GameRepository;
use App\Repository\GuessRepository;
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
    // ── Overview ──────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        UserRepository $userRepository,
        GameRepository $gameRepository,
        GuessRepository $guessRepository,
        EntityManagerInterface $em,
    ): Response {
        $conn         = $em->getConnection();
        $since7days   = new \DateTimeImmutable('-7 days');
        $since30days  = new \DateTimeImmutable('-30 days');

        $totalUsers    = $userRepository->count([]);
        $verifiedCount = $userRepository->countVerified();
        $games         = $gameRepository->findBy([], ['createdAt' => 'DESC']);
        $totalGuesses  = $guessRepository->countAll();

        $recentResets = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM reset_password_request WHERE requested_at >= :since',
            ['since' => $since30days->format('Y-m-d')]
        );

        $gamesByStatus = [];
        foreach ($games as $game) {
            $s = $game->getStatus()->value;
            $gamesByStatus[$s] = ($gamesByStatus[$s] ?? 0) + 1;
        }

        $avgGuessesPerGame = count($games) > 0 ? round($totalGuesses / count($games), 1) : 0;

        $gamesTrend   = $this->buildTrend($gameRepository->getDailyCreatedTrend($since7days));
        $guessesTrend = $this->buildTrend($guessRepository->getDailyCreatedTrend($since7days));
        $recentGuesses = $guessRepository->findRecentWithGame(10);
        $recentGames   = $gameRepository->findRecentWithGuessCount(8);

        return $this->render('admin/index.html.twig', [
            'totalUsers'        => $totalUsers,
            'verifiedCount'     => $verifiedCount,
            'totalGames'        => count($games),
            'totalGuesses'      => $totalGuesses,
            'gamesByStatus'     => $gamesByStatus,
            'avgGuessesPerGame' => $avgGuessesPerGame,
            'recentResets'      => $recentResets,
            'gamesTrend'        => $gamesTrend,
            'guessesTrend'      => $guessesTrend,
            'recentGuesses'     => $recentGuesses,
            'recentGames'       => $recentGames,
        ]);
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    #[Route('/users', name: 'users', methods: ['GET'])]
    public function users(UserRepository $userRepository): Response
    {
        return $this->render('admin/users.html.twig', [
            'users'         => $userRepository->findBy([], ['id' => 'DESC']),
            'totalVerified' => $userRepository->countVerified(),
            'totalAdmins'   => $userRepository->countAdmins(),
        ]);
    }

    // ── Games ─────────────────────────────────────────────────────────────────

    #[Route('/games', name: 'games', methods: ['GET'])]
    public function games(
        GameRepository $gameRepository,
        GuessRepository $guessRepository,
    ): Response {
        $games = $gameRepository->findBy([], ['createdAt' => 'DESC']);

        $gamesByStatus = [];
        foreach ($games as $game) {
            $s = $game->getStatus()->value;
            $gamesByStatus[$s] = ($gamesByStatus[$s] ?? 0) + 1;
        }

        $totalGuesses = $guessRepository->countAll();

        return $this->render('admin/games.html.twig', [
            'games'         => $games,
            'gamesByStatus' => $gamesByStatus,
            'totalGuesses'  => $totalGuesses,
            'avgGuesses'    => count($games) > 0 ? round($totalGuesses / count($games), 1) : 0,
            'catStats'      => $gameRepository->getCategoryStats(),
        ]);
    }

    // ── Activity logs ─────────────────────────────────────────────────────────

    #[Route('/logs', name: 'logs', methods: ['GET'])]
    public function logs(
        GameRepository $gameRepository,
        GuessRepository $guessRepository,
        EntityManagerInterface $em,
    ): Response {
        $conn          = $em->getConnection();
        $recentGuesses = $guessRepository->findRecentWithGameAndUser(50);
        $recentGames   = $gameRepository->findLogsWithUserAndGuessCount(30);
        $recentReveals = $gameRepository->findRevealedWithGuessCount(30);

        $errorLogs = $conn->fetchAllAssociative(
            'SELECT status_code, url, message, user_email, created_at FROM app_error_log ORDER BY created_at DESC LIMIT 100'
        );

        $errorStats = $conn->fetchAllAssociative(
            'SELECT status_code, COUNT(*) as cnt FROM app_error_log WHERE created_at >= :since GROUP BY status_code ORDER BY cnt DESC',
            ['since' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d')]
        );

        return $this->render('admin/logs.html.twig', [
            'recentGuesses' => $recentGuesses,
            'recentGames'   => $recentGames,
            'recentReveals' => $recentReveals,
            'errorLogs'     => $errorLogs,
            'errorStats'    => $errorStats,
        ]);
    }

    // ── Security ──────────────────────────────────────────────────────────────

    #[Route('/security', name: 'security', methods: ['GET'])]
    public function security(
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $conn        = $em->getConnection();
        $since30days = new \DateTimeImmutable('-30 days');

        $resetRequests = $conn->fetchAllAssociative(
            'SELECT r.requested_at, r.expires_at, u.email, u.first_name, u.last_name
             FROM reset_password_request r
             JOIN user u ON r.user_id = u.id
             ORDER BY r.requested_at DESC
             LIMIT 50'
        );

        $unverifiedUsers = $conn->fetchAllAssociative(
            'SELECT id, email, first_name, last_name FROM user WHERE is_verified = 0 ORDER BY id DESC'
        );

        $totalResets30d = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM reset_password_request WHERE requested_at >= :since',
            ['since' => $since30days->format('Y-m-d')]
        );

        $expiredResets = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM reset_password_request WHERE expires_at < NOW()'
        );

        return $this->render('admin/security.html.twig', [
            'resetRequests'   => $resetRequests,
            'unverifiedUsers' => $unverifiedUsers,
            'adminUsers'      => $userRepository->findAllAdmins(),
            'totalResets30d'  => $totalResets30d,
            'expiredResets'   => $expiredResets,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildTrend(array $rawData, int $days = 7): array
    {
        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = (new \DateTimeImmutable("-{$i} days"))->format('Y-m-d');
            $trend[$day] = 0;
        }
        foreach ($rawData as $row) {
            $day = substr((string) $row['day'], 0, 10);
            if (isset($trend[$day])) {
                $trend[$day] = (int) $row['cnt'];
            }
        }

        return $trend;
    }
}
